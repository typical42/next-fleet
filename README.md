# NextFleet

NextFleet is a logbook for your vehicles in Nextcloud. It records the counter, trips, fuel and
charging, maintenance and costs. It reminds you of inspections and services. You can give other
users access to a vehicle. A driver or a manager can book it, take it and return it.

- Nextcloud 31 to 34.
- English and German (formal and informal).
- Version 0.3.1 is the first release. It is not published yet. [CHANGELOG.md](CHANGELOG.md) lists
  its functions.

## Documentation

| Part | For |
|---|---|
| [User manual](docs/user/README.md) | Users of the app |
| [Administration](docs/admin/README.md) | Administrators of a Nextcloud server |
| [Developer guide](docs/developer/README.md) | People who change the code |

## Your data

- NextFleet keeps its data in the Nextcloud database. Only the database backup of your
  administrator protects it against a damaged database
  ([data and backups](docs/admin/data.md)).
- NextFleet itself sends only the reminder mails off the server. They use the mail account of the
  server. Nextcloud can send notifications to phones through its push service.
- An administrator can read the database. There is no client-side encryption.
- The Nextcloud *User migration* app exports your NextFleet data with your account. An import of
  that archive restores nothing.
- When an administrator deletes an account, NextFleet replaces its name with a random name
  ([ADR 0008](docs/adr/0008-erasing-a-driver-pseudonymises.md)). [legal.md](docs/legal.md) tells
  you what stays, and why.

## No legal review

NextFleet helps you keep a vehicle logbook. It does not promise that a tax office, an auditor or a
court accepts the result. Nobody did a legal review of the app. The rules and rates come from
public sources, and they can be wrong or old. The licence gives no warranty. Ask your own adviser
before you rely on the app.

## Security

Report a security problem privately. [SECURITY.md](SECURITY.md) tells you how.

## License

Copyright (C) 2026 Johannes Kolb

AGPL-3.0-or-later. Refer to [LICENSE](LICENSE). The Nextcloud app store requires it, and the
frontend includes `@nextcloud/vue`, which has the AGPL licence.
