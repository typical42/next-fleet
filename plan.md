# NextFleet — Plan

A Nextcloud app for vehicle fleet management. Backend: Nextcloud DB, user system, calendar,
notifications, mail. Frontend: Nextcloud web UI. An Android client comes later and reuses the same
API.

## Documents

| Document | What is in it |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Layers, data model, Nextcloud integration, the reminder engine, the maths |
| [docs/features.md](docs/features.md) | Prior art, the backlog, logbook mode |
| [docs/contributing.md](docs/contributing.md) | How someone adds a country, an importer or a report — by merge request |
| [docs/ui.md](docs/ui.md) | Screens, the entry flow, English and German |
| [docs/security.md](docs/security.md) | Threat model, and the rules that follow from it |
| [docs/legal.md](docs/legal.md) | Licence, trademarks, data protection |
| [docs/development.md](docs/development.md) | Testing, and the local WSL/Docker setup |
| [design/](design/) | Mockups of the three screens |

## Scope

**In scope (v1):** vehicles, odometer, trips (Fahrtenbuch), fuel and charging, service/repair
records, due-date reminders with notification + mail + calendar, cost overview, CSV export.

**Later:** shared/pool vehicles with roles, bookings, handover protocols, import from other tools,
Android app.

**Out of scope:** telematics/OBD hardware, GPS tracking, route planning, OCR of receipts.

**Germany is the first jurisdiction, not the only one.** Every country-specific rule — logbook
requirements, inspection cadence, mileage rates, units — lives in one directory per country that
someone else can add by merge request ([contributing](docs/contributing.md)).

## Milestones

| # | Content | Done when |
|---|---|---|
| M0 | Repo skeleton, `info.xml`, l10n scaffold (en/de/de_DE), REUSE headers, CI (psalm, php-cs-fixer, phpunit, npm lint/build), dev docker ([dev environment](docs/development.md#local-dev-environment)) | App installs and shows an empty page on NC 34 |
| M1 | One vertical slice first (migration → mapper → OCS → Vue), then vehicles CRUD, odometer readings, access layer, personal settings. **Three tables, not eleven** — a migration is easy to add and hard to withdraw | A vehicle can be created, its km updated, and a foreign user is denied |
| M2 | Trips, derived odometer, list and filters, `de` logbook ruleset (append-only + `fleet_audit`) | Adding a trip moves the vehicle's km; an edited locked trip leaves a revision; the German rules sit in `lib/Jurisdiction/De/`, not scattered through services |
| M3 | Fuel/charging, expenses, the [consumption and cost maths](docs/architecture.md#numbers-consumption-cost-emissions) | Per-vehicle cost per km is correct |
| M4 | Reminder engine, TimedJob, notifications, mail digest, calendar sync | HU/AU due in 4 weeks reaches the phone |
| M5 | Service/repair records, documents, dashboard widget, search, CSV export, **second jurisdiction (UK)** | Feature-complete v1, app store release — and miles/mpg/MOT work without touching the core |
| M6 | Sharing UI, fleet view, bookings, handover | Multi-driver pool works |
| M7 | OCS API hardening, `?since=` delta endpoint, versioning, app passwords, API docs | Android client can be built against it |

M0–M5 is the releasable product. Ship it before starting M6.

## Decisions taken

All dated 2026-09-03.

1. **Share-aware from M1.** Access control is one check in the data layer from the first commit;
   the sharing UI still ships in M6. Retrofitting it would touch every controller and mapper.
2. **Nextcloud 31–34.** Covers older installs; we pay for it with some Vue compatibility shims.
3. **Fahrtenbuch mode in v1 (M2).** Immutability is a property of the data model — an audit trail
   added later cannot reconstruct history that was never recorded.
4. **Calendar: write into a user-picked calendar.** Real events, real CalDAV alarms. A read-only
   `ICalendarProvider` view stays a possible addition, not a v1 obligation.

From the plan review:

5. **Sync columns from M1** — `uuid`, `updated_at`, `deleted_at` on every table
   ([data model](docs/architecture.md#data-model)). Three columns now against a migration plus a
   conflict model in M7.
6. **Odometer readings are ordered by date, and a lower value is a flag, not an error**
   ([data model](docs/architecture.md#data-model)). This is the rule that decides whether late
   entries and cluster swaps corrupt the history.
7. **`value` carries km or engine hours** ([data model](docs/architecture.md#data-model)). One
   nullable column now buys trailers, tractors and generators later; adding it afterwards rewrites
   every query.
8. **The calendar is a one-way projection**
   ([reminder engine](docs/architecture.md#reminder-engine)). Two-way sync with CalDAV is a trap we
   are not walking into.
9. **File downloads are proxied through the app**
   ([Nextcloud integration](docs/architecture.md#nextcloud-integration)), so vehicle sharing and
   file sharing cannot drift apart.
10. **No plugin system; countries arrive as merge requests** ([contributing](docs/contributing.md)).
    One directory per country, the core knows none of them, and review is the gate. Germany is the
    first, the UK is the proof, both ship before v1.
11. **Rates are time-versioned** ([contributing](docs/contributing.md)). A report for a past year
    uses that year's rate.
12. **Canonical units in the database, local units on screen**
    ([contributing](docs/contributing.md)). Even for a UK vehicle.

## Still open

1. **Own `fleet_shares` table, or Nextcloud's `OCP\Share\IManager`?** The share manager brings the
   dialog users already know, link shares, expiry and circles; our own table is simpler and fully
   under our control. It decides whether a core table exists, so it is a spike before M1, not a
   migration after M6. See [Nextcloud integration](docs/architecture.md#nextcloud-integration).
2. **Does `ICreateFromString` upsert on a repeated UID?** Decides whether a changed reminder updates
   its calendar event or has to cancel and recreate. Spike at M4.

## Risks

- **Calendar write API is thin.** Update/delete semantics unclear — see the M4 spike. Fallback: the
  app's own notifications carry the feature; the calendar is a bonus.
- **Mail depends on server SMTP.** Not every instance has it. Notifications must stand alone.
- **App store compatibility churn.** Nextcloud majors break APIs twice a year; keep the OCP surface
  small and pin CI to the supported range.
- **Entry friction kills logbooks.** If adding a trip takes more than a few seconds, the data rots.
  Treat the [entry sheet](docs/ui.md#the-entry-sheet-in-detail) as a feature, not polish — and the
  QR sticker ([backlog](docs/features.md#feature-backlog)) as its cheapest fix.
- **Retroactive entries are the norm, not the exception.** People log trips days later. Every figure
  must be date-ordered and recomputable from the records; nothing may be incremented in place
  ([data model](docs/architecture.md#data-model)).
- **Offline sync cannot be retrofitted cheaply.** Without `uuid`, `updated_at` and tombstones from
  M1, the Android client in M7 costs a data migration plus a conflict model. Decided in the
  [data model](docs/architecture.md#data-model); the cost of being wrong is asymmetric.
- **Seven interfaces before two implementations.** They are internal seams now, not a promised
  API, so the cost of getting one wrong is a refactor rather than a breaking change. Still: only
  jurisdiction and importer start with two implementations; the rest earn their shape when a second
  one turns up.
- **We maintain every country we merge.** A jurisdiction whose maintainer disappears becomes a
  wrong tax report with our name on it. `CODEOWNERS`, the country test kit, and the willingness to
  mark one experimental are the whole defence ([contributing](docs/contributing.md)).
- **Eleven tables before one line of code.** The schema in
  [data model](docs/architecture.md#data-model) is a design, not a migration plan: M1 ships
  vehicles, odometer readings and access, and every later table arrives with the feature that needs
  it. Columns written speculatively are columns nobody dares remove.
- **Scope.** M0–M5 is a lot for a side project. If time runs out, cut the sharing UI — never the
  reminder engine. That is the one thing nothing else in
  [the prior art](docs/features.md#what-existing-tools-teach-us) offers.
