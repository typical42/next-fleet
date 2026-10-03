# next-fleet

Nextcloud app for vehicle fleet management.

The plan lives in [plan.md](plan.md), with the detail in [docs/](docs/) and screen mockups in [design/](design/).
[CONTEXT.md](CONTEXT.md) is the glossary — the words the code uses.
Decisions that are hard to reverse are in [docs/adr/](docs/adr/), and where a document and an ADR disagree, the ADR wins.
Version 0.2.0 is v1, on Nextcloud 31 to 34, and not released yet: vehicles and their odometer, trips
and the Fahrtenbuch, energy, maintenance, expenses and the Costs screen, reminders, documents from
Files, CSV export and the mileage claim. Since then M6 lets the owner give others access to a
vehicle, M7 lets them book it, hand it over and file receipts from an inbox folder, and M8 opens
an OCS API with a sync endpoint, so a client such as an Android app can be built against it
([docs/api.md](docs/api.md)). M9 imports a vehicle's history from a CSV file in Files, in LubeLogger or
Spritmonitor format. All four are unreleased 0.3.0.
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
