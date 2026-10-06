PHPUnit as a client: HTTP to a running Nextcloud, signed in with an app password, no server class
loaded — the layer that proves `../../docs/api.md` is what a client gets.

`ClientTest` makes a fresh account and its app password through `occ`, as a client is handed one,
and deletes it afterwards (`../../docs/development.md#testing`). `SliceTest` walks an owner and a
driver, two accounts, kept in step by sync alone. `Server` holds the HTTP and the `occ`, `Syncing`
a client's sync; a new case goes through them.

Its own config (`../../phpunit.api.xml`), with only the autoloader as bootstrap. It needs the
server's `occ` and its address, so it runs where the integration suite runs; the command is in
`../../docs/development.md#testing`.

These tests write to the instance they run against. Point them at a dev container.
