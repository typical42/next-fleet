PHPUnit against a running Nextcloud and a real database — the layer where migrations, QBMapper
queries, optimistic concurrency and the access checks are worth trusting.

Its own bootstrap (`../integration-bootstrap.php`) and config (`../../phpunit.integration.xml`),
because `../bootstrap.php` deliberately has no server. The command is in
`../../docs/development.md#testing`.

These tests write to the instance they run against. Point them at a dev container.
