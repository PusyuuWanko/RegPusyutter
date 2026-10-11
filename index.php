<?php
/**
 * =====================================================================
 * RegPusyutter - サービスの住所録(台帳)API
 *
 * 【これは何か】
 * 1つのサービス(例: イサミーズサービス、プシューサービス)に属するプロダクトの
 * 住所(入口ごとの URL)・一覧に出すかどうか・公開してよい設定・プロダクト宛ての機密を、
 * 1か所で配る API です。各プロダクトは自分以外の住所をここから受け取り、
 * ドメインやパスをプロダクトの中に書かずに済みます。
 * 読む側の部品は pusyuu_registry_client(PusyuuRegistryClient)です。
 *
 * 【1つの RegPusyutter は1つのサービスの台帳です】
 * サービスが2つあれば、この index.php を2か所に置き、それぞれ別の非公開フォルダを指します。
 * どのサービスの台帳かは、台帳の中の service 欄({"id","name"})に持ち、?api=registry と
 * ?api=futures の応答にも出します(管理操作 admin_service_put で設定します)。
 *
 * 【ゼロトラストの約束(呼ぶ側への約束)】
 * 呼ぶ側は「このAPIには繋がらないことがある」前提で作ります。ここは
 * 「取りに行く場所」であって、呼ぶ側は手元の控えで動き、繋がった時だけ
 * 控えを更新します。だからこのAPIは、応答が遅いより「素早く失敗する」方を選びます。
 * 重い処理・外部への通信をこのファイルに足さないでください。
 *
 * 【逆方向のゼロトラスト(このAPIが呼ぶ側を信用しない)】
 * 公開してよい情報(プロダクトの一覧と台帳)は誰にでも返します。
 * 機密と管理操作は、Ed25519の署名で本人確認できた相手にだけ応じます。
 * サーバが持つのは公開鍵だけなので、このサーバの中身が漏れても、
 * それだけで管理操作を行える人は生まれません。
 * 機密の応答は、さらに相手の鍵でしか開けない封筒(sealed box)に入れて返します。
 *
 * 【名前を変えても、通信の約束は変えていません】
 * 前の名前(pusyuu_registry)のときに決めた、署名する文字列の先頭の印(pusyuu_registry/1)と
 * 見出しの名前(X-Pusyuu-*)はそのままです。変えると、配った読む係と管理画面の署名が全部通らなくなります。
 *
 * 【動作環境の基準】
 * aptでPHPだけを入れた既定の構成(php.ini-production)で動くことを基準にしています。
 * その構成には mbstring と curl が入っていません。mb_ 系の関数・curl をここで
 * 使わないでください(入れた瞬間、既定構成のサーバで真っ白になります)。
 * sodium と openssl は既定で入っています。書き方は PHP 8.0 の範囲に収めています。
 *
 * 呼び方の詳細は同じフォルダの SPEC.md、管理者の鍵の登録は ADMIN_KEY_GUIDE.md を参照してください。
 * =====================================================================
 */

declare(strict_types=1);
// =====================================================================
// 非公開フォルダの指定(CODING_STYLE 5-3)
//
// 【このプロダクトを別の場所・別のサーバへ置いたら、ここだけ書き換えてください】
// このファイルのあるフォルダからの素の相対パスです。Webで公開されない場所を指してください。
// 使う前に下の PusyuuHiddenDir::verify() が「フォルダがあって読めるか」と「公開されていないか」を
// 確かめます。確かめられなければ、そのフォルダを使う機能が止まります(公開フォルダや
// 一時フォルダへ代わりに書くことはありません)。
// =====================================================================
// 例は「公開フォルダ(この index.php のあるフォルダ)の1つ上に .regpusyutter_private を置く」形です。
// 【名前は . で始めること】一覧に出にくくし、うっかり公開フォルダへ混ぜたときも多くのサーバ設定で隠れるようにするためです
// (守りの本体は下の PusyuuHiddenDir::verify() の確かめで、. はもう一重の目印です)。
// 2つのサービスの台帳を同じサーバに置くときは、それぞれ別のフォルダを指してください(同じだと台帳が混ざります)。
const REGPUSYUTTER_HIDDEN_PATH = '../.regpusyutter_private'; // この台帳だけが使う(台帳の本体・受け取り箱)

// =====================================================================
// 時刻は日本時間で扱います。
//
// 【初期化より前に置くこと】PHP の既定のタイムゾーンは、素のままだと UTC です(CODING_STYLE 12-5)。
// 指定しないと、画面に出す時刻・保存する日時の文字列・「今日」の区切りが9時間ずれます。
// このプロダクトは日本の利用者向けなので、ここで日本時間に決めます。
// =====================================================================
date_default_timezone_set('Asia/Tokyo');

// =====================================================================
// PusyuuHiddenDir - 非公開フォルダの確かめ役(CODING_STYLE 5-3)
//
// 【全プロダクトに同じ物をコピーして配っています。プロダクト固有の事情を入れないこと】
// 直すときは1つ直して、他のプロダクトへそのまま貼り直してください。
//
// 入口の定数に書いた非公開フォルダのパスを、使う前に1回だけ確かめます。
//   1. フォルダとして存在し、読めること(ファイルではなくフォルダで判断します)
//   2. サーバが公開している範囲の内側でないこと
//   3. 逆に、公開範囲が非公開フォルダの中に入っていないこと(上の階層を指す指定ミス)
//   4. 公開範囲の手がかりが1つも取れなければ使わない(非公開だと確かめられないため)
// 確かめられたら実際の場所(realpath の絶対パス)、確かめられなければ null を返し、
// 理由を error_log に残します。
//
// 【公開範囲は、取れた手がかりを全部使うこと】特定のHTTPサーバの機能に頼らないためです。
//   DOCUMENT_ROOT         … PHPを動かすサーバが渡す値(CGI / FastCGI の慣習)
//   CONTEXT_DOCUMENT_ROOT … Apache だけが渡す値。無ければ使わないだけ
//   SCRIPT_FILENAME から SCRIPT_NAME を末尾で除いたもの … どのサーバでも必ずある値から求めた、
//                           このリクエストの実際の公開元(nginx の alias などでも正しく出る)
// .htaccess や Require all denied は判断の根拠にしません(nginx などは読みません)。
//
// 【null のときに別の場所へ書かないこと】公開フォルダや一時フォルダへ代わりに書くと、
// 鍵が公開される事故になります。呼び出し側は null を「使えない」として機能を止めてください。
// =====================================================================
if (!class_exists('PusyuuHiddenDir')) {
  final class PusyuuHiddenDir {
    public static function verify(string $path): ?string {
      $real = ($path === '') ? false : realpath($path);
      $published = self::publishedRoots();
      $result = null;

      if ($real === false || !is_dir($real)) {
        error_log('[PusyuuHiddenDir] 非公開フォルダが見つかりません: ' . $path);
      } else if (!is_readable($real)) {
        error_log('[PusyuuHiddenDir] 非公開フォルダを読めません: ' . $path . ' (' . $real . ')');
      } else if ($published === []) {
        error_log('[PusyuuHiddenDir] サーバが公開している範囲が分からないため、非公開だと確かめられません。使いません: ' . $real);
      } else if (self::overlaps($real, $published)) {
        error_log('[PusyuuHiddenDir] 非公開フォルダが、Webで公開されている範囲と重なっています。使いません: ' . $real
          . ' / 公開範囲=' . implode(', ', $published));
      } else {
        $result = $real;
      }

      return $result;
    }

    /** 今のリクエストでサーバが公開している場所(実在するものだけ、realpath済み)。 */
    private static function publishedRoots(): array {
      $candidates = [
        (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
        (string)($_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? ''),
        self::rootFromScript(),
      ];
      $roots = [];

      foreach ($candidates as $candidate) {
        $real = ($candidate === '') ? false : realpath($candidate);
        if ($real !== false && is_dir($real) && !in_array($real, $roots, true)) {
          $roots[] = $real;
        }
      }

      return $roots;
    }

    /**
     * SCRIPT_FILENAME(実ファイル)の末尾から SCRIPT_NAME(URL上のパス)を取り除いた場所。
     * 末尾が一致しない(書き換えで別のファイルが動いている等)ときは空文字。
     */
    private static function rootFromScript(): string {
      $file = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
      $name = (string)($_SERVER['SCRIPT_NAME'] ?? '');
      $tail = substr($file, -strlen($name));
      $root = '';

      if ($file !== '' && $name !== '' && $name[0] === '/' && $tail === $name) {
        $root = substr($file, 0, strlen($file) - strlen($name));
        $root = ($root === '') ? '/' : $root;
      }

      return $root;
    }

    /** 片方がもう片方の中(同じ場所を含む)にあるか。 */
    private static function overlaps(string $dir, array $roots): bool {
      $target = rtrim(str_replace('\\', '/', $dir), '/') . '/';
      $result = false;

      foreach ($roots as $root) {
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (strncmp($target, $prefix, strlen($prefix)) === 0 || strncmp($prefix, $target, strlen($target)) === 0) {
          $result = true;
        }
      }

      return $result;
    }
  }
}

// 確かめた結果(実際の場所の絶対パス、確かめられなければ null)。共有スクリプトへはこの定数で渡します。
// 【共有スクリプトを読み込むより前に置くこと】読み込んだ時点で定数を見るものがあります。
define('REGPUSYUTTER_HIDDEN_DIR', PusyuuHiddenDir::verify(REGPUSYUTTER_HIDDEN_PATH));


// =====================================================================
// ▼▼▼ 設定エリア ▼▼▼
// =====================================================================

// 台帳・機密・鍵を置く「公開外」のディレクトリ。
//
// 置き場は、このファイルの先頭で確かめた台帳API専用の非公開フォルダ(REGPUSYUTTER_HIDDEN_DIR。
// 指定は REGPUSYUTTER_HIDDEN_PATH の1か所だけ)の下の data/ です(CODING_STYLE 5-3)。
// 確かめられなければ空文字になり、全部の応答が 503 になります(推測で別の場所を使うより、
// 止まって気付かせる方を選びます)。
//
// 【サーバを移すときは REGPUSYUTTER_HIDDEN_PATH を書き換える】台帳の住所は呼ぶ側が持っているので
// 変えないのが基本ですが、この非公開フォルダの中身は別のサーバへ移ることがあります。
// 中身(registry.json / secrets.json / clients/)はフォルダごと持っていけば動きます。
//
// 【絶対パスや探索に戻さないこと】以前は /var/www/page/... の決め打ちでした。サーバの構成に
// 縛られ、置き場所が変わったときに確かめないまま外れます(規約 5-2)。
//
// 【公開フォルダの中を指すと、はっきり止まります】PusyuuHiddenDir::verify() が断るうえに、
// 下の RegPusyutterStore::insideDocumentRoot() でもう一度確かめます。
define('REGPUSYUTTER_PRIVATE_DIR', (REGPUSYUTTER_HIDDEN_DIR === null) ? '' : REGPUSYUTTER_HIDDEN_DIR . '/data');

// 管理者の公開鍵の「受け取り箱」(ファイル1つ。台帳API専用の非公開フォルダの直下)。
//
// 【なぜ箱が要るか】上の公開外ディレクトリはサーバのPHPが所有者だけ読み書きできる
// 形で作るため、samba などサーバのPHP以外からは中へファイルを置けません
// (2026-09-20 に実際に書き込めないことを確認)。そこで、人が置ける場所に
// 公開鍵を書いたファイルを置いてもらい、次の要求のときにこのAPIが取り込みます。
// 取り込んだら箱は消し、結果を同じ場所の「.result.txt」に書きます。
//
// 【これは新しい抜け道ではありません】この箱に書ける人は、そもそもサーバの
// ファイルを書き換えられる人です(このファイル自体も書き換えられます)。
// 信用の根はもともとそこにあり、箱はその人に手段を1つ用意しただけです。
// 公開フォルダの内側は指さないでください(誰でも管理者を足せるようになります。
// 内側を指していると判定したら取り込みません)。
define('REGPUSYUTTER_ADMIN_INBOX_FILE', (REGPUSYUTTER_HIDDEN_DIR === null) ? '' : REGPUSYUTTER_HIDDEN_DIR . '/regpusyutter_admin.json');

// 署名の時刻のずれを許す幅(秒)。この幅の外の署名は、正しくても受け付けません。
// 使い済みの合図(nonce)を覚えておく期間もこの幅から決まるため、広げると
// 覚えておくファイルが増えます。
const REGISTRY_CLOCK_SKEW_SEC = 300;

// 受け付ける本文の上限(バイト)。台帳はテキストだけなので、これで十分足ります。
const REGISTRY_MAX_BODY_BYTES = 262144;

// 署名する文字列の先頭に付ける印(前の名前 pusyuu_registry のときに決めたもの)。
// 【名前を変えても、これは変えないこと】変えると、既に配った全ての管理クライアントの署名が通らなくなります。
// 形式を変える時は、新しい印(/2)を足して両方を受け付ける期間を設けてください。
const REGISTRY_SIGN_CONTEXT = 'pusyuu_registry/1';

// 使い済みの合図を掃除する頻度(この回数に1回)。毎回だと遅く、しないと溜まり続けます。
const REGISTRY_NONCE_SWEEP_ODDS = 64;

// クローラー(BOT)として断る User-Agent。署名の無い要求にだけ当てます。
//
// 【curl / wget / python などは入れないこと】外から管理操作をする道具や、各プロダクトの
// サーバ間呼び出しがそれらを名乗ることがあります。ここに入れると、正規の呼び出しが
// 403 になり、呼ぶ側は「台帳が読めない」として機能を止めます。
// 【.htaccess では断らない方針です】サーバの設定次第で効いたり効かなかったりするので、
// 断る処理はこのファイルの中だけに置いています。
const REGISTRY_BOT_UA_PATTERN = '/bot|crawl|spider|slurp|archiver|scrap|facebookexternalhit|embedly|preview|headless/i';

// =====================================================================
// ▲▲▲ 設定エリアここまで ▲▲▲
// =====================================================================

/**
 * 保存先の係。公開外ディレクトリの特定と、ファイルの読み書きだけを受け持ちます。
 *
 * 【このクラス以外でファイルを直接触らないこと】保存先の判定(公開フォルダの内側を
 * 拒む等)はここにしか無いので、別の場所で file_put_contents を書くと、その経路だけ
 * 守りが外れます。
 */
final class RegPusyutterStore {
  /** 公開外ディレクトリ。使えないときは null(理由は error_log に残します)。 */
  public static function privateDir(): ?string {
    static $resolved = false;
    static $dir = null;

    // 2回目以降は $resolved が true なので、この塊ごと素通りして答えを使い回します。
    if ($resolved === false) {
      $resolved = true;
      $base = (string)REGPUSYUTTER_PRIVATE_DIR;

      if ($base === '') {
        error_log('[RegPusyutter] 公開外ディレクトリが決まりません。REGPUSYUTTER_PRIVATE_DIR を設定してください。');
        $dir = null;
      } else if (self::insideDocumentRoot($base)) {
        // 【作るより先に判定する】先に mkdir すると、断る場合でも公開フォルダの中に
        // 空のフォルダが残り、次に誰かがそこへ手で鍵を置く呼び水になります。
        error_log('[RegPusyutter] 公開外ディレクトリが公開フォルダの内側を指しています。使いません: ' . $base);
        $dir = null;
      } else if (!self::ensureDir($base) || !self::ensureDir($base . '/clients') || !self::ensureDir($base . '/nonces')) {
        // 中の2つも、ここで必ず揃えます。無いまま進むと、鍵の一覧が空に見えたり
        // 合図を記録できずに全部の署名が「再送」と判定されたりと、原因の分かりにくい
        // 壊れ方をします。揃えられないなら、ここで止めて 503 にします。
        error_log('[RegPusyutter] 公開外ディレクトリ(または clients / nonces)を作れません: ' . $base);
        $dir = null;
      } else {
        $real = realpath($base);
        $dir = ($real === false) ? $base : $real;
      }
    }

    return $dir;
  }

  /** 無ければ作ります。mkdir が失敗しても、他の要求が同時に作っていれば is_dir は真になります。 */
  public static function ensureDir(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir);
  }

  /**
   * DOCUMENT_ROOT の内側かどうか。DOCUMENT_ROOT はサーバが決める値で、
   * 要求の側からは偽れません(HTTP_HOST とは性質が違います)。
   * CLIなど DOCUMENT_ROOT が無い場面では判定できないので「内側ではない」とします。
   *
   * まだ存在しないディレクトリも判定できるよう、実在する一番近い祖先で比べます。
   * 祖先が公開フォルダの内側なら、その下に作る物も必ず内側です。
   */
  public static function insideDocumentRoot(string $dir): bool {
    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $realDoc = ($docRoot === '') ? false : realpath($docRoot);
    $probe = $dir;
    while (!file_exists($probe) && dirname($probe) !== $probe) {
      $probe = dirname($probe);
    }
    $realDir = realpath($probe);

    if ($realDoc === false || $realDir === false) {
      $inside = false;
    } else {
      $docPrefix = rtrim(str_replace('\\', '/', $realDoc), '/') . '/';
      $dirPath   = rtrim(str_replace('\\', '/', $realDir), '/') . '/';
      $inside = (strncmp($dirPath, $docPrefix, strlen($docPrefix)) === 0);
    }

    return $inside;
  }

  public static function path(string $name): string {
    return self::privateDir() . '/' . $name;
  }

  /** JSONファイルを配列で読みます。無い・壊れているときは $default を返します。 */
  public static function readJson(string $file, array $default): array {
    $raw = is_file($file) ? @file_get_contents($file) : false;
    $decoded = ($raw === false) ? null : json_decode($raw, true);

    if (is_array($decoded)) {
      $data = $decoded;
    } else {
      if ($raw !== false) {
        // 壊れたファイルを黙って空扱いにすると、次の保存で中身が消えます。
        // 書き込みの前に止められるよう、記録を残します。
        error_log('[RegPusyutter] JSONとして読めません: ' . $file);
      }
      $data = $default;
    }

    return $data;
  }

  /** 生のバイト列(ETag を作るため)。無ければ空文字。 */
  public static function readRaw(string $file): string {
    $raw = is_file($file) ? @file_get_contents($file) : false;
    return ($raw === false) ? '' : $raw;
  }

  /** 別名で書いてから rename します。途中で落ちても、半分だけ書かれたファイルを読む人がいません。 */
  public static function writeJson(string $file, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $dir = dirname($file);

    if ($json === false) {
      $ok = false;
    } else if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
      $ok = false;
    } else {
      $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';

      if (@file_put_contents($tmp, $json . "\n") === false) {
        $ok = false;
      } else if (!@rename($tmp, $file)) {
        @unlink($tmp);
        $ok = false;
      } else {
        @chmod($file, 0600);
        $ok = true;
      }
    }

    if ($ok === false) {
      error_log('[RegPusyutter] 書き込みに失敗しました: ' . $file);
    }

    return $ok;
  }

  /**
   * 読んで・直して・書く、の一続きを排他で行います。
   *
   * 【管理操作は必ずこの中で】2つの管理操作が同時に届いたとき、ロック無しだと
   * 後から書いた方が先の変更を丸ごと消します。台帳・機密・鍵はどれも
   * この1本のロックで守っています(ファイルごとに分けると、鍵の削除と
   * 「管理者が0人にならないか」の確認の間に割り込まれます)。
   * ロックを握ったまま外部と通信しないでください。
   */
  public static function withLock(callable $work) {
    $fp = @fopen(self::path('registry.lock'), 'c');

    if ($fp === false) {
      $result = null;
    } else if (!flock($fp, LOCK_EX)) {
      fclose($fp);
      $result = null;
    } else {
      $result = $work();
      flock($fp, LOCK_UN);
      fclose($fp);
    }

    return $result;
  }
}

/**
 * 台帳の形の係。受け取った値を確かめ、決まった形に揃えます。
 *
 * 台帳の1件(プロダクト)の形:
 *   id          … 英小文字・数字・_・- 。プロダクトが自分を名乗る名前で、ドメインとは無関係
 *   title       … 表示名
 *   description … 説明
 *   image       … 空 / 絶対URL / 「/」で始まるパス(そのプロダクトの顔からの位置)
 *   listed      … 一覧に出すか(メイキィのように、住所は要るが一覧に出さないものは false)
 *   faces       … 入口ごとの住所。{ "public": "https://...", "lan": "http://192.168.../p" }
 *                 public は必須。どの入口にも当てはまらない時は public に倒す約束です
 *   internal    … サーバ間の呼び出しで実際に繋ぐ先(空なら public へそのまま繋ぐ)。
 *                 例: "https://127.0.0.1"。名乗るホスト名(Hostヘッダ・証明書の確認)は
 *                 faces.public のホストを使います。
 *                 【なぜ faces に入れないか】faces は「ブラウザが来る入口」で、今の要求と
 *                 照らし合わせて選ばれます。ここに 127.0.0.1 を混ぜると、ブラウザへ渡す
 *                 リンクに選ばれる事故が起きます。サーバの内側の経路は別の欄に分けています。
 *   settings    … HTTPの住所ではない、そのプロダクトの公開してよい設定値。{ "名前": "値" }
 *                 例: p-reversi の stun_urls(STUNサーバーは stun: で始まる別の種類の住所のため)。
 *                 【機密を入れないこと】台帳は誰でも読めます。機密は secrets へ。
 */
final class RegPusyutterSchema {
  public const ID_PATTERN          = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
  public const FACE_PATTERN        = '/^[a-z0-9][a-z0-9_-]{0,31}$/';
  public const SECRET_NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';
  public const ROLE_ADMIN   = 'admin';
  public const ROLE_PRODUCT = 'product';

  /** service 欄の id の形(プロダクトの id と同じ)。name は表示用の文字列です。 */
  public const SERVICE_NAME_MAX_BYTES = 200;

  public static function emptyRegistry(): array {
    return ['version' => 1, 'updated_at' => '', 'service' => self::emptyService(), 'products' => [], 'terms' => []];
  }

  /** まだ設定されていない service 欄。 */
  public static function emptyService(): array {
    return ['id' => '', 'name' => ''];
  }

  /**
   * service 欄として受け取れる形に揃えます。受け取れなければ null。
   * 【id を空にできるのは「まだ決めていない」ときだけ】設定するときは id・name の両方を求めます。
   */
  public static function normalizeService($service): ?array {
    $id = is_array($service) ? ($service['id'] ?? null) : null;
    $name = is_array($service) ? ($service['name'] ?? null) : null;
    $ok = is_string($id) && preg_match(self::ID_PATTERN, $id) === 1
      && self::isText($name, self::SERVICE_NAME_MAX_BYTES) && trim((string)$name) !== '';

    return $ok ? ['id' => $id, 'name' => trim((string)$name)] : null;
  }

  /** 台帳ファイルを読み、欠けているキーを補った形で返します。 */
  public static function load(): array {
    $data = RegPusyutterStore::readJson(RegPusyutterStore::path('registry.json'), self::emptyRegistry());

    // 【service 欄が無い・壊れている台帳でも読めること】前の版(pusyuu_registry)の台帳には service 欄がありません。
    // そのときは空の欄として返します(呼ぶ側は id が空なら「まだ決まっていない」と分かります)。
    $service = self::normalizeService($data['service'] ?? null) ?? self::emptyService();

    return [
      'version'    => 1,
      'updated_at' => (string)($data['updated_at'] ?? ''),
      'service'    => $service,
      'products'   => is_array($data['products'] ?? null) ? array_values($data['products']) : [],
      'terms'      => is_array($data['terms'] ?? null) ? array_values($data['terms']) : [],
    ];
  }

  public static function save(array $registry): bool {
    $registry['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    return RegPusyutterStore::writeJson(RegPusyutterStore::path('registry.json'), $registry);
  }

  public static function indexOf(array $registry, string $id): int {
    $found = -1;
    foreach ($registry['products'] as $i => $product) {
      if (($product['id'] ?? '') === $id) {
        $found = $i;
        break;
      }
    }
    return $found;
  }

  /** 文字列として受け取れるか(UTF-8として正しく、長さが上限以内)。 */
  public static function isText($value, int $maxBytes): bool {
    return is_string($value) && strlen($value) <= $maxBytes && preg_match('//u', $value) === 1;
  }

  /**
   * 入口の住所として使えるURLか。スキーム・ホストがあり、問い合わせ部分や
   * 利用者名を含まないもの。パスは持ってよい(パスでプロダクトを分ける構成のため)。
   */
  public static function isBaseUrl($value): bool {
    $parts = is_string($value) ? parse_url($value) : false;

    if (!is_array($parts)) {
      $ok = false;
    } else if (!in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
      $ok = false;
    } else if ((string)($parts['host'] ?? '') === '') {
      $ok = false;
    } else if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
      $ok = false;
    } else {
      $ok = preg_match('/[\s\x00-\x1f\x7f]/', $value) !== 1;
    }

    return $ok;
  }

  /**
   * 画像の指定として使えるか。空、絶対URL、「/」で始まる顔からのパス、のどれか。
   * 「//」で始まるもの(スキーム省略の絶対URL)は、顔からのパスと見分けが付かないので拒みます。
   */
  public static function isImageRef($value): bool {
    if (!self::isText($value, 2048)) {
      $ok = false;
    } else if ($value === '') {
      $ok = true;
    } else if (preg_match('/[\s\x00-\x1f\x7f]/', $value) === 1) {
      $ok = false;
    } else if (strncmp($value, '//', 2) === 0) {
      $ok = false;
    } else if ($value[0] === '/') {
      $ok = true;
    } else {
      $parts = parse_url($value);
      $ok = is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && (string)($parts['host'] ?? '') !== '';
    }

    return $ok;
  }

  /**
   * 受け取ったプロダクト1件を確かめて揃えます。
   * 戻り値: ['ok' => bool, 'product' => array|null, 'message' => string]
   */
  public static function normalizeProduct($in): array {
    $settings = (is_array($in) && is_array($in['settings'] ?? null)) ? $in['settings'] : [];
    $badSetting = (is_array($in) && array_key_exists('settings', $in) && !is_array($in['settings']));
    foreach ($settings as $name => $value) {
      if (!is_string($name) || preg_match(self::SECRET_NAME_PATTERN, $name) !== 1 || !self::isText($value, 4000)) {
        $badSetting = true;
      }
    }
    $faces = (is_array($in) && is_array($in['faces'] ?? null)) ? $in['faces'] : null;
    $badFace = '';
    if ($faces !== null) {
      foreach ($faces as $name => $url) {
        if (!is_string($name) || preg_match(self::FACE_PATTERN, $name) !== 1 || !self::isBaseUrl($url)) {
          $badFace = is_string($name) ? $name : '(名前が文字列ではない)';
          break;
        }
      }
    }

    if (!is_array($in)) {
      $result = ['ok' => false, 'product' => null, 'message' => 'product はオブジェクトで指定してください。'];
    } else if (!is_string($in['id'] ?? null) || preg_match(self::ID_PATTERN, $in['id']) !== 1) {
      $result = ['ok' => false, 'product' => null, 'message' => 'id は英小文字・数字・_・- の64文字以内で指定してください。'];
    } else if (!self::isText($in['title'] ?? null, 1000) || trim($in['title']) === '') {
      $result = ['ok' => false, 'product' => null, 'message' => 'title は必須です。'];
    } else if (array_key_exists('description', $in) && !self::isText($in['description'], 20000)) {
      $result = ['ok' => false, 'product' => null, 'message' => 'description は文字列で指定してください。'];
    } else if (array_key_exists('image', $in) && !self::isImageRef($in['image'])) {
      $result = ['ok' => false, 'product' => null, 'message' => 'image は空・絶対URL・「/」で始まるパスのどれかで指定してください。'];
    } else if (array_key_exists('internal', $in) && $in['internal'] !== '' && !self::isBaseUrl($in['internal'])) {
      $result = ['ok' => false, 'product' => null, 'message' => 'internal は空か、http(s) のURLで指定してください(例: https://127.0.0.1)。'];
    } else if ($badSetting) {
      $result = ['ok' => false, 'product' => null, 'message' => 'settings は「英数字・_・.・- の名前: 文字列の値」の組で指定してください。'];
    } else if (array_key_exists('listed', $in) && !is_bool($in['listed'])) {
      $result = ['ok' => false, 'product' => null, 'message' => 'listed は true か false で指定してください。'];
    } else if ($faces === null || !isset($faces['public'])) {
      $result = ['ok' => false, 'product' => null, 'message' => 'faces に public の住所は必須です(どの入口にも当てはまらない時の倒し先です)。'];
    } else if ($badFace !== '') {
      $result = ['ok' => false, 'product' => null, 'message' => "faces の「{$badFace}」が正しくありません。名前は英小文字・数字・_・-、値は http(s) のURLです。"];
    } else {
      $cleanFaces = [];
      foreach ($faces as $name => $url) {
        $cleanFaces[$name] = rtrim($url, '/');
      }

      $result = [
        'ok' => true,
        'product' => [
          'id'          => $in['id'],
          'title'       => trim($in['title']),
          'description' => (string)($in['description'] ?? ''),
          'image'       => (string)($in['image'] ?? ''),
          'listed'      => array_key_exists('listed', $in) ? $in['listed'] : true,
          'faces'       => $cleanFaces,
          'internal'    => rtrim((string)($in['internal'] ?? ''), '/'),
          'settings'    => $settings,
        ],
        'message' => '',
      ];
    }

    return $result;
  }

  /**
   * 旧 futures.json の1件の形へ変換します(公開の顔だけで表した形)。
   * 【旧形式の読み手のためだけの変換】新しい読み手は faces を使い、今の入口に
   * 合った顔を自分で選んでください。ここで public に決め打ちしているのは、
   * 旧形式に「入口」という考え方が無いからです。
   */
  public static function toLegacyLink(array $product): array {
    $base = (string)($product['faces']['public'] ?? '');
    $image = (string)($product['image'] ?? '');

    if ($image !== '' && $image[0] === '/') {
      $imageUrl = $base . $image;
    } else {
      $imageUrl = $image;
    }

    return [
      'id'          => (string)($product['id'] ?? ''),
      'title'       => (string)($product['title'] ?? ''),
      'description' => (string)($product['description'] ?? ''),
      'url'         => $base,
      'image'       => $imageUrl,
    ];
  }

  /** terms は旧 futures.json から引き継いだ欄。中身は「文字列だけを持つオブジェクト」の並びに限ります。 */
  public static function isTerms($terms): bool {
    $ok = is_array($terms) && array_values($terms) === $terms;
    if ($ok) {
      foreach ($terms as $term) {
        if (!is_array($term)) {
          $ok = false;
          break;
        }
        foreach ($term as $k => $v) {
          if (!is_string($k) || !self::isText($v, 4000)) {
            $ok = false;
            break 2;
          }
        }
      }
    }
    return $ok;
  }
}

/**
 * 本人確認の係。署名の検証と、使い済みの合図の記録、機密の封筒づくりを受け持ちます。
 *
 * 【署名するもの】
 *   REGISTRY_SIGN_CONTEXT \n メソッド \n 問い合わせ文字列(?より後ろ、そのまま) \n
 *   時刻(UNIX秒) \n 合図(nonce) \n 本文のSHA-256(16進)
 *
 * 【ホスト名とパスを署名に入れていない理由】中継や移設でパスの見え方
 * (末尾の / や index.php の有無)が変わり得て、そこで署名が壊れるためです。
 * 【その代わり、台帳ごとに別の鍵を登録すること】署名に住所が入っていないので、ある台帳あての署名付きの
 * 要求は、同じ公開鍵を登録している別の台帳でも(時刻の幅の中なら)通ります。使い済みの合図は台帳ごとに
 * 覚えるので、別の台帳への使い回しは弾けません。1つの管理者が複数の台帳を触るときは、台帳ごとに鍵を分けてください。
 */
final class RegPusyutterAuth {
  /**
   * 要求の署名を確かめます。
   * 戻り値: ['ok' => bool, 'client' => array|null, 'status' => int, 'reason' => string]
   * reason はログ用です。呼び出し側へは「認証できません」だけを返してください
   * (どこで落ちたかを教えると、鍵を持たない人に手掛かりを与えます)。
   */
  public static function verify(string $body): array {
    $clientId  = (string)($_SERVER['HTTP_X_PUSYUU_CLIENT'] ?? '');
    $timestamp = (string)($_SERVER['HTTP_X_PUSYUU_TIMESTAMP'] ?? '');
    $nonce     = (string)($_SERVER['HTTP_X_PUSYUU_NONCE'] ?? '');
    $signature = self::base64UrlDecode((string)($_SERVER['HTTP_X_PUSYUU_SIGNATURE'] ?? ''));

    $wellFormed = preg_match(RegPusyutterSchema::ID_PATTERN, $clientId) === 1
      && preg_match('/^[0-9]{1,12}$/', $timestamp) === 1
      && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) === 1
      && $signature !== null && strlen($signature) === 64;
    $client = $wellFormed ? self::loadClient($clientId) : null;

    if (!function_exists('sodium_crypto_sign_verify_detached')) {
      $result = ['ok' => false, 'client' => null, 'status' => 500, 'reason' => 'sodium が使えません'];
    } else if (!$wellFormed) {
      $result = ['ok' => false, 'client' => null, 'status' => 401, 'reason' => '署名の見出しが足りない・形が違う'];
    } else if ($client === null) {
      $result = ['ok' => false, 'client' => null, 'status' => 401, 'reason' => "登録の無いクライアント: {$clientId}"];
    } else if (abs(time() - (int)$timestamp) > REGISTRY_CLOCK_SKEW_SEC) {
      $result = ['ok' => false, 'client' => null, 'status' => 401, 'reason' => "時刻のずれが大きすぎる: {$clientId}"];
    } else if (!sodium_crypto_sign_verify_detached($signature, self::signedMessage($timestamp, $nonce, $body), base64_decode($client['public_key'], true))) {
      $result = ['ok' => false, 'client' => null, 'status' => 401, 'reason' => "署名が一致しない: {$clientId}"];
    } else if (!self::consumeNonce($clientId, $nonce)) {
      // 【合図の記録は署名を確かめた後】先に記録すると、鍵を持たない人でも
      // でたらめな合図を送り付けるだけで記録のファイルを増やせてしまいます。
      $result = ['ok' => false, 'client' => null, 'status' => 401, 'reason' => "使い済みの合図(再送の疑い): {$clientId}"];
    } else {
      $result = ['ok' => true, 'client' => $client, 'status' => 200, 'reason' => ''];
    }

    return $result;
  }

  public static function signedMessage(string $timestamp, string $nonce, string $body): string {
    return implode("\n", [
      REGISTRY_SIGN_CONTEXT,
      strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
      (string)($_SERVER['QUERY_STRING'] ?? ''),
      $timestamp,
      $nonce,
      hash('sha256', $body),
    ]);
  }

  /** クライアント1件を読みます。形が崩れているものは「登録が無い」と同じ扱いです。 */
  public static function loadClient(string $id): ?array {
    $file = RegPusyutterStore::path('clients/' . $id . '.json');
    $data = is_file($file) ? RegPusyutterStore::readJson($file, []) : [];
    $key = base64_decode((string)($data['public_key'] ?? ''), true);
    $role = (string)($data['role'] ?? '');

    if (($data['id'] ?? '') !== $id) {
      $client = null;
    } else if ($key === false || strlen($key) !== 32) {
      $client = null;
    } else if ($role === RegPusyutterSchema::ROLE_ADMIN) {
      $client = $data;
    } else if ($role === RegPusyutterSchema::ROLE_PRODUCT && preg_match(RegPusyutterSchema::ID_PATTERN, (string)($data['product'] ?? '')) === 1) {
      $client = $data;
    } else {
      $client = null;
    }

    return $client;
  }

  /** 登録されている全クライアント(公開鍵だけなので、管理者に見せてよい情報です)。 */
  public static function listClients(): array {
    $files = glob(RegPusyutterStore::path('clients/*.json')) ?: [];
    $clients = [];
    foreach ($files as $file) {
      $client = self::loadClient(basename($file, '.json'));
      if ($client !== null) {
        $clients[] = $client;
      }
    }
    return $clients;
  }

  /**
   * 受け取り箱(REGPUSYUTTER_ADMIN_INBOX_FILE)に置かれた公開鍵を、管理者として取り込みます。
   * 置かれていなければ何もしません(毎回の要求で is_file を1回見るだけです)。
   *
   * 箱の中身: {"id": "名前", "public_key": "公開鍵(base64)"}
   * 同じ id が既にあれば、公開鍵を置き換えます(鍵をなくした時の作り直しに使えます)。
   *
   * 結果は箱の隣の「.result.txt」に日本語で書きます。サーバのログを読めない人でも、
   * 同じフォルダを開けば成功したかどうかが分かるようにするためです。
   * 取り込めなかった箱は消さずに残します(直して置き直せるように)。
   */
  public static function importAdminInbox(): void {
    $inbox = (string)REGPUSYUTTER_ADMIN_INBOX_FILE;
    $present = ($inbox !== '' && is_file($inbox));
    $raw = $present ? (string)@file_get_contents($inbox) : '';
    // メモ帳などで保存すると先頭に BOM が付くことがあり、そのままでは JSON として読めません。
    // また、貼り付けた鍵の途中で改行されていると、引用符の中の改行は JSON では不正です。
    // 改行を空白に置き換えてから読み、鍵の中の空白は下で取り除きます。
    $json = str_replace(["\r", "\n"], ' ', (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) ? substr($raw, 3) : $raw);
    $data = $present ? json_decode($json, true) : null;
    $id = is_array($data) ? trim((string)($data['id'] ?? '')) : '';
    $keyText = is_array($data) ? preg_replace('/\s+/', '', (string)($data['public_key'] ?? '')) : '';
    $key = ($keyText === '') ? null : self::base64UrlDecode(strtr($keyText, '+/', '-_'));

    if (!$present) {
      $message = null;
    } else if (RegPusyutterStore::insideDocumentRoot($inbox)) {
      $message = null;
      error_log('[RegPusyutter] 受け取り箱が公開フォルダの内側にあるため取り込みません: ' . $inbox);
    } else if (!is_array($data)) {
      $message = "取り込めませんでした: 中身がJSONとして読めません。{\"id\": \"...\", \"public_key\": \"...\"} の形か確かめてください。";
    } else if (preg_match(RegPusyutterSchema::ID_PATTERN, $id) !== 1) {
      $message = "取り込めませんでした: id は英小文字・数字・_・- で書いてください(今の値: {$id})。";
    } else if ($key === null || strlen($key) !== 32) {
      $message = '取り込めませんでした: public_key が公開鍵の形ではありません。鍵づくりで表示された「public」の行を、そのまま貼ってください(secret ではありません)。';
    } else {
      $before = self::loadClient($id);
      $record = [
        'id'         => $id,
        'role'       => RegPusyutterSchema::ROLE_ADMIN,
        'product'    => null,
        'public_key' => base64_encode($key),
        'note'       => '受け取り箱から登録',
        'created_at' => ($before === null) ? gmdate('Y-m-d\TH:i:s\Z') : (string)($before['created_at'] ?? ''),
      ];

      if (!RegPusyutterStore::writeJson(RegPusyutterStore::path('clients/' . $id . '.json'), $record)) {
        $message = '取り込めませんでした: 保存先に書き込めません。サーバの権限を確かめてください。';
      } else if (!@unlink($inbox)) {
        // 消せないと、次の要求でまた取り込みます(同じ内容なので害はありませんが、
        // 管理者を消しても箱から復活します)。気付けるように結果に書きます。
        $message = "管理者「{$id}」を登録しました。ただし受け取り箱を消せませんでした。手で消してください。";
      } else {
        $message = ($before === null) ? "管理者「{$id}」を登録しました。" : "管理者「{$id}」の公開鍵を置き換えました。";
      }
      error_log('[RegPusyutter] 受け取り箱: ' . $message);
    }

    if ($message !== null) {
      @file_put_contents($inbox . '.result.txt', gmdate('Y-m-d H:i:s') . " (UTC)\n" . $message . "\n");
    }
  }

  /**
   * 合図(nonce)を使い済みにします。既に使われていれば false。
   * 'x' で開くと「無ければ作る」が不可分に行われるので、同時に届いた
   * 同じ合図のうち、通るのは必ず1つだけです。
   */
  private static function consumeNonce(string $clientId, string $nonce): bool {
    $dir = RegPusyutterStore::path('nonces');
    if (!is_dir($dir)) {
      @mkdir($dir, 0700, true);
    }

    if (random_int(1, REGISTRY_NONCE_SWEEP_ODDS) === 1) {
      // 時刻のずれの幅を2倍過ぎた合図は、もう署名の時刻の検査で弾かれるので覚えておく必要がありません。
      $limit = time() - REGISTRY_CLOCK_SKEW_SEC * 2;
      foreach (glob($dir . '/*.n') ?: [] as $old) {
        if (@filemtime($old) < $limit) {
          @unlink($old);
        }
      }
    }

    $fp = @fopen($dir . '/' . hash('sha256', $clientId . "\n" . $nonce) . '.n', 'x');
    if ($fp === false) {
      $fresh = false;
    } else {
      fclose($fp);
      $fresh = true;
    }

    return $fresh;
  }

  /**
   * 機密を、そのクライアントの鍵でしか開けない封筒に入れます。
   * Ed25519の公開鍵を X25519 に写して sealed box で閉じます。開け方は SPEC.md。
   *
   * 【なぜTLSに加えて封筒に入れるか】TLSは経路の途中しか守りません。中継(リバース
   * プロキシ)やサーバ側のログ、応答の控えに平文で残る所をなくすためです。
   */
  public static function seal(string $plain, array $client): ?string {
    $edPk = base64_decode((string)$client['public_key'], true);
    $box = ($edPk === false) ? false : sodium_crypto_box_seal($plain, sodium_crypto_sign_ed25519_pk_to_curve25519($edPk));
    return ($box === false) ? null : base64_encode($box);
  }

  /** base64url を読みます。末尾の = は有っても無くても構いません(付け直すので二重になりません)。 */
  public static function base64UrlDecode(string $s): ?string {
    $s = rtrim($s, '=');
    $decoded = ($s === '') ? false : base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return ($decoded === false) ? null : $decoded;
  }
}

// ---------------------------------------------------------------------
// 要求の処理
// ---------------------------------------------------------------------

/** 応答を1つの形にまとめます。出力は最後の1か所だけで行います。 */
function registryReply(int $status, ?array $body, array $headers = []): array {
  return ['status' => $status, 'body' => $body, 'headers' => $headers];
}

function registryFail(int $status, string $error, string $message): array {
  return registryReply($status, ['ok' => false, 'error' => $error, 'message' => $message]);
}

/**
 * 管理操作の本文(JSON)を読みます。
 * 戻り値: ['ok' => bool, 'data' => array, 'reply' => array|null]
 */
function registryJsonBody(string $body): array {
  $decoded = json_decode($body, true);

  if (!is_array($decoded)) {
    $result = ['ok' => false, 'data' => [], 'reply' => registryFail(400, 'bad_json', '本文はJSONのオブジェクトで送ってください。')];
  } else {
    $result = ['ok' => true, 'data' => $decoded, 'reply' => null];
  }

  return $result;
}

/** 公開の読み出しに付ける見出し。台帳の中身から作った ETag で、変わっていなければ 304 を返せます。 */
function registryPublicReply(array $body, string $etagSource): array {
  $etag = '"' . substr(hash('sha256', $etagSource), 0, 32) . '"';
  $headers = [
    'ETag' => $etag,
    'Cache-Control' => 'no-cache',
    'Access-Control-Allow-Origin' => '*',
  ];

  if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    $reply = registryReply(304, null, $headers);
  } else {
    $reply = registryReply(200, $body, $headers);
  }

  return $reply;
}

/** 管理者の数(クライアントの削除・役割変更で 0 人にしないため)。 */
function registryAdminCount(array $clients): int {
  $count = 0;
  foreach ($clients as $client) {
    if (($client['role'] ?? '') === RegPusyutterSchema::ROLE_ADMIN) {
      $count++;
    }
  }
  return $count;
}

/**
 * 管理操作。署名の確認と役割の確認は呼ぶ前に済んでいます。
 * どの操作も RegPusyutterStore::withLock の中で「読む→直す→書く」を行います。
 */
function registryAdmin(string $api, array $in, array $admin): array {
  $reply = RegPusyutterStore::withLock(function () use ($api, $in) {
    switch ($api) {
      case 'admin_product_put': {
        $checked = RegPusyutterSchema::normalizeProduct($in['product'] ?? null);
        $registry = RegPusyutterSchema::load();

        if (!$checked['ok']) {
          $reply = registryFail(400, 'invalid_product', $checked['message']);
        } else {
          $index = RegPusyutterSchema::indexOf($registry, $checked['product']['id']);
          // 既にあるものは同じ位置で差し替え、無いものは末尾へ。並び順は admin_product_order で変えます。
          if ($index >= 0) {
            $registry['products'][$index] = $checked['product'];
          } else {
            $registry['products'][] = $checked['product'];
          }
          $reply = RegPusyutterSchema::save($registry)
            ? registryReply(200, ['ok' => true, 'product' => $checked['product'], 'created' => $index < 0])
            : registryFail(500, 'write_failed', '台帳を保存できませんでした。');
        }
        break;
      }

      case 'admin_product_delete': {
        // 【機密と鍵は消しません】消し間違えたプロダクトを戻したとき、配った鍵と
        // 機密までやり直しになるのを避けるためです。要らなくなった物は
        // admin_secret_delete / admin_client_delete で個別に消してください。
        $id = (string)($in['id'] ?? '');
        $registry = RegPusyutterSchema::load();
        $index = RegPusyutterSchema::indexOf($registry, $id);

        if ($index < 0) {
          $reply = registryFail(404, 'not_found', "プロダクト「{$id}」は台帳にありません。");
        } else {
          array_splice($registry['products'], $index, 1);
          $reply = RegPusyutterSchema::save($registry)
            ? registryReply(200, ['ok' => true, 'deleted' => $id])
            : registryFail(500, 'write_failed', '台帳を保存できませんでした。');
        }
        break;
      }

      case 'admin_product_order': {
        // 並び順は「全IDの並び」で丸ごと受け取ります。上下に1つずつ動かす形だと、
        // 2人が同時に動かしたときに、どちらの意図とも違う順番になるためです。
        $ids = $in['ids'] ?? null;
        $registry = RegPusyutterSchema::load();
        $current = array_map(function ($p) { return (string)($p['id'] ?? ''); }, $registry['products']);
        $wanted = is_array($ids) ? array_values($ids) : [];
        $sortedCurrent = $current;
        $sortedWanted = $wanted;
        sort($sortedCurrent);
        sort($sortedWanted);

        if (!is_array($ids) || $sortedCurrent !== $sortedWanted) {
          $reply = registryFail(400, 'invalid_order', 'ids には台帳にある全てのIDを、重複なく1回ずつ並べてください。');
        } else {
          $byId = [];
          foreach ($registry['products'] as $product) {
            $byId[$product['id']] = $product;
          }
          $registry['products'] = [];
          foreach ($wanted as $id) {
            $registry['products'][] = $byId[$id];
          }
          $reply = RegPusyutterSchema::save($registry)
            ? registryReply(200, ['ok' => true, 'ids' => $wanted])
            : registryFail(500, 'write_failed', '台帳を保存できませんでした。');
        }
        break;
      }

      case 'admin_service_put': {
        // どのサービスの台帳か({"id","name"})。?api=registry と ?api=futures の応答に出ます。
        $service = RegPusyutterSchema::normalizeService($in['service'] ?? null);
        $registry = RegPusyutterSchema::load();

        if ($service === null) {
          $reply = registryFail(400, 'invalid_service', 'service は {"id": 英小文字・数字・_・- の64文字以内, "name": 空でない' . RegPusyutterSchema::SERVICE_NAME_MAX_BYTES . 'バイト以内の文字列} で指定してください。');
        } else {
          $registry['service'] = $service;
          $reply = RegPusyutterSchema::save($registry)
            ? registryReply(200, ['ok' => true, 'service' => $service])
            : registryFail(500, 'write_failed', '台帳を保存できませんでした。');
        }
        break;
      }

      case 'admin_terms_put': {
        $terms = $in['terms'] ?? null;
        $registry = RegPusyutterSchema::load();

        if (!RegPusyutterSchema::isTerms($terms)) {
          $reply = registryFail(400, 'invalid_terms', 'terms は「文字列だけを持つオブジェクト」の並びで指定してください。');
        } else {
          $registry['terms'] = $terms;
          $reply = RegPusyutterSchema::save($registry)
            ? registryReply(200, ['ok' => true, 'terms' => $terms])
            : registryFail(500, 'write_failed', '台帳を保存できませんでした。');
        }
        break;
      }

      case 'admin_secret_put':
      case 'admin_secret_delete': {
        $product = (string)($in['product'] ?? '');
        $name = (string)($in['name'] ?? '');
        $value = $in['value'] ?? null;
        $file = RegPusyutterStore::path('secrets.json');
        $secrets = RegPusyutterStore::readJson($file, []);
        $exists = RegPusyutterSchema::indexOf(RegPusyutterSchema::load(), $product) >= 0;

        if (!$exists) {
          // 台帳に無いプロダクトへの機密は、打ち間違いの可能性が高いので受け付けません。
          $reply = registryFail(404, 'not_found', "プロダクト「{$product}」は台帳にありません。先に admin_product_put で登録してください。");
        } else if (preg_match(RegPusyutterSchema::SECRET_NAME_PATTERN, $name) !== 1) {
          $reply = registryFail(400, 'invalid_name', 'name は英数字・_・.・- の64文字以内で指定してください。');
        } else if ($api === 'admin_secret_put' && !RegPusyutterSchema::isText($value, 65536)) {
          $reply = registryFail(400, 'invalid_value', 'value は文字列で指定してください。');
        } else if ($api === 'admin_secret_delete' && !isset($secrets[$product][$name])) {
          $reply = registryFail(404, 'not_found', "機密「{$product} / {$name}」はありません。");
        } else {
          if ($api === 'admin_secret_put') {
            $secrets[$product][$name] = $value;
          } else {
            unset($secrets[$product][$name]);
            if (empty($secrets[$product])) {
              unset($secrets[$product]);
            }
          }
          // 応答に値は載せません(管理者の手元のログに機密が残らないように)。
          $reply = RegPusyutterStore::writeJson($file, $secrets)
            ? registryReply(200, ['ok' => true, 'product' => $product, 'name' => $name])
            : registryFail(500, 'write_failed', '機密を保存できませんでした。');
        }
        break;
      }

      case 'admin_client_put': {
        $c = is_array($in['client'] ?? null) ? $in['client'] : [];
        $id = (string)($c['id'] ?? '');
        $role = (string)($c['role'] ?? '');
        $product = (string)($c['product'] ?? '');
        $key = RegPusyutterAuth::base64UrlDecode(strtr((string)($c['public_key'] ?? ''), '+/', '-_'));
        $clients = RegPusyutterAuth::listClients();
        $before = RegPusyutterAuth::loadClient($id);
        $adminsAfter = registryAdminCount($clients)
          - (($before !== null && $before['role'] === RegPusyutterSchema::ROLE_ADMIN) ? 1 : 0)
          + (($role === RegPusyutterSchema::ROLE_ADMIN) ? 1 : 0);

        if (preg_match(RegPusyutterSchema::ID_PATTERN, $id) !== 1) {
          $reply = registryFail(400, 'invalid_id', 'id は英小文字・数字・_・- の64文字以内で指定してください。');
        } else if ($key === null || strlen($key) !== 32) {
          $reply = registryFail(400, 'invalid_key', 'public_key は Ed25519 の公開鍵(32バイト)を base64 で指定してください。');
        } else if ($role !== RegPusyutterSchema::ROLE_ADMIN && $role !== RegPusyutterSchema::ROLE_PRODUCT) {
          $reply = registryFail(400, 'invalid_role', 'role は admin か product で指定してください。');
        } else if ($role === RegPusyutterSchema::ROLE_PRODUCT && RegPusyutterSchema::indexOf(RegPusyutterSchema::load(), $product) < 0) {
          $reply = registryFail(404, 'not_found', "role が product のときは、台帳にあるプロダクトを product に指定してください(「{$product}」はありません)。");
        } else if ($adminsAfter < 1) {
          $reply = registryFail(409, 'last_admin', '管理者が1人もいなくなる変更はできません。先に別の管理者を登録してください。');
        } else {
          $record = [
            'id'         => $id,
            'role'       => $role,
            'product'    => ($role === RegPusyutterSchema::ROLE_PRODUCT) ? $product : null,
            'public_key' => base64_encode($key),
            'note'       => RegPusyutterSchema::isText($c['note'] ?? '', 1000) ? (string)($c['note'] ?? '') : '',
            'created_at' => ($before === null) ? gmdate('Y-m-d\TH:i:s\Z') : (string)($before['created_at'] ?? ''),
          ];
          $reply = RegPusyutterStore::writeJson(RegPusyutterStore::path('clients/' . $id . '.json'), $record)
            ? registryReply(200, ['ok' => true, 'client' => $record, 'created' => $before === null])
            : registryFail(500, 'write_failed', 'クライアントを保存できませんでした。');
        }
        break;
      }

      case 'admin_client_delete': {
        $id = (string)($in['id'] ?? '');
        $target = (preg_match(RegPusyutterSchema::ID_PATTERN, $id) === 1) ? RegPusyutterAuth::loadClient($id) : null;
        $admins = registryAdminCount(RegPusyutterAuth::listClients());

        if ($target === null) {
          $reply = registryFail(404, 'not_found', "クライアント「{$id}」は登録されていません。");
        } else if ($target['role'] === RegPusyutterSchema::ROLE_ADMIN && $admins <= 1) {
          $reply = registryFail(409, 'last_admin', '最後の管理者は消せません。先に別の管理者を登録してください。');
        } else if (!@unlink(RegPusyutterStore::path('clients/' . $id . '.json'))) {
          $reply = registryFail(500, 'write_failed', 'クライアントを削除できませんでした。');
        } else {
          $reply = registryReply(200, ['ok' => true, 'deleted' => $id]);
        }
        break;
      }

      default: {
        $reply = registryFail(404, 'unknown_api', "管理操作「{$api}」はありません。");
        break;
      }
    }

    return $reply;
  });

  if ($reply === null) {
    $reply = registryFail(503, 'lock_failed', '台帳のロックを取れませんでした。');
  } else {
    error_log('[RegPusyutter] 管理操作 ' . $api . ' by ' . $admin['id'] . ' => ' . $reply['status']);
  }

  return $reply;
}

/** 要求1件を処理し、返す内容を決めます。出力はしません。 */
function registryHandle(): array {
  $api = (string)($_GET['api'] ?? '');
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  $isRead = ($method === 'GET' || $method === 'HEAD');
  $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  $body = ($length > REGISTRY_MAX_BODY_BYTES) ? '' : (string)@file_get_contents('php://input', false, null, 0, REGISTRY_MAX_BODY_BYTES + 1);

  // 署名付きの要求は、名乗りが何であれ署名で判断します(BOT判定には掛けません)。
  $signedAttempt = isset($_SERVER['HTTP_X_PUSYUU_SIGNATURE']);
  $isBot = !$signedAttempt && preg_match(REGISTRY_BOT_UA_PATTERN, (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) === 1;
  $ready = (RegPusyutterStore::privateDir() !== null);
  if ($ready) {
    // どの要求でも最初に1回だけ見ます。署名の確認より前に取り込むのは、
    // 置いた直後の最初の管理操作から、その鍵で通るようにするためです。
    RegPusyutterAuth::importAdminInbox();
  }

  if (!$ready) {
    // 保存先が無いまま推測で動くより、全部を止めて気付かせる方を選びます。
    // 呼ぶ側は控えで動き続けるので、ここが止まってもプロダクトは止まりません。
    $reply = registryFail(503, 'server_misconfigured', '台帳の保存先が設定されていません。');

  } else if ($isBot) {
    $reply = registryFail(403, 'bot_refused', 'クローラーからの取得はお断りしています。');

  } else if ($length > REGISTRY_MAX_BODY_BYTES || strlen($body) > REGISTRY_MAX_BODY_BYTES) {
    // CONTENT_LENGTH で先に見るのは、上限を超えた本文を読み込む前に断るためです。
    $reply = registryFail(413, 'too_large', '本文が大きすぎます。');

  } else if ($api === 'futures') {
    // 旧 futures.json と同じ形(links と terms)に、どのサービスの台帳か(service)を足したもの。
    // 一覧に出す(listed)ものだけを、公開の顔の住所で返します。
    // 【service は足すだけにすること】links と terms の形を変えると、旧 futures.json を読んでいた所が壊れます。
    $registry = RegPusyutterSchema::load();
    $links = [];
    foreach ($registry['products'] as $product) {
      if (($product['listed'] ?? true) === true) {
        $links[] = RegPusyutterSchema::toLegacyLink($product);
      }
    }
    $reply = $isRead
      ? registryPublicReply(['ok' => true, 'service' => $registry['service'], 'links' => $links, 'terms' => $registry['terms']], 'futures' . RegPusyutterStore::readRaw(RegPusyutterStore::path('registry.json')))
      : registryFail(405, 'method_not_allowed', 'futures は GET で呼んでください。');

  } else if ($api === 'registry') {
    // 台帳の全部。一覧に出さないもの(listed が false)も含みます。
    // 呼ぶ側は、今の要求の入口に合う顔を faces から選び、合う物が無ければ public に倒します。
    $raw = RegPusyutterStore::readRaw(RegPusyutterStore::path('registry.json'));
    $reply = $isRead
      ? registryPublicReply(['ok' => true] + RegPusyutterSchema::load(), 'registry' . $raw)
      : registryFail(405, 'method_not_allowed', 'registry は GET で呼んでください。');

  } else if ($api === 'secrets' || strncmp($api, 'admin_', 6) === 0) {
    $auth = RegPusyutterAuth::verify($body);
    $client = $auth['client'];
    $role = (string)($client['role'] ?? '');
    $asked = (string)($_GET['product'] ?? '');

    if (!$auth['ok']) {
      error_log('[RegPusyutter] 認証を拒否しました: ' . $auth['reason']);
      $reply = ($auth['status'] === 500)
        ? registryFail(500, 'server_misconfigured', 'サーバで署名を確かめられません。')
        : registryFail(401, 'unauthorized', '認証できません。');

    } else if ($api === 'secrets' && !$isRead) {
      $reply = registryFail(405, 'method_not_allowed', 'secrets は GET で呼んでください。');

    } else if ($api === 'secrets' && $role === RegPusyutterSchema::ROLE_PRODUCT && $asked !== '' && $asked !== $client['product']) {
      // プロダクトの鍵で読めるのは、そのプロダクト自身の機密だけです。
      $reply = registryFail(403, 'forbidden', '他のプロダクトの機密は読めません。');

    } else if ($api === 'secrets' && $role === RegPusyutterSchema::ROLE_ADMIN && $asked === '') {
      $reply = registryFail(400, 'product_required', '管理者の鍵で読むときは ?product= を指定してください。');

    } else if ($api === 'secrets') {
      $product = ($role === RegPusyutterSchema::ROLE_PRODUCT) ? (string)$client['product'] : $asked;
      $secrets = RegPusyutterStore::readJson(RegPusyutterStore::path('secrets.json'), []);
      $mine = is_array($secrets[$product] ?? null) ? $secrets[$product] : [];
      $sealed = RegPusyutterAuth::seal(json_encode(['product' => $product, 'secrets' => (object)$mine], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $client);
      $reply = ($sealed === null)
        ? registryFail(500, 'seal_failed', '機密を封筒に入れられませんでした。')
        : registryReply(200, ['ok' => true, 'product' => $product, 'sealed' => $sealed]);

    } else if ($role !== RegPusyutterSchema::ROLE_ADMIN) {
      $reply = registryFail(403, 'forbidden', '管理操作には管理者の鍵が必要です。');

    } else if ($api === 'admin_clients') {
      $reply = $isRead
        ? registryReply(200, ['ok' => true, 'clients' => RegPusyutterAuth::listClients()])
        : registryFail(405, 'method_not_allowed', 'admin_clients は GET で呼んでください。');

    } else if ($method !== 'POST') {
      $reply = registryFail(405, 'method_not_allowed', '管理操作は POST で呼んでください。');

    } else {
      $parsed = registryJsonBody($body);
      $reply = $parsed['ok'] ? registryAdmin($api, $parsed['data'], $client) : $parsed['reply'];
    }

  } else {
    $reply = registryFail(404, 'unknown_api', '?api= に futures / registry / secrets / admin_… のどれかを指定してください。');
    // どのサービスの台帳かは、この応答にも載せます(住所だけを開いた人が、何の台帳か分かるように)。
    $reply['body']['service'] = RegPusyutterSchema::load()['service'];
  }

  return $reply;
}

// 想定外の例外でも、真っ白なページや PHP の生のエラーを返さず、JSONの 500 にします。
// 呼ぶ側は「壊れている」として扱い、控えで動き続けられます。
try {
  $registryReply = registryHandle();
} catch (Throwable $e) {
  error_log('[RegPusyutter] 想定外の例外: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  $registryReply = registryFail(500, 'internal_error', 'サーバ内部で問題が起きました。');
}

// 出力はここだけです。ここより上で echo しないでください(ヘッダを送れなくなります)。
http_response_code($registryReply['status']);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
// 検索エンジンの索引・控えに載せません(断りきれなかったクローラー向けの二段目)。
header('X-Robots-Tag: noindex, nofollow, noarchive');
if (!isset($registryReply['headers']['Cache-Control'])) {
  // 公開の読み出し以外(機密・管理操作・失敗)は、どこにも控えを残させません。
  header('Cache-Control: no-store');
}
foreach ($registryReply['headers'] as $name => $value) {
  header($name . ': ' . $value);
}
if ($registryReply['body'] !== null && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
  echo json_encode($registryReply['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
