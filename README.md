# RegPusyutter

1つのサービスに属するプロダクトの**住所録(台帳)API** です。前の名前は pusyuu_registry です。
各プロダクトは、自分以外の住所・一覧に出すプロダクト・自分宛ての機密をここから受け取り、ドメインやパスをプロダクトの中に書かずに済みます。
どのサービスの台帳かは、応答の `service` 欄で分かります。

```json
GET ?api=futures
{"ok":true,"service":{"id":"isami","name":"イサミーズサービス"},"links":[…],"terms":[]}
```

## できること

- `?api=futures` / `?api=registry` … 誰でも読める一覧と台帳(ETag つき)
- `?api=secrets` … プロダクト宛ての機密を、そのプロダクトの鍵でしか開けない封筒に入れて返す
- `?api=admin_…` … 台帳の編集(Ed25519 の署名が必要。サーバは公開鍵しか持ちません)

読む側の部品は [pusyuu_registry_client](https://github.com/PusyuuWanko/pusyuu_registry_client) です。

## 置き方

1. `index.php` を公開フォルダに置きます(PHP 8.0 以上・sodium。mbstring と curl は要りません)。
2. 公開フォルダの**外**に、この台帳専用のフォルダを作ります(既定の名前は `index.php` の1つ上の `.regpusyutter_private`。以前の `.pusyuuHiddenFiles` と同じく `.` で始めます)。
3. `index.php` の先頭の `REGPUSYUTTER_HIDDEN_PATH` に、そのフォルダを `index.php` からの相対パスで書きます。
4. 最初の管理者を受け取り箱から登録します(手順は [ADMIN_KEY_GUIDE.md](ADMIN_KEY_GUIDE.md))。
5. `admin_service_put` で、どのサービスの台帳かを設定します。

サービスが複数あるときは、サービスごとに置き、それぞれ別のフォルダを指してください。
管理者の鍵も台帳ごとに分けてください(理由は [SPEC.md](SPEC.md) の 4.3)。

呼び方の詳細は [SPEC.md](SPEC.md) にあります。

## ライセンス

[MIT License](LICENSE) © 2026 ISAMI ABE
