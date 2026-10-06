# NextFleet

NextFleet is a logbook for your vehicles in Nextcloud. It records the counter, trips, fuel and
charging, maintenance and costs. It reminds you of inspections and services. You can give other
users access to a vehicle. The owner, a driver or a manager can book it, take it and return it.

- Nextcloud 31 to 34.
- English and German (formal and informal).
- Version 0.3.1 is the first release. [CHANGELOG.md](CHANGELOG.md) lists its functions.

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

## How this app was made

An AI tool, Claude Code, wrote most of the code, the tests and the documents. It worked in an
autonomous loop that the maintainer supervised. The maintainer reviewed and tested the result, and
answers for every line. [Nextcloud's AI
policy](https://github.com/nextcloud/.github/blob/master/AI_POLICY.md) applies to this app.
[Contributing](docs/contributing.md#ai-assistance) gives its rules for your changes.

## License

Copyright (C) 2026 Johannes Kolb

AGPL-3.0-or-later. Refer to [LICENSE](LICENSE). The frontend includes `@nextcloud/vue`, which has
the AGPL licence. [legal.md](docs/legal.md) gives the reasons for the licence.
