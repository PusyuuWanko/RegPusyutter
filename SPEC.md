# RegPusyutter 仕様書

version: 2.0 / 最終更新: 2026-10-11(前の名前は pusyuu_registry)

## 0. これは何か

RegPusyutter は、**1つのサービスに属するプロダクトの住所録(台帳)API** です。
各プロダクトは、自分以外の住所(他プロダクトのURL・入口ごとの顔)と、自分宛ての機密を、ここから受け取ります。

- サービスが複数あるときは、サービスごとに1つ置きます(例: イサミーズサービス用と、プシューサービス用)。どのサービスの台帳かは、台帳の `service` 欄に持ち、公開の読み出しにも出します(2節・3節)。
- プロダクトの一覧(旧 `futures.json` の役目)も、このAPIが受け持ちます(`?api=futures`)。
- ドメインやパスを推測する処理は、このAPIの内側だけに閉じ込めます。プロダクトは推測しません。
- サーバを移すときは、`index.php` 冒頭の `REGPUSYUTTER_HIDDEN_PATH` を書き換え、非公開フォルダごと持っていきます(6節)。

**名前を変えても、通信の約束は変えていません。** 署名する文字列の先頭の印(`pusyuu_registry/1`)と見出しの名前(`X-Pusyuu-*`)は、前の名前のときのままです(4節)。

## 1. 呼ぶ側の約束(ゼロトラスト)

**このAPIには繋がらないことがある、という前提で作ってください。**

1. このAPIは「取りに行く場所」です。プロダクトは手元の控えで動き、**繋がった時だけ控えを更新**します。
2. 呼ぶときは**短いタイムアウト**(1秒程度)にし、1回の要求の中では1度だけ呼びます。
3. 取りに行く頻度は間引きます(前回試してから一定時間が経った時だけ)。APIが落ちている間に、全ての要求がタイムアウトまで待たされないためです。
4. 失敗は次の3つに分けて記録します。**届かない**(接続できない・時間切れ)/**壊れている**(JSONとして読めない・`ok` が true でない)/**項目が無い**(探しているIDや顔が台帳に無い)。
5. 控えが一度も無いまま読めなかったとき、または控えの置き場所が無いときは、**台帳を必要とする機能だけ**を止めます。プロダクト全体は止めません。控えを一時フォルダなど他の場所へ逃がすことはしません。
6. 自分へのリンク・フォームの送信先・リダイレクトには台帳を使いません。相対URLで書きます。
7. 公開の読み出しは `ETag` を返します。控えの ETag を `If-None-Match` に付ければ、変わっていないときは `304` が返ります。
8. **台帳が複数あるときは、控えを台帳ごとに分けます。** 同じ控えのファイルを使うと、別のサービスの台帳を読んでしまいます。

## 2. 台帳の形

```json
{
  "version": 1,
  "updated_at": "2026-10-11T00:00:00Z",
  "service": { "id": "isami", "name": "イサミーズサービス" },
  "products": [
    {
      "id": "pips",
      "title": "isamisIPS",
      "description": "…",
      "image": "/assets/images/pips_logo.png",
      "listed": true,
      "faces": {
        "public": "https://isami.moe/products/pips",
        "legacy": "http://isami.moe/products/pips"
      },
      "internal": "",
      "settings": {}
    }
  ],
  "terms": []
}
```

| 欄 | 意味 |
|---|---|
| `service` | **どのサービスの台帳か**。`id` は英小文字・数字・`_`・`-` の64文字以内、`name` は表示用の名前(200バイト以内)。まだ決めていない台帳では `{"id":"","name":""}`。`admin_service_put` で設定します |
| `products[].id` | プロダクトが**自分を名乗る名前**。ドメインとは無関係。英小文字・数字・`_`・`-`、64文字以内 |
| `faces` | **入口ごとの住所**。名前は自由(英小文字・数字・`_`・`-`)。`public` は必須 |
| `image` | 空/絶対URL/`/` で始まるパス。パスのときは、使う顔の住所の後ろに付けて使う |
| `listed` | 一覧に出すか。住所は要るが一覧に出さないもの(アカウントの仕組みなど)は `false` |
| `internal` | サーバ間の呼び出しで実際に繋ぐ先(省略・空なら `public` へそのまま繋ぐ)。例: `https://127.0.0.1`。名乗るホスト名(Host・証明書の確認)は `faces.public` のホスト。ブラウザへ渡すリンクには使わない |
| `settings` | HTTPの住所ではない、公開してよい設定値 `{"名前": "値"}`。**機密は入れない**(台帳は誰でも読める) |
| `terms` | 旧 futures.json から引き継いだ欄(文字列だけを持つオブジェクトの並び) |

**古い端末向けの http の顔**: 一部の古い端末(IE6・3DS等)にだけ http のまま応答する構成のために、`legacy`(`public` の http 版)のような顔を置けます。

**顔の選び方(呼ぶ側の約束)**: ①今の入口と同じ名前の顔 → ②今の要求と同じスキームの顔 → ③ `public`。今の要求が来た入口を、台帳に載っている顔の住所と**照らし合わせて**決めます。推測はしません。

### 2.1 プロダクト側の読み手

読み手は `pusyuu_registry_client.php`(`PusyuuRegistryClient`)です。1節の約束(控え・タイムアウト・間引き・失敗の3分類・台帳ごとの控え)はすべてこの中にあります。
読み込んだ直後に `PusyuuRegistryClient::init(<共有の非公開フォルダ>, <この台帳の住所>)` を呼びます。台帳の住所は、プロダクトの入口に定数で書きます(読み手は住所を持ちません)。

| 呼び方 | 用途 |
|---|---|
| `url(id, path)` | ブラウザへ渡すリンク(今の入口に合う顔、無ければ public) |
| `publicUrl(id, path)` | 通知・RSS・OGPなど、要求と無関係に誰かへ届くもの |
| `links(selfId)` | 一覧(旧 futures.json の links)。自分は ID で外す |
| `route(id, path)` / `call(...)` | サーバ間の呼び出し(`internal` と Host を台帳から決める) |
| `location(id, path)` | ほかのライブラリの初期化へ渡す居場所 `['api', 'host', 'site']` |
| `knownScheme()` | 今の入口の顔が台帳で分かったときだけ、そのスキーム(分からなければ null) |
| `isKnownUrl(url)` / `knownHosts()` | 自サイト判定など(台帳が読めなければ「どこも許さない」)。ログインの戻り先の許可には使いません(台帳は住所録で、ログインを使ってよい相手の名簿ではないため) |

## 3. 公開の読み出し(認証なし)

| 呼び方 | 返すもの |
|---|---|
| `GET ?api=futures` | 旧 futures.json の形に `service` を足したもの。`{"ok":true,"service":{"id","name"},"links":[{"id","title","description","url","image"}],"terms":[]}`。`listed` が true のものだけを、`public` の顔で返す。`image` は絶対URLに直してある |
| `GET ?api=registry` | 台帳の全部(2節の形に `ok` が付いたもの)。`listed` が false のものも含む |

`futures` は、旧 futures.json を読んでいた所をそのまま差し替えるための形です(`service` は足しただけなので、旧い読み手はそのまま動きます)。新しく書く読み手は `registry` を使い、2節の約束で顔を選んでください。

`?api=` が無い・知らない値のときは `404 unknown_api` ですが、その応答にも `service` を載せます(住所だけを開いた人が、何の台帳か分かるように)。

## 4. 署名(機密と管理操作)

機密と管理操作は、Ed25519 の署名で本人確認できた相手にだけ応じます。**サーバは公開鍵しか持ちません。**

### 4.1 見出し

| 見出し | 中身 |
|---|---|
| `X-Pusyuu-Client` | 登録したクライアントのID |
| `X-Pusyuu-Timestamp` | 今のUNIX秒。サーバの時計と ±300秒 以内 |
| `X-Pusyuu-Nonce` | 毎回新しい乱数。英数字・`_`・`-` の16〜128文字。**同じものは2度と通りません** |
| `X-Pusyuu-Signature` | 下の文字列への Ed25519 署名(64バイト)を base64url(末尾の `=` は有っても無くてもよい) |

### 4.2 署名する文字列

次の6つを改行(`\n`)1つでつないだもの。

```
pusyuu_registry/1
メソッド(GET / POST を大文字で)
問い合わせ文字列(? より後ろ。送るURLと1文字も違わないこと。例: api=secrets&product=pips)
X-Pusyuu-Timestamp と同じ値
X-Pusyuu-Nonce と同じ値
本文のSHA-256(16進・小文字)。本文が無いときは空文字のSHA-256
```

先頭の `pusyuu_registry/1` は前の名前のときに決めた印で、**名前を変えた後もこのままです**(変えると、配った全ての読み手・管理画面の署名が通らなくなります)。

ホスト名とパスは含めません。住所の見え方(末尾の `/` や `index.php` の有無)が中継や移設で変わっても署名が壊れないようにするためです。

### 4.3 台帳ごとに別の鍵を使うこと

署名に住所が入っていないので、ある台帳あての署名付きの要求は、**同じ公開鍵を登録している別の台帳でも**(時刻の幅の中なら)通ります。使い済みの合図は台帳ごとに覚えるので、別の台帳への使い回しは弾けません。
1つの管理者(管理画面など)が複数の台帳を触るときは、**台帳ごとに鍵の対を分けて**登録してください。

### 4.4 失敗したとき

どこで落ちても応答は `401 unauthorized`(「認証できません」)だけです。どこで落ちたかは、サーバのエラーログに `[RegPusyutter] 認証を拒否しました: …` として残ります。

## 5. 機密と管理操作

### 5.1 機密の読み出し

`GET ?api=secrets`(署名必須)

- `product` 役のクライアント: 自分のプロダクトの機密だけ。`?product=` に別のプロダクトを書くと `403`
- `admin` 役のクライアント: `?product=` の指定が必須

応答は `{"ok":true,"product":"pips","sealed":"<base64>"}`。`sealed` は、**そのクライアントの鍵でしか開けない封筒**(libsodium の sealed box)です。開くと `{"product":"pips","secrets":{"名前":"値", …}}` になります。

開け方(PHP、sodium):

```php
$edSk = /* クライアントの Ed25519 秘密鍵(64バイト) */;
$edPk = substr($edSk, 32);
$kp = sodium_crypto_box_keypair_from_secretkey_and_publickey(
  sodium_crypto_sign_ed25519_sk_to_curve25519($edSk),
  sodium_crypto_sign_ed25519_pk_to_curve25519($edPk)
);
$json = sodium_crypto_box_seal_open(base64_decode($reply['sealed']), $kp);
```

### 5.2 管理操作(`admin` 役の署名が必須)

本文はJSONのオブジェクトです(上限256KB)。

| 呼び方 | 本文 | 動き |
|---|---|---|
| `POST ?api=admin_service_put` | `{"service":{"id":"isami","name":"イサミーズサービス"}}` | どのサービスの台帳かを設定する(2節の `service`) |
| `POST ?api=admin_product_put` | `{"product":{…2節の1件…}}` | 同じIDがあれば同じ位置で丸ごと差し替え、無ければ末尾に追加 |
| `POST ?api=admin_product_delete` | `{"id":"pips"}` | 台帳から外す。**機密と鍵は消さない**(戻したときにやり直しにならないため) |
| `POST ?api=admin_product_order` | `{"ids":["main","pips",…]}` | 並び順を丸ごと指定。台帳の全IDを重複なく1回ずつ |
| `POST ?api=admin_terms_put` | `{"terms":[…]}` | terms を丸ごと差し替え |
| `POST ?api=admin_secret_put` | `{"product":"pips","name":"push_api_url","value":"…"}` | 機密を1つ置く。応答に値は載せない |
| `POST ?api=admin_secret_delete` | `{"product":"pips","name":"push_api_url"}` | 機密を1つ消す |
| `POST ?api=admin_client_put` | `{"client":{"id","role","product","public_key","note"}}` | クライアント(公開鍵)を登録・更新。`role` は `admin` か `product`。`product` 役のときは、台帳にあるプロダクトを `product` に書く |
| `POST ?api=admin_client_delete` | `{"id":"…"}` | クライアントを消す |
| `GET ?api=admin_clients` | なし | 登録されているクライアントの一覧(公開鍵だけなので見せてよい) |

**管理者を0人にする変更**(最後の管理者の削除・降格)は `409 last_admin` で断ります。

### 5.3 失敗の形

`{"ok":false,"error":"<英字の種類>","message":"<日本語の説明>"}`。種類は `invalid_service` `invalid_product` `invalid_order` `invalid_terms` `invalid_name` `invalid_value` `invalid_id` `invalid_key` `invalid_role` `not_found` `last_admin` `bad_json` `too_large` `unauthorized` `forbidden` `product_required` `method_not_allowed` `unknown_api` `bot_refused` `write_failed` `lock_failed` `seal_failed` `server_misconfigured` `internal_error`。

## 6. 置き場所と、最初の管理者

### 6.1 公開外ディレクトリ

`index.php` 先頭の `REGPUSYUTTER_HIDDEN_PATH` に、**公開フォルダの外**にあるこの台帳専用の非公開フォルダを、`index.php` のあるフォルダからの相対パスで書きます(使う前に「フォルダがあって読めるか」「公開範囲と重なっていないか」を確かめます)。台帳はその下の `data/` に置きます。

- 中身は `registry.json`(台帳)・`secrets.json`(機密)・`clients/<ID>.json`(公開鍵)・`nonces/`(使い済みの合図)・`registry.lock`。
- サーバを移すときは、このフォルダごと持っていき、`REGPUSYUTTER_HIDDEN_PATH` を書き換えるだけで動きます。
- 前の名前(pusyuu_registry)の `data/` は、そのまま使えます(`service` 欄が無い台帳は、空の `service` として読みます)。
- **同じサーバに2つの台帳を置くときは、別々のフォルダを指してください。** 同じフォルダを指すと台帳が混ざります。
- 確かめられないと全部 `503`。公開範囲(DOCUMENT_ROOT 等)の内側を指していても `503`(機密をWebから読める場所に置く事故を防ぐため)。

### 6.2 管理者の登録は「受け取り箱」から

公開外ディレクトリはサーバのPHPが「所有者だけ読み書きできる」形で作るので、ファイル共有などからは中へファイルを置けないことがあります。
そこで `REGPUSYUTTER_HIDDEN_PATH` の直下の `regpusyutter_admin.json` に、次の1行を書いたファイルを置きます。

```json
{"id": "<ID>", "public_key": "<公開鍵(base64)>"}
```

- 次にAPIへ何か要求が来たとき(`?api=futures` を開くだけでよい)に取り込まれ、箱は消えます。
- 結果は隣の `regpusyutter_admin.json.result.txt` に日本語で書かれます。取り込めなかったときは箱が残り、理由がそこに書かれます。
- 同じ ID を置くと公開鍵が置き換わります(鍵をなくした・漏れた時の作り直し)。
- 先頭の BOM や、鍵の途中の改行は取り除いてから読みます(メモ帳で保存しても通ります)。
- この箱に書ける人は、もともとサーバのファイルを書き換えられる人なので、箱は新しい抜け道ではありません。ただし**公開フォルダの内側を指すと、誰でも管理者を足せる**ようになるため、内側を指していたら取り込みません。

鍵の作り方から登録まで、画面の操作だけで進める手順は `ADMIN_KEY_GUIDE.md` にあります。
2人目以降と、プロダクトの鍵は `admin_client_put` で登録します。

## 7. あえてやっていないこと

- **管理操作の本文(機密の値を含む)は封筒に入れていません。** 行きはTLSだけで守っています。サーバ側の鍵の対が別に必要になり、鍵の置き場所がもう1つ増えるためです。
- **失敗回数による締め出しはしていません。** 署名は総当たりで破れないので、締め出しは攻撃者が正規の管理者を締め出す手段にしかならないためです。
- **応答そのものへの署名はしていません。** 呼ぶ側はTLSでこの住所の本物であることを確かめます。
- **署名に住所を入れていません。** 理由と、その代わりの約束(台帳ごとに鍵を分ける)は 4.3 にあります。
