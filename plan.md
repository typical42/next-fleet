# NextFleet — Plan

A Nextcloud app for keeping a vehicle logbook. Backend: Nextcloud DB, user system, calendar,
notifications, mail. Frontend: Nextcloud web UI. An Android client comes later and reuses the same
services.

## Documents

| Document | What is in it |
|---|---|
| [CONTEXT.md](CONTEXT.md) | The glossary. Read it first — the code, the UI and these docs use these words and no others |
| [docs/adr/](docs/adr/) | Decisions that are hard to reverse, each with its reasoning. Where a document and an ADR disagree, the ADR wins |
| [docs/architecture.md](docs/architecture.md) | Layers, data model, Nextcloud integration, the reminder engine, the maths |
| [docs/features.md](docs/features.md) | Prior art, the backlog, Logbook Mode |
| [docs/contributing.md](docs/contributing.md) | How someone adds a jurisdiction, an importer or a report — by merge request |
| [docs/ui.md](docs/ui.md) | Screens, the entry flow, English and German |
| [docs/security.md](docs/security.md) | Threat model, and the rules that follow from it |
| [docs/legal.md](docs/legal.md) | Licence, trademarks, data protection |
| [docs/development.md](docs/development.md) | Testing, CI, and the local WSL/Docker setup |
| [design/](design/) | Mockups of the three screens |

## Who it is for

A freelancer or small business with one to five vehicles
([ADR 0004](docs/adr/0004-freelancers-not-fleets.md)). The private owner is already served by two
Nextcloud apps; the company fleet manager brings roles, pool bookings and employee-data law, and
waits behind v1.

That choice decides the rest of this document. The features that justify switching apps are the
tax-grade logbook, gap detection and the mileage claim — everything multi-driver is deferred, and
what remains is sized for one person to finish.

## Scope

**In scope (v1 = M0–M5):** vehicles, odometer, trips, fuel and charging, maintenance records,
expenses, due-date reminders with notification + mail + calendar, cost overview, CSV export,
HTML/print reports.

**Later:** shared vehicles with roles, bookings, handover protocols, import from other tools, the
OCS API and the Android client.

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
| M3 | Energy entries, maintenance records, expenses, VAT, and the [consumption and cost maths](docs/architecture.md#numbers-consumption-cost-emissions) | Per-vehicle cost per km is correct, and a plug-in hybrid shows two consumption figures |
| M4 | Reminder engine, TimedJob, notifications, mail digest, calendar sync | HU/AU due in 4 weeks reaches the phone |
| M5 | Documents, dashboard widget, search, CSV export, HTML/print reports, generic jurisdiction | Feature-complete v1, app store release |
| M6+ | Sharing UI, fleet view, bookings, handover. Then the OCS API, `?since=` delta endpoint, app passwords, API docs | Multi-driver pool works; an Android client can be built against it |

**M5 is v1 and it ships before M6 starts.** Maintenance records sit in M3 rather than M5 because
they write odometer readings and close reminders — building the reminder engine against a record
type that does not exist yet is the wrong order.

## Decisions taken

Dated 2026-09-03, revised 2026-09-04. Where an ADR exists it carries the reasoning; this list is
the index.

**Shape of the product**

1. **A freelancer, not a fleet** — [ADR 0004](docs/adr/0004-freelancers-not-fleets.md).
2. **Nextcloud 31–34.** Covers older installs; we pay for it with Vue compatibility shims and with
   the M0 gate above, which is where that price becomes visible.
3. **Logbook Mode in v1 (M2).** Immutability is a property of the data model — an audit trail added
   later cannot reconstruct history that was never recorded.
4. **No plugin system; jurisdictions arrive as merge requests**
   ([contributing](docs/contributing.md)). One directory each, the core knows none of them, review
   is the gate.
5. **v1 has one internal API** — [ADR 0006](docs/adr/0006-one-api-surface-in-v1.md).
6. **Reports are HTML with a print stylesheet** — [ADR 0005](docs/adr/0005-no-pdf-library.md).

**Data**

7. **Access is our own table, checked in one place** —
   [ADR 0001](docs/adr/0001-own-access-table.md). The sharing UI still waits for M6; the check does
   not.
8. **`uuid`, `updated_at`, `deleted_at`, `created_by` on every table**
   ([data model](docs/architecture.md#data-model)). `uuid` is identity, `updated_at` powers
   optimistic concurrency today and sync later, `deleted_at` gives undo and gives GDPR erasure
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
16. **The calendar is a one-way projection**
    ([reminder engine](docs/architecture.md#reminder-engine)). Two-way sync with CalDAV is a trap we
    are not walking into.
17. **Dismissing a reminder skips one occurrence; deleting it ends the recurrence**
    ([reminder engine](docs/architecture.md#reminder-engine)).
18. **File downloads are proxied through the app**
    ([Nextcloud integration](docs/architecture.md#nextcloud-integration)), and a document is
    resolved by `file_id`, never by path.
19. **Rates are time-versioned** ([contributing](docs/contributing.md)). A report for a past year
    uses that year's rate.
20. **Erasing a driver pseudonymises** —
    [ADR 0008](docs/adr/0008-erasing-a-driver-pseudonymises.md). Retention is opt-in and off by
    default ([data protection](docs/legal.md)).

## Still open

1. **Does `ICreateFromString` upsert on a repeated UID?** Decides whether a changed reminder updates
   its calendar event or has to cancel and recreate. Spike at M4.
2. **Which `@nextcloud/vue` major spans NC 31 to 34?** The M0 gate answers it. If none does, the
   31 floor is what moves — not the UI.

## Risks

- **Calendar write API is thin.** Update/delete semantics unclear — see the M4 spike. Fallback: the
  app's own notifications carry the feature; the calendar is a bonus.
- **Mail depends on server SMTP.** Not every instance has it. Notifications must stand alone.
- **App store compatibility churn.** Nextcloud majors break APIs twice a year; four supported
  majors is a deliberate cost, paid at M0 and again at every release.
- **Entry friction kills logbooks.** If adding a trip takes more than a few seconds, the data rots.
  Treat the [entry sheet](docs/ui.md#the-entry-sheet-in-detail) as a feature, not polish — and the
  QR sticker ([backlog](docs/features.md#feature-backlog)) as its cheapest fix.
- **Retroactive entries are the norm, not the exception.** People log trips days later. Every figure
  must be date-ordered and recomputable from the records; nothing may be incremented in place
  ([data model](docs/architecture.md#data-model)).
- **Seven interfaces before two implementations.** They are internal seams now, not a promised API,
  so the cost of getting one wrong is a refactor rather than a breaking change. Only jurisdiction
  and importer start with two implementations; the rest earn their shape when a second one turns up.
- **We maintain every jurisdiction we merge.** One whose maintainer disappears becomes a wrong tax
  report with our name on it. `CODEOWNERS`, the country test kit, and the willingness to mark one
  experimental are the whole defence ([contributing](docs/contributing.md)).
- **Eleven tables before one line of code.** The schema in
  [data model](docs/architecture.md#data-model) is a design, not a migration plan: M1 ships
  vehicles, odometer readings and access, and every later table arrives with the feature that needs
  it. Columns written speculatively are columns nobody dares remove.
- **Scope.** M0–M5 is still a lot for a side project. If time runs out, cut documents and the
  dashboard widget — never the reminder engine. That is the one thing nothing else in
  [the prior art](docs/features.md#what-existing-tools-teach-us) offers.
