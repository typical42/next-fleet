# next-fleet

Nextcloud app for vehicle fleet management.

The plan lives in [plan.md](plan.md), with the detail in [docs/](docs/) and screen mockups in [design/](design/).
[CONTEXT.md](CONTEXT.md) is the glossary — the words the code uses.
Decisions that are hard to reverse are in [docs/adr/](docs/adr/), and where a document and an ADR disagree, the ADR wins.
Version 0.2.0 is v1, on Nextcloud 31 to 34: vehicles and their odometer, trips and the Fahrtenbuch,
energy, maintenance, expenses and the Costs screen, reminders, documents from Files, CSV export and
the mileage claim. Sharing a vehicle and the rest of M6+ in [plan.md](plan.md) are not built yet.
What changed is in [CHANGELOG.md](CHANGELOG.md).

Languages: English and German (formal and informal).

## No legal review

NextFleet helps you keep a vehicle logbook. It does not promise that the result satisfies any tax
office, auditor or court. Nothing here has been checked by a lawyer, the rules and rates were
written from public sources and may be wrong or outdated, and the licence gives no warranty. Ask your own adviser before relying on it.

## License

Copyright (C) 2026 Johannes Kolb

AGPL-3.0-or-later — see [LICENSE](LICENSE). Required by the Nextcloud app store, and the frontend
bundles AGPL-licensed `@nextcloud/vue`.
