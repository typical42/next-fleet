# NextFleet — Plan

A Nextcloud app for keeping a vehicle logbook. Backend: Nextcloud DB, user system,
notifications, mail. Frontend: Nextcloud web UI. An Android client comes later, built against the
[OCS API](docs/api.md) over the same services.

## Documents

| Document | What is in it |
|---|---|
| [CONTEXT.md](CONTEXT.md) | The glossary. Read it first — the code, the UI and these docs use these words and no others |
| [docs/adr/](docs/adr/) | Decisions that are hard to reverse, each with its reasoning. Where a document and an ADR disagree, the ADR wins |
| [docs/architecture.md](docs/architecture.md) | Layers, data model, Nextcloud integration, the reminder engine, the maths |
| [docs/api.md](docs/api.md) | The OCS API a client is built against: signing in, answers, tokens, sync, what v1 promises |
| [docs/features.md](docs/features.md) | Prior art, the backlog, Logbook Mode |
| [docs/contributing.md](docs/contributing.md) | How someone adds a jurisdiction, an importer or a report — by merge request |
| [docs/ui.md](docs/ui.md) | Screens, the entry flow, English and German |
| [docs/security.md](docs/security.md) | Threat model, and the rules that follow from it |
| [docs/legal.md](docs/legal.md) | Licence, trademarks, data protection |
| [docs/development.md](docs/development.md) | Testing, CI, and the local WSL/Docker setup |
| [docs/user/](docs/user/README.md) | The user manual |
| [docs/admin/](docs/admin/README.md) | For an admin: install, upgrade, data and backups, the `occ` commands, troubleshooting |
| [docs/developer/](docs/developer/README.md) | The entry point for developers: dev server, checks, code layout, how to send a change |
| [design/](design/) | Mockups of the three screens |

## Who it is for

A freelancer or small business with one to five vehicles
([ADR 0004](docs/adr/0004-freelancers-not-fleets.md)). The private owner is already served by two
Nextcloud apps; the company fleet manager brings roles, pool bookings and employee-data law, and
waits behind v1.

That choice decides the rest of this document. The features that justify switching apps are the
tax-grade logbook, gap detection and the mileage claim. v1 deferred everything multi-driver, and
M6–M7 added only what two or three people sharing the owner's car need.

## Scope

**In scope (v1 = M0–M5):** vehicles, odometer, trips, fuel and charging, maintenance records,
expenses, reminders by date or km with notification + mail, cost overview, CSV export,
HTML/print reports.

**Built after v1 (M6–M9), not released:** access for others with roles, bookings, handover
protocols, the receipt inbox, the OCS API, import from LubeLogger and Spritmonitor CSV exports.

**Later:** the Android client, and the rest of the [backlog](docs/features.md#feature-backlog).

**Out of scope:** telematics/OBD hardware, GPS tracking, route planning, OCR of receipts.

**Germany is the first jurisdiction, not the only one.** v1 ships `de` and a generic profile;
the UK exists as a test jurisdiction that breaks unit assumptions before release
([ADR 0002](docs/adr/0002-uk-is-a-test-jurisdiction.md)). Every country-specific rule lives in one
directory that someone else can add by merge request ([contributing](docs/contributing.md)).

## Milestones

| # | Content | Done when |
|---|---|---|
| M0 | Repo skeleton, `info.xml`, l10n scaffold (en/de/de_DE), REUSE headers, CI ([matrix](docs/development.md#testing)), dev docker ([dev environment](docs/development.md#local-dev-environment)). **Gate: one frontend bundle builds and runs against NC 31 and NC 34** | App installs and shows an empty page on both ends of the supported range |
| M1 | One vertical slice first (migration → mapper → route → Vue), then vehicles, odometer readings, `fleet_access` and `VehicleAccess::may`, optimistic concurrency, jurisdiction defaulting, personal settings. **Three tables, not eleven** — a migration is easy to add and hard to withdraw | A vehicle can be created, its km updated, a stale write is rejected, and a foreign user is denied |
| M2 | Trips, derived odometer, timeline, filters, gap detection, Logbook Mode with the `de` ruleset (append-only + `fleet_audit`) | Adding a trip moves the vehicle's km; a voided locked trip survives in the export; the German rules sit in `lib/Jurisdiction/De/`, not scattered through services |
| M3 | Energy entries, maintenance records, expenses, VAT, and the [consumption and cost maths](docs/architecture.md#numbers-consumption-cost-emissions) | Per-vehicle cost per 100 km is correct, and a plug-in hybrid shows two consumption figures |
| M4 | Reminder engine, TimedJob, notifications, mail digest, recipients, HU/AU from the sticker, closing a reminder by maintenance record. No calendar (decision 16) | HU/AU due in 4 weeks reaches the phone |
| M5 | Documents, the Costs screen with a CO₂ estimate, CSV export, the mileage claim, a plain logbook for the generic jurisdiction, dashboard widget, search, QR sticker | Feature-complete v1 as 0.2.0, not released |
| M6 | Access: the owner grants a user or a group viewer, driver or manager; a driver logs their own entries; screens follow the caller's role | A partner or an employee logs trips in the owner's car and sees only what their role allows |
| M7 | The pool and the inbox: a grantee who may log books the vehicle, and the server refuses a second booking over it; check-out and check-in with the counter and the tank, the check-in prefilling the trip; who has the car on the overview; a receipt folder the mobile app's auto-upload fills, attached in two taps. No calendar (decision 16) | Two or three people share a car without phoning first, and a photographed receipt reaches its entry without the file picker |
| M8 | The OCS API under `/api/v1`, signed in with an app password; a `sync` delta endpoint with tombstones; a generated `openapi.json` checked in CI; API docs | An Android client can be built against it |
| M9 | Import: a LubeLogger or Spritmonitor CSV export picked from the caller's own Files, previewed with its columns, open questions, duplicates and unreadable rows, imported in one transaction through the entry services, undone as a whole; `occ nextfleet:import` for scripts. Never trips ([architecture](docs/architecture.md#import)) | Someone switching from LubeLogger or Spritmonitor brings their history along, and can take it back straight away |
| M10 | Release-ready, nothing released: the importers checked against their formats' sources, `source_uuid` on Readings and `estimate` on synced reminders, export, sync and import logged alike, M5's small gaps closed, a security review of M6–M9, the package installed fresh and over 0.2.0 by [`tools/upgrade-check.sh`](tools/upgrade-check.sh), every suite green on NC 31 and NC 34 in one pass, and the [release checklist](docs/development.md#release) | The maintainer only commits, signs, tags and uploads |
| M11 | Review fixes, nothing released: a security contact through GitHub's private vulnerability reporting; a linear, capped CSV reader; erasure pseudonyms no account can take; recipients that follow the sharing rules; bookings that keep an overdue car accounted for; papers served only from their attacher's own Files; names shown as typed; a contract test that catches narrowing; an upgrade check that proves every table and index; a first-release CHANGELOG and an executable [release checklist](docs/development.md#release) | 0.3.0 is round and safe to use |
| M12 | Round-two review fixes, nothing released: the trip audit follows the trip's period; late is decided at export; trip arithmetic and counter resets hold; a mileage claim the Finanzamt accepts; an erased owner's vehicles close and `occ nextfleet:transfer` hands a pool over; a personal data export; a bound on every input; reminders that ring again after an edit and survive a failed send; erasures and group revokes that finish; retried creates that do not duplicate; migration 7's indexes and bounded queries; no `IN` list over 1 000, tried on Oracle; Node 24 and a stricter CI; the UX review's wording | 0.3.1, the first release, is round and safe to use |
| M13 | Loose ends, nothing released: a file deleted straight after its download, guarded by the API suite; Oracle green on five fresh stacks, its API suite and an upgrade check from 0.2.0; the personal data export tried on a real *user_migration* and tested weekly; a cron runner for NC 31's dev server | 0.3.1 goes out with no known open question |
| M14 | Admin commands, nothing released: `occ nextfleet:` vehicles, check, recompute, access, audit, pending, reminders, mail-test and restore, each a thin shell over a service, and [docs/admin/](docs/admin/README.md) on where the data lives and what keeps it safe | An admin finds, checks, repairs and undoes without SQL |
| Next | Not planned yet: the [feature backlog](docs/features.md#feature-backlog), which no milestone has claimed | — |

**M0–M14 are built, and none is released.** M0–M5 are 0.2.0, M6–M11 are 0.3.0, M12 to M14 are
0.3.1.
0.3.1 is the first release; neither 0.2.0 nor 0.3.0 goes out alone
([release](docs/development.md#release), step 2).

**v1 is 0.2.0, and it is not released for now** (decided 2026-09-30). M5 is v1 by scope; the
version tracks maturity, and a first release has none yet, so it stays on the 0.x line. Until a
release, later milestones collect under the CHANGELOG's open section — `## 0.3.1 — not released`
since M12's migration raised the version, because Nextcloud runs a migration only after a rise.

**M5 is v1 by scope, and M6 builds on it.** Maintenance records sit in M3 rather than M5 because
they write odometer readings and close reminders — building the reminder engine against a record
type that does not exist yet is the wrong order.

## Decisions taken

Dated 2026-09-03, revised 2026-09-04, 2026-09-23 and 2026-10-02. Where an ADR exists it carries the reasoning; this list is
the index.

**Shape of the product**

1. **A freelancer, not a fleet** — [ADR 0004](docs/adr/0004-freelancers-not-fleets.md).
2. **Nextcloud 31–34.** Covers older installs; we pay for it with Vue compatibility shims and with
   the M0 gate above, which is where that price becomes visible. `@nextcloud/vue` 9 spans the
   range, so the 31 floor held.
3. **Logbook Mode in v1 (M2).** Immutability is a property of the data model — an audit trail added
   later cannot reconstruct history that was never recorded.
4. **No plugin system; jurisdictions arrive as merge requests**
   ([contributing](docs/contributing.md)). One directory each, the core knows none of them, review
   is the gate.
5. **The OCS API v1 is the public contract** —
   [ADR 0009](docs/adr/0009-the-ocs-api-v1-is-the-public-contract.md), superseding ADR 0006. Two
   doors, one rule: the web UI keeps its internal routes, and both call the same services.
6. **Reports are HTML with a print stylesheet** — [ADR 0005](docs/adr/0005-no-pdf-library.md).

**Data**

7. **Access is our own table, checked in one place** —
   [ADR 0001](docs/adr/0001-own-access-table.md). The *Access* section of the Edit vehicle
   sheet (M6) is its screen.
8. **`uuid`, `updated_at`, `deleted_at`, `created_by` on every table**
   ([data model](docs/architecture.md#data-model)). `uuid` is identity, `updated_at` powers
   optimistic concurrency and sync, `deleted_at` gives undo and gives GDPR erasure
   something explicit to purge.
9. **Odometer readings are ordered by date, and a lower value is a flag, not an error**
   ([odometer rules](docs/architecture.md#odometer-rules)).
10. **Observed readings beat derived ones** ([odometer rules](docs/architecture.md#odometer-rules)).
    A distance-only trip computes its reading from the preceding one and is marked as such.
11. **`value` carries km or engine hours** ([data model](docs/architecture.md#data-model)). One
    nullable column now buys trailers, tractors and generators later.
12. **Time is a UTC instant plus its originating offset** —
    [ADR 0007](docs/adr/0007-time-is-an-instant-plus-an-offset.md).
13. **Canonical units and no cross-currency arithmetic**
    ([contributing](docs/contributing.md)). Kilometres, millilitres, cents, UTC in the database; a
    report that spans currencies or units shows sections, never a sum.

**Behaviour**

14. **Switching Logbook Mode on locks nothing retroactively** —
    [ADR 0003](docs/adr/0003-logbook-mode-does-not-lock-the-past.md). A delete under it voids the
    record rather than removing it.
15. **Gaps are closed one at a time, as private trips only**
    ([logbook mode](docs/features.md#logbook-mode)).
16. **No calendar** ([Nextcloud integration](docs/architecture.md#nextcloud-integration)). A
    reminder whose event cannot be changed or removed keeps ringing after it is done.
17. **Dismissing a reminder skips one occurrence; deleting it ends the recurrence**
    ([reminder engine](docs/architecture.md#reminder-engine)).
18. **A reminder recurs from the work actually done**, not from its planned due
    ([reminder engine](docs/architecture.md#reminder-engine), rule 4). An oil change done late
    moves the next one late too.
19. **File downloads are proxied through the app**
    ([Nextcloud integration](docs/architecture.md#nextcloud-integration)), and a document is
    resolved by `file_id`, never by path.
20. **Rates are time-versioned** ([contributing](docs/contributing.md)). A report for a past year
    uses that year's rate.
21. **Erasing a driver pseudonymises** —
    [ADR 0008](docs/adr/0008-erasing-a-driver-pseudonymises.md). Retention is opt-in and off by
    default ([data protection](docs/legal.md)).

## Risks

- **Mail depends on server SMTP.** Not every instance has it. Notifications must stand alone, and
  do: each channel has its own receipts, so a refused mail leaves the notification sent
  ([reminder engine](docs/architecture.md#reminder-engine), rule 7).
- **Reminder undo is lossy.** The schema does not keep a planned due, so withdrawing the maintenance
  record that closed a reminder reopens it due at the record's own day and km
  ([reminder engine](docs/architecture.md#reminder-engine)). Keeping it would take a column, and the
  first release adds no more: its last migration adds indexes only.
- **App store compatibility churn.** Nextcloud majors break APIs twice a year; four supported
  majors is a deliberate cost, paid at M0 and again at every release.
- **Entry friction kills logbooks.** If adding a trip takes more than a few seconds, the data rots.
  Treat the [entry sheet](docs/ui.md#the-entry-sheet-in-detail) as a feature, not polish — and the
  QR sticker ([ui](docs/ui.md#the-qr-shortcut)) as its cheapest fix.
- **Retroactive entries are the norm, not the exception.** People log trips days later. Every figure
  must be date-ordered and recomputable from the records; nothing may be incremented in place
  ([data model](docs/architecture.md#data-model)).
- **Eight interfaces before two implementations.** They are internal seams now, not a promised API,
  so the cost of getting one wrong is a refactor rather than a breaking change. Only jurisdiction
  and, since M9, importer have two implementations; the rest earn their shape when a second one
  turns up.
- **We maintain every jurisdiction we merge.** One whose maintainer disappears becomes a wrong tax
  report with our name on it. `CODEOWNERS`, the country test kit, and the willingness to mark one
  experimental are the whole defence ([contributing](docs/contributing.md)).
- **A schema designed before one line of code.** The [data model](docs/architecture.md#data-model)
  was a design, not a migration plan: M1 shipped vehicles, odometer readings and access, and each
  later table arrived with the feature that needed it, thirteen by M7. A new one still waits for its
  feature. Columns written speculatively are columns nobody dares remove.
- **Scope.** The backlog is longer than a side project can finish. Whatever is cut, never the
  reminder engine. That is the one thing nothing else in
  [the prior art](docs/features.md#what-existing-tools-teach-us) offers.
