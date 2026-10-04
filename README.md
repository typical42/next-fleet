# next-fleet

Nextcloud app for keeping a vehicle logbook.

The plan lives in [plan.md](plan.md), with the detail in [docs/](docs/) and screen mockups in [design/](design/).
[CONTEXT.md](CONTEXT.md) is the glossary — the words the code uses.
Decisions that are hard to reverse are in [docs/adr/](docs/adr/), and where a document and an ADR disagree, the ADR wins.
Version 0.3.1 is the first release, on Nextcloud 31 to 34, and not out yet. It keeps vehicles and
their odometer, trips and the Fahrtenbuch, energy, maintenance, expenses and the Costs screen,
reminders, documents from Files, CSV export and the mileage claim. The owner can give others access
to a vehicle; they book it, hand it over and file receipts from an inbox folder. An OCS API with a
sync endpoint lets a client such as an Android app be built against it ([docs/api.md](docs/api.md)).
A vehicle's history imports from a CSV file in Files, in LubeLogger or Spritmonitor format.
The details are in [CHANGELOG.md](CHANGELOG.md).

Languages: English and German (formal and informal).

## Your data

Nextcloud's *user_migration* app exports your NextFleet data with your account: every row that
names you, as a JSON file per table. Importing that archive restores nothing. Deleting an account
pseudonymises its rows ([ADR 0008](docs/adr/0008-erasing-a-driver-pseudonymises.md)).

An admin can read the database; there is no client-side encryption. Reminder mails go out through
the server's mail account. What stays after an erasure, and why, is in
[docs/legal.md](docs/legal.md).

## No legal review

NextFleet helps you keep a vehicle logbook. It does not promise that the result satisfies any tax
office, auditor or court. Nothing here has been checked by a lawyer, the rules and rates were
written from public sources and may be wrong or outdated, and the licence gives no warranty. Ask your own adviser before relying on it.

## License

Copyright (C) 2026 Johannes Kolb

AGPL-3.0-or-later — see [LICENSE](LICENSE). Required by the Nextcloud app store, and the frontend
bundles AGPL-licensed `@nextcloud/vue`.
