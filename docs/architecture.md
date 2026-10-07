# Architecture

Part of the [NextFleet plan](../plan.md). Terms are defined in [CONTEXT.md](../CONTEXT.md).

## Stack and layers

Standard Nextcloud app, no external services.

- **PHP:** the intersection of what NC 31–34 support — confirmed at M0, let CI decide. Public `OCP`
  API only (app store rule). Layers: Controller → Service → QBMapper → Entity. Migrations via
  `OCP\Migration\SimpleMigrationStep`.
- **Vue 3 + Vite** (`@nextcloud/vite-config`, `@nextcloud/vue`), Pinia for state. **One bundle for
  the whole supported range** — proving that is the M0 gate ([plan](../plan.md#milestones)). One
  per *page*, though: the app's block on the user's settings page is a second entry, because
  mounting the fleet inside a settings section would load every view for one dropdown.
- **Two doors, one rule.** The web UI uses an internal route set; the versioned OCS API twins it
  under `/api/v1` and is the public contract
  ([ADR 0009](adr/0009-the-ocs-api-v1-is-the-public-contract.md), [api.md](api.md)). Both call the
  same service with the same arguments. Controllers stay thin and every rule lives in a service,
  which is what makes the second door cheap. The routes are in
  `appinfo/routes.php`; the JSON keys are the column names, so what a client reads back is what it
  may send — and what it may *not* send is stated once, in the service.
- **Target:** Nextcloud 31 → 34 (`min-version`/`max-version` in `appinfo/info.xml`). App id
  `nextfleet` (lowercase, matches folder name; must not contain "Nextcloud"). Licence
  AGPL-3.0-or-later.

## Data model

Odometer is **not** a field you edit. It is derived: every Entry that knows a mileage writes a
Reading, and the vehicle carries the latest value as a cache. That keeps trips, fill-ups and
maintenance from drifting apart.

```mermaid
erDiagram
    VEHICLES ||--o{ ODO_READINGS : "has — odo_value caches the newest"
    VEHICLES ||--o{ TRIPS : has
    VEHICLES ||--o{ ENERGY : has
    VEHICLES ||--o{ MAINTENANCE : has
    VEHICLES ||--o{ EXPENSES : has
    VEHICLES ||--o{ REMINDERS : has
    VEHICLES ||--o{ DOCUMENTS : has
    VEHICLES ||--o{ ACCESS : "access through"
    VEHICLES ||--o{ BOOKINGS : "booked through"
    TRIPS ||--|| ODO_READINGS : writes
    ENERGY ||--o{ ODO_READINGS : "writes, one per counter"
    MAINTENANCE ||--o{ ODO_READINGS : "writes, one per counter"
    ENERGY |o--o{ DOCUMENTS : "papers"
    MAINTENANCE |o--o{ DOCUMENTS : "papers"
    EXPENSES |o--o{ DOCUMENTS : "papers"
    BOOKINGS |o--o{ DOCUMENTS : "papers"
    MAINTENANCE }o--o| REMINDERS : "closes, then recurs"
    REMINDERS ||--o{ REMINDER_RECEIPTS : "one row per point, channel and recipient"
    VEHICLES ||--o{ REMINDER_RECIPIENTS : "reminders go to"
    BOOKINGS ||--o| TRIPS : becomes
    TRIPS ||--o{ AUDIT : "revisions, Logbook Mode only"
    VEHICLES ||--o{ AUDIT : "mode flips, plate, country, currency, type"
```

Tables (prefix `fleet_`; Nextcloud prepends `oc_`, so names stay under 27 characters):

| Table | Key columns |
|---|---|
| `fleet_vehicles` | `user_id` (owner), `plate`, `manufacturer`, `model`, `vehicle_type`, `engine`, `energy_types`, `tank_ml`, `battery_wh`, `first_reg`, `vin`, `odo_value` (cache), `odo_unit` (km/h), `second_unit` (null/h), `second_value` (cache), `purchase_price`, `residual_est`, `currency`, `jurisdiction`, `logbook_mode`, `lifecycle`, `disposed_at`, `folder_file_id`, `retention_months`, `color`, `notes`, `reminder_mail` (off/daily/weekly/monthly, default weekly) |
| `fleet_odo_readings` | `vehicle_id`, `read_at`, `read_at_off`, `value`, `kind` (reading/reset/correction), `origin` (observed/derived), `flagged`, `source_type` (manual/trip/energy/maintenance), `source_id`, `counter` (main/second, null reads as main) |
| `fleet_trips` | `vehicle_id`, `started_at`, `started_at_off`, `ended_at`, `ended_at_off`, `start_odo` (nullable claim), `end_odo`, `distance`, `from_label`, `to_label`, `purpose`, `partner`, `category` (business/private/commute), `reconciled` |
| `fleet_energy` | `vehicle_id`, `filled_at`, `filled_at_off`, `odo`, `second_odo`, `energy` (petrol/diesel/lpg/cng/electric), `amount` (ml or Wh, per `energy`), `unit_price` (tenths of a cent per l or kWh), `total`, `vat_rate`, `full_tank`, `missed_previous`, `station`, `is_dc`, `location_kind` (home/public) |
| `fleet_maintenance` | `vehicle_id`, `type` (service/repair/inspection/tyres/upgrade), `done_at`, `done_at_off`, `odo`, `second_odo`, `title`, `vendor`, `cost`, `vat_rate`, `notes`, `reminder_id` (the reminder it closed) |
| `fleet_expenses` | `vehicle_id`, `spent_at`, `spent_at_off`, `category` (insurance/tax/toll/parking/fine/lease/other), `amount`, `vat_rate`, `notes` |
| `fleet_reminders` | `vehicle_id`, `template_key` (nullable — seeded templates translate, user titles do not), `title`, `mode` (date/odo/either), `due_date` (a plain date), `due_odo`, `lead_odo`, `warn_month_before`, `warn_month_start`, `warn_due_date`, `recur_months`, `recur_odo`, `state`, `snoozed_until`, `occurrence` (counts up with each recurrence) |
| `fleet_reminder_receipts` | `reminder_id`, `occurrence`, `point`, `channel` (app/mail), `user_id`, `sent_at` — unique per send |
| `fleet_reminder_recipients` | `vehicle_id`, `user_id` — unique per vehicle; starts as the owner |
| `fleet_documents` | `vehicle_id`, `file_id`, `kind` (registration/insurance/manual/receipt/photo), `linked_type`, `linked_id` |
| `fleet_audit` | `entity`, `entity_id`, `diff_json` — written for a trip under Logbook Mode or set off inside a period it was on ([logbook mode](features.md#logbook-mode)), and for every mode flip and every change of a vehicle's `plate`, `jurisdiction`, `currency` or `vehicle_type`, mode or not |
| `fleet_access` | `vehicle_id`, `grantee`, `grantee_type` (user/group), `role` (manager/driver/viewer) — one live row per grantee; a revoke soft-deletes it |
| `fleet_bookings` | `vehicle_id`, `user_id` (the booker), `starts_at`, `starts_at_off`, `ends_at`, `ends_at_off`, `purpose`, `state` (booked/out/returned/cancelled), `out_at`, `out_at_off`, `out_odo`, `out_level`, `out_notes`, `in_at`, `in_at_off`, `in_odo`, `in_level`, `in_notes`, `trip_id` (the trip logged from it) |

Money as integer cents, distances as integer km, volumes as integer millilitres, energy as integer
watt-hours. No floats. A unit price is the one exception to cents: pumps price to a tenth of a cent,
so `unit_price` counts tenths. Money also needs a `currency`, and business users need `vat_rate` — a report
that mixes net and gross is useless for accounting. **The currency stays once an amount is recorded
in it** — a priced fill-up, a costed record or an expense, voided ones included: a new code would
relabel every amount without converting one. The edit is a 400 with `reason: currency_in_use` through
either door, which the vehicle sheet words. Only a code is frozen: a vehicle with no currency, or a
sign written before the sheet checked it, takes a code over its amounts, because naming one labels
them, and it is what the import asks for.

**`vat_rate` is nullable and null means "not stated"**, never zero. A receipt without a VAT line and
a genuinely zero-rated cost are different facts, and a report that conflates them is the mixed
net/gross failure by another route. So 0 is a rate like any other, and an expense of a category the
jurisdiction charges no VAT on (`IRateProvider::vatFreeCategories()`) is prefilled with it.

**No column is named after a unit it might not hold.** The odometer columns are `odo`, not `km`,
because a tractor counts hours; `fleet_energy.amount` means millilitres or watt-hours and says which
in `energy`, because a plug-in hybrid has both kinds of row. The table is `fleet_energy` and not
`fleet_fuel` for exactly the same reason.

**`engine` classifies, `energy_types` decides.** `engine` (petrol/diesel/lpg/cng/electric/hybrid) is
for display, filtering and emission defaults. `energy_types` is the set the vehicle actually
accepts, and it is authoritative: it decides which options the entry sheet offers and which
consumption figures exist. A plug-in hybrid is `hybrid` / `[petrol, electric]`. A create that sends
no energies takes them from the engine (`VehicleService::ENGINE_ENERGIES`); an update derives
nothing, so an owner can still empty the set. A fill-up of an
energy outside the set is saved and flagged, as one without a total is flagged "no price"; both
flags are computed on read (`EnergyService::flags()`), never stored.

**`tank_ml` and `battery_wh` exist only to flag implausible amounts** — sixty litres into a
forty-five-litre tank. They never constrain a save. The flag is `overfilled`, computed on read like
the others: an electric amount against `battery_wh`, any other against `tank_ml`, and nothing when
the capacity is not on file.

**The plate is a label, the `uuid` is the identity.** Cars get re-registered and plates get
transferred; changing one renames the vehicle, leaves an audit row, and breaks no history. The same
rule governs papers: a document points at its file by `file_id`, never by path
([documents](#documents)).

**`lifecycle` replaces an `active` flag.** `active`, `laid_up` (seasonal or off the road — reminders
pause), `disposed` (sold or scrapped, with `disposed_at` — reminders stop, the vehicle leaves the
fleet list for the overview's *Disposed of* list, records stay for the retention period). A boolean cannot tell a Saisonkennzeichen from a
scrapyard.

**An audit row's author and instant are `created_by` and `created_at`.** It is written in the same
transaction as the change it records and never updated, so a `user_id` and a `changed_at` of its own
would be two more places for one fact to disagree. What kind of change it was — a void, the undo of
one, a late edit, a mode flip, a reconciliation — is in `diff_json` and not in a column: the trail
has to describe tables that do not exist yet. **An undo is a change too**: a trail that stopped at
the void would say "voided" over a trip the export lists as driven, and nothing would say which of
the two happened last.

**`diff_json` is `{"change": …, "fields": {…}}`**, each field a `[before, after]` pair. A creation's
pairs all start at null; a field nobody stated is not in them, because a diff that lists what did not
change buries what did. Anything the change itself carried — that an edit was late, that a trip was
derived rather than observed — is a further key beside those two. The words so far: on a trip
`created`, `edited`, `voided` and `restored` (the last three with `late`, true or false, as the rules
of the day said; the export decides again and does not read it); on a vehicle `switched` for a save
that flipped the mode and `edited` for one that changed a fact without a flip, and `transferred`
with the owners' `user_id` pair, written by `occ:transfer` (below). A Reconciliation
Trip is `created` with `derived: true`. A trip's row also carries `updated_at`, the token the change
left the trip with: a second save in the same second moves the token past the row's `created_at`, and
the export has to tell that from a change that left no row. An edit that changed nothing writes no
row and no trip either, so it moves no token.

There is deliberately **no `hu_due` column**. The next inspection is a reminder produced by an
inspection scheme ([contributing](contributing.md)) — a German date in a core table would be a
second source of truth and a country the core is not supposed to know.

**Every table carries** `uuid`, `created_at`, `updated_at`, `deleted_at` (soft delete),
`created_by`. `uuid` is identity, `updated_at` powers optimistic concurrency
([below](#concurrency)) and [sync](api.md#sync), `deleted_at` gives users a trash and gives GDPR
erasure something explicit to purge. A jurisdiction that needs its own field adds a column in a
reviewed migration ([contributing](contributing.md)) — there is no catch-all JSON blob, because a
field that is not in the schema is a field nobody maintains. That rule is why notification receipts
are a table and not a column. It also carries a `<name>_off` for each of its user-facing instants
([Time](#time)); the table above spells those out only for the tables that exist.

**Nothing purges yet.** Deleting a vehicle stamps its `deleted_at` and touches no other row: its
readings, trips, fill-ups, maintenance records, expenses, reminders, receipts, recipients and
access grants stay as they were, so an undo brings the vehicle back whole. No route, `occ` command
or job removes a row. Two hard deletes exist, and neither is a vehicle delete: taking a recipient
off a list ([Who is told](#reminder-engine)), and a grant whose grantee was gone the moment it
committed (below). Opt-in retention will be the first purge, and must then take the vehicle's child
rows with it.

**Deleting a Nextcloud account pseudonymises, it purges nothing**
([ADR 0008](adr/0008-erasing-a-driver-pseudonymises.md)). `UserDeletedListener` hands the uid to
`ErasureService`, which replaces it with one random `erased:…` pseudonym on every table, in one
transaction: `created_by` everywhere, a vehicle's owner `user_id`, a receipt's and a booking's
`user_id` and a user grant's `grantee`. Ownership and grants are renamed too because Nextcloud lets a deleted uid be
taken again, and the new account must inherit nothing. The `:` is outside the characters a uid may
hold, so no account can take the pseudonym either. Erasures before 0.3.0 wrote `erased-…`; the
repair step `ErasedPseudonyms` renames those on upgrade. One a live account carries stays, named in
the upgrade's output, a `warning` in the log and a notification to every member of `admin`: it may
be a real person, and deleting the account erases its rows. The notice goes once the account does.
`VehicleAccess` answers nothing to a uid starting with `erased:`, owner or grantee: no session
carries one, so a caller that hands one in took it off a row. The account's reminder-list entries
are the rows that go: a deleted account receives nothing. A new table that names an account says so in its
mapper's `accountColumns()` and joins `AccountTables`, which the erasure and the
[personal data export](#personal-data-export) share. A transfer's audit row names both
owners in `diff_json`, so `AuditMapper` renames the uid there too. Most accounts never used the
app, so the erasure first asks each table for one row naming the uid (the large tables index
`created_by`), and touches only the tables that answer. Renamed rows keep their `updated_at`, so every sync starts
over (`SyncEpoch`) - but only when a row was renamed.

**An erased owner's vehicles close** (decided 2026-10-03). Nobody is left who may decide over
them, so in the same transaction each one, held, has every grant revoked with a revoke's rules and
is soft-deleted; one already in the trash loses its grants too, as nobody can restore it. The rows
stay for the retention [legal](legal.md) states. An admin who wants a pool to outlive its owner
runs `occ nextfleet:transfer <vehicle-uuid> <new-owner-uid>` first (`TransferService`): the new
owner must exist; every grant stands but the new owner's own, which owning makes spent; the former
owner keeps a `viewer` grant if their account still exists; the new owner joins the recipients; a
`transferred` audit row records it. It starts every sync over (`SyncEpoch`), because the new
owner's client never read the grants of a vehicle it did not own.

**Deleting a group revokes its grants**, for the same reason: a group made later under the same id
must reach nothing. `GroupDeletedListener` hands the id to `GrantService::forgetGroup`, which
revokes each of the group's grants with a revoke's rules — one transaction per vehicle under its
hold, recipients who no longer reach `view` off the list, the grant's notification withdrawn. Who
was in the group is unreadable by then, so on `BeforeGroupDeletedEvent` the listener has
`GrantService::noteGroup` record which recipients are members, and those count as having seen the
car. A
vehicle in the trash is included, since an undo would bring its grants back. A grantee deleted
while being granted ran its listener before the grant row existed: `GrantService::grant` asks
after the commit, under the vehicle's hold, whether the grantee still exists. If not, it removes the
grant row, prunes recipients as a revoke does, and refuses the grant as it refuses an unknown
grantee. Nobody was told of that grant or saw it, so no row keeps the name. It erases nothing: an external backend (LDAP) briefly out of reach answers "no
such user" for a live account, and an erasure cannot be undone. Erasing stays
`UserDeletedListener`'s alone.

**Both finish, whatever fails.** Nothing asks for an account's erasure or a group's revokes again
once the event has passed, so each is marked pending in app config before it starts
(`Service\Pending`, `pending_erasure_…`/`pending_group_…`, the key a hash of the id) and unmarked
once done. A run a crash or a database error cut short stays marked, and the hourly `PendingJob`
runs it again; `occ nextfleet:pending --finish` runs it at once. Rows already renamed or revoked match no more, so a second run changes only what
the first left; a notification whose withdrawal was lost after the commit is dropped by
`Notifier::prepare()` when read, as its vehicle or grant is gone. A pending erasure whose uid an
account holds again is dropped with a `warning`: that account may be a real person, and an erasure
cannot be undone. A pending group is revoked even if a group of that id exists again: a grant the
owner can give again costs less than a new group inheriting the old one's cars. The mark keeps the
time the first run began, and a grant made or changed after it stays: that is the owner's, for the
new group. Without
`BeforeGroupDeletedEvent` — a backend that never sent it, or the job's run — no members were
noted, so every recipient of the vehicle who no longer reaches `view` comes off, a bookkeeper
included.

Adding a recipient and the job's round write rows naming an account, so each asks again under the
vehicle's hold whether it exists. The erasure holds every vehicle whose list names the account
before it deletes those entries, so one that asked before the account went commits first and its
row is deleted or renamed with the rest. It takes those holds and its own vehicles' in one id
order, so two erasures at once never hold crosswise.

**A booking is a plan, not an Entry.** It writes no Reading and is on no timeline, logbook or
report. Its span is half-open, `[starts_at, ends_at)`, so one ending at noon and the next starting
at noon do not collide; only a `booked` or `out` one is live and holds the vehicle
(`BookingMapper::findLiveOverlapping`). An `out` one holds `[min(starts_at, out_at), max(ends_at,
now))`: the car is gone from the moment it was taken early until it is back, however overdue. The handover is the booking's own two moments, so it is
columns, not a table: `out_*` at check-out, `in_*` at check-in, `level` a percentage of the main
tank or battery. The handover writes no Reading either — the trip logged from it does, and
`trip_id` points there.

**`…/bookings`** lists a vehicle's bookings by start, every state, from a week ago unless `from`
and `to` say otherwise, plus every `out` one and every `returned` one without its trip whatever the
window — the one still has to come back, the other to be logged; it books, changes the span or purpose (`PUT …/bookings/{booking}`, with
the `updated_at` token) and cancels (`DELETE`, which keeps the row as `cancelled`). Only an
`active` vehicle takes a booking or a change, its end must be still to come — a past booking is a
trip — and only a `booked` one changes or is cancelled. Under the vehicle's hold a span another
live booking holds is refused with 409 and that booking, booker's display name included, so the
sheet says whose it is. A double-booked car is what a pool exists to prevent, so this is a
refusal, not a flag.

**`…/bookings/{booking}/check-out`** and **`…/check-in`** take `odo` (required), `level` (0–100),
`notes` and `at_off`; the instant is the server's. Check-out needs a `booked` booking of an
`active` vehicle, until its end, and is refused with 409 and that booking while another of the
vehicle is `out` ("still with Anna"); the 409's booking carries its `state`, so the sheet tells
"still with" from "booked by". Early is allowed but claims the hours before the start too,
so another live booking in them refuses it the same way, and a booking into them afterwards is
refused as one into an `out` booking's. Check-in needs an `out` one, on any vehicle —
a car laid up while out still comes back — and answers the booking. Neither sends the `updated_at`
token: what counts is the booking's state when the car changes hands, not what the screen showed.
Each booking carries `flags`, worked out on read and never refusing: `odo_below` (the check-out
counter below the vehicle's Reading at `out_at` — not its newest, which the trip logged afterwards
moves past it — or below the latest earlier check-in's whose trip is not logged yet, which no
Reading holds; once logged, the trip is the record; or the check-in counter below the check-out one) and `late` (checked in after `ends_at`).
Three queries read what `odo_below` measures against for a whole page
(`BookingMapper::findCountersAtOut`), so the list and sync cost the same for one row as for many:
the two instants per booking, each a `MAX` in the SELECT list, then the rows at them by key. A
`MAX` in a join condition ran once per candidate row and grew with the square of the bookings.

**A returned booking becomes a trip** only through the driver. While it has none it carries
`trip_draft` — `started_*`/`ended_*` and `start_odo`/`end_odo` from the handover, the booking's
purpose, no category — for the entry sheet to prefill. `POST …/trips` takes an optional
`booking_uuid`: a `returned` booking of the same vehicle, under the booking rule, with no trip yet.
Under the trip's own hold and transaction `trip_id` is written to it (`BookingService::tie`), or
the trip is refused with 409 and that booking; another vehicle's booking is not found. The
check-in logs no trip itself: a business trip needs a purpose and a partner the app would be
inventing. A voided trip stays tied, `trip_voided` on the booking says so, and the booking is
not logged twice. `open_trip` in the booking's `may` is the trip's own `edit`, as its timeline row
carries it, while the trip is not voided.

**A vehicle says who has it.** Its JSON carries `out_with` — the booker of its `out` booking, by uid
and display name, with that booking's `ends_at`/`_off` — and `my_next_booking`, the caller's own
`booked` one not over yet that starts within seven days, by uuid and span. Both are null when there
is none. The list fills them for every vehicle from one query (`BookingMapper::findPooled`), never
one per vehicle; the single read, the update and the restore fill them too, because the screen
replaces the vehicle it holds with their answer. Overdue is the client's to say, against the clock.
`ever_granted` comes the same way, from one more query (`AccessMapper::everGrantedAmong`): whether anybody
was ever given access, the rule *Entered by* follows and the screen shows Bookings by.

**A boolean column is nullable and carries a default.** Nextcloud's schema check refuses a `NOT NULL`
boolean outright — it is an integer of length 1 on the databases it supports — and NC 31 enforces
that where NC 34 no longer does. The default is what a flag nobody touched means.

**Indexes are part of the schema, not an optimisation:** a unique index on `uuid` everywhere, so the
database states the identity too; `(vehicle_id, <time column>)` on every child table, `(user_id)` on
vehicles, `(vehicle_id, read_at)` on readings, `(vehicle_id, started_at)` on trips, `(vehicle_id,
starts_at)` on bookings, `(grantee)` and `(vehicle_id)` on access, `(entity, entity_id)` on the
audit trail, which hangs off a row rather than a vehicle. Besides: `(vehicle_id, updated_at, id)`
on every table sync pages through, in its order; `(created_by)` wherever an erasure or a personal
export looks for an author; on readings `(vehicle_id, source_type, source_id)` for the Reading an
entry wrote and `(vehicle_id, source_type, read_at)` for one source's Readings by time;
`(vehicle_id, state)` on bookings, for who has the car. Index names stay within 27 characters and
are unique across tables, as PostgreSQL and Oracle name them per schema.
QBMapper hides the query, not the missing index. A list in a query goes through `Db\InList`, since
Oracle refuses an `IN` of more than 1 000 items ([Oracle](development.md#oracle)).

### Time

Every user-facing timestamp is two facts, not one
([ADR 0007](adr/0007-time-is-an-instant-plus-an-offset.md)): the UTC instant, plus `<name>_off`, the
originating UTC offset in minutes. A Fahrtenbuch is judged on local calendar dates, and a trip
ending 00:30 in Berlin belongs to the previous day in UTC.

- **Reporting and legal views derive the local date** from the pair. Ordering and durations use the
  instant.
- **The entry's own instant is the user's** — `read_at`, `ended_at`, `filled_at` — when it happened,
  editable, part of the data. A plain calendar date is one fact and takes no offset: `first_reg` and
  `disposed_at` are days, not moments.
- **`created_at` is the server's**, from `ITimeFactory`, never accepted from a client. Timeliness is
  the gap between the two, and the export can show it rather than pretending it is zero.
- Never call `time()` or `new DateTime()` in app code ([testing](development.md#testing)).

### Concurrency

An update sends the `updated_at` it read; a stale one is rejected with **412**, never silently
overwritten. Roughly ten lines in the base mapper. Two browser tabs are the realistic case today and
sync stands on the same foundation. Trips under Logbook Mode cannot collide — they are
append-only.

The token is `updated_at` itself, a unix second, and the statement that checks it is the statement
that writes: one `UPDATE … WHERE id = ? AND updated_at = ? AND deleted_at IS NULL`, matching nothing
when the row has moved on. It **always advances**, to at least one second past the token it
replaces, because two writes in the same second would otherwise leave the second one's token
looking fresh. Identity and provenance — `uuid`, `created_at`, `created_by` — are not writable
through an update at all.

**Undo advances the token too.** `POST /api/vehicles/{uuid}/restore` (and each Entry's and
reminder's) runs `UPDATE … SET deleted_at = NULL, updated_at = ? WHERE id = ? AND updated_at = ? AND
deleted_at IS NOT NULL`: the same check against the token the delete answered with, the mirror
predicate, and a new token as on any write, answered with the row. A client that asks what changed
since by `updated_at` would otherwise never see a restore — a trip voided under Logbook Mode and
restored a month later would never reach it. The delete's token is spent: an edit still holding it
is a 412. A restore that matches nothing is a **412** like any other: either the row moved on, or it
was never deleted, and both mean what you read is not what is there.

**Recomputed columns check no token.** `fleet_vehicles.odo_value` and `fleet_odo_readings.flagged`
are derived from the readings ([odometer rules](#odometer-rules)), so each is written by a statement
of its own that checks no token: two entries arriving together would otherwise tell the second one
it lost a race it was never in. `odo_value` does not move the vehicle's token either, or an
odometer entry would refuse the vehicle sheet that happened to be open.

**A changed flag moves the Reading's token.** Until 0.3.1 it did not, and sync sent a vehicle's
whole chain whenever one Reading changed, because it could not tell which flags had moved. Now
settling moves `updated_at` on exactly the rows whose `flagged` changed, so sync sends those and no
others. The price: a sheet open on a Reading while another entry changes its flag saves into a
**412**. That is rare, and honest - the Reading is not what the sheet shows any more.

On the wire the token is the vehicle's `updated_at`, and every write carries it back — a `PUT` in
the body, a `DELETE` in the query string. A write that arrives without one is a **400**: there is
nothing to check it against. **Nextcloud answers its own failed CSRF check with 412 too**, so a
client tells the two apart by the body, not by the status: ours carries `"conflict": true`, which
Nextcloud's never does. The client raises that as its own error type, so the sheet can stay open
with the values intact ([ui](ui.md#the-entry-sheet-in-detail)) — a CSRF failure would only repeat
itself on a retry.

**A create may arrive twice** ([retried creates](api.md#retried-creates)). Under the client's
`client_uuid`, `Once` looks the row up before the request is validated — the first create
may have made it invalid, as a booking makes its own span taken — and again under the vehicle's
hold, where a twin in flight has committed. A twin the hold does not order, such as two vehicle
creates, is caught by the uuid's unique index: the insert fails, the transaction rolls back, and the
row that won is answered.

The client also learns the server's clock: every response carries the server time in a header, and
the frontend keeps a running offset. That offset is a **diagnostic** — it lets the UI warn that a
device's clock is days out. It never rewrites what the user typed.

### Odometer rules

This is where logbooks quietly break. Six rules, decided once:

1. **Readings are ordered by `(read_at, id)`, never by value.** A trip entered three days late is
   dated three days back, not appended to the end.
2. `fleet_vehicles.odo_value` **caches** the newest reading in that order. Every write to a chain
   recomputes it. Two drivers logging at once must not be able to corrupt a running total,
   so nothing is ever incremented in place. Recomputing alone is not enough: a write that read the
   chain before another's Reading committed would cache the older number, and could cache it last.
   So every write that recomputes holds the vehicle's row first, in its own transaction, with an
   `UPDATE` that changes nothing. A second writer waits for the first to commit and, at Nextcloud's
   READ COMMITTED, reads its Reading. No read writes the cache and no nightly job rewrites it: that
   would only hide a bug. `occ nextfleet:check` finds a cache or flag that drifted, and
   `occ nextfleet:recompute` settles it again under the same hold.
3. **A lower reading is a flag, not an error.** Cluster swaps, engine changes and imports really do
   reset the counter — and the app cannot tell one from a typo. So the row is saved as `reading`,
   `flagged`, and the timeline offers the follow-up question on the Entry's row: *Counter
   replaced* or *Typo*. Until it is answered the flag stands and the segment is broken, exactly
   like `missed_previous`. *Typo* opens the Entry's sheet to fix the number. *Counter replaced*
   is `POST …/readings/{reading}/reset` with the Reading's token, for any Entry's Reading, by
   whoever may edit that Entry, and only for an Observed Reading in question: it writes `kind =
   reset`, which is never flagged and starts a new segment. A distance sums its segments: what
   the old counter ran up to the swap plus what the new one ran since. A consumption segment over
   a swap yields no number, and a Gap is measured from the swap, never across it. An edit that
   changes the number makes it a `reading` again, so the question is asked afresh.
4. **`value` is km or engine hours**, per vehicle. Trailers count neither, tractors and generators
   count hours. A km vehicle — a truck, say — may also count engine hours: `second_unit = h`
   switches on a second chain of Readings (`counter = second`; null reads as `main`). Each chain is
   ordered, flagged and cached on its own — `odo_value` for `main`, `second_value` for `second` —
   and a distance counts from its own chain. Trips, Gaps, Reconciliation Trips, Logbook Mode and the
   Fahrtenbuch read `main` only. An Odometer Entry on a two-counter vehicle says which counter it
   read. Switching it off clears the column and keeps the Readings. The main unit is never switched
   by picking a type once the vehicle has Readings, because their numbers mean what the unit meant
   when they were read.
5. **An Entry writes its Readings** at the moment it happened: a trip exactly one, at `ended_at`,
   on `main`; a fill-up at `filled_at` and maintenance at `done_at`, one per counter given and none
   without. A trip's `start_odo` is a *claim*, not a
   reading — comparing it with the Reading the last trip before it left is precisely what produces
   gap detection ([logbook mode](features.md#logbook-mode)). A Reading no trip wrote is not what
   the claim is compared with: it proves the counter moved and accounts for no kilometre. The Reading goes where its Entry goes: voiding the
   Entry voids the Reading and the undo brings both back, in one transaction. A Reading left
   standing on a voided trip would hold the vehicle's counter at a journey nobody claims any more,
   on a row the timeline no longer shows. An edit restates the same Reading in the same
   transaction: its date follows the journey's end, and its number is counted again only when the
   counter, the distance or the start changed. An edit to the end time, where the trip went or why
   leaves the number alone, so a counted value is never recounted behind the driver's back.
   A fill-up or maintenance record keeps one Reading row per counter through its edits
   (`OdometerService::followEntry()`): a changed counter or moment moves it, an emptied counter
   soft-deletes it, and a counter stated again brings the same row back. Its undo restores the
   Readings for the counters it stated when it was deleted, so one an earlier edit emptied stays
   gone. An Odometer Entry is its own Reading, so it is edited, deleted and restored as itself
   (`…/readings/{id}`); a Reading another Entry wrote is not found there, only through its Entry.
6. **Observed beats derived.** A driver who enters a distance instead of an end odometer leaves
   `start_odo` null; the Reading written at `ended_at` is *(latest reading at or before
   `started_at`) + distance*, marked `origin = derived`. Consumption requires **observed** readings
   at both ends of a segment; a fill-up without a counter has none, and its segment yields no number. When
   a later observed reading contradicts the derived chain, the observed value wins and the derived
   rows are flagged — never silently corrected. Winning is not an alibi: if that reading is still
   below the last row left standing, it is flagged too, and both questions get asked.

### The timeline

One vehicle, one timeline ([ui](ui.md)): every table that dates a row, merged into one order,
newest first, fifty rows at a time. `GET /api/vehicles/{uuid}/timeline`, `?type=` the chip and
`?cursor=` the scroll position.

**The merge is the server's, because the paging is.** A client that asked each table for fifty rows
would have to hold both to know which fifty come first, and that gets worse with every table M3
adds.

**The order is `(occurred_at, kind, id)`.** Two rows really do share an instant — a trip entered at
the moment the counter was read, an import that dates a day's rows alike — so the instant alone is
not a total order, and a page boundary that is not a total order silently drops a row or serves it
twice. `kind` breaks the tie between tables, `id` inside one. A kind ranks by its place in a fixed
list, never by its name, so that the merge and the cursor cannot end up ranking differently; a new
kind goes at the end of that list, because re-ordering it would re-order pages somebody is already
scrolling through.

**The cursor is that key**, `<instant>:<kind>:<id>`, and it is the server's own word handed back —
one a client invented names a place in an order it cannot see, so it is refused rather than read as
"start at the top". Each table is asked for fifty-one rows, which is what says whether a next page
exists; a page that answers with no cursor is the last one, so nobody fetches an empty page to find
out.

**A row is an Entry, not a written row.** A trip carries the Reading it left on the counter
([rule 5](#odometer-rules)), so the journey and the counter it moved are one row on screen — which
is why the Readings query selects only `source_type = 'manual'`, the Entries that are their own
Reading (CONTEXT.md). Listing the rest would show every trip twice. Voided rows are out of the read
entirely; the Fahrtenbuch export asks its own question.

A trip row also carries `missing`, the fields its ruleset requires and it leaves unstated
([logbook mode](features.md#logbook-mode)), and `flags`, computed on read. `overlap`: another live
trip of the vehicle shares part of its span; ends that only touch do not. `overtaken`: a
Reconciliation Trip overlapped by a trip entered after it, which now counts those kilometres twice.
The row offers *Void trip* for it. One query per page finds the overlapping trips, those on other
pages included.

The kinds are `odometer`, `trip`, `energy`, `maintenance` and `expense`, ranked in that order. A
fill-up or maintenance row carries `readings`, the Readings it wrote (none to two, one per
counter), for the same reason a trip carries its one; they are looked up by `(source_type,
source_id)`, because an id is unique only inside its own table. A fill-up row carries `flags` as
well, and `consumption`: the segment it closes, or null
([numbers](#numbers-consumption-cost-emissions)). That is measured over the vehicle's whole energy
chain, since the fill-up that opened the segment may be pages away.

**One row reads back on its own**, `GET /api/vehicles/{uuid}/timeline/{type}/{id}`, in the same
shape. The entry sheet asks for it after an edit lost a race, for the token its _Save anyway_ writes
under ([ui](ui.md#the-entry-sheet-in-detail)). A Reading another Entry wrote is not a row, so it is
not found as an `odometer`.

**The Gaps are a route of their own**, `GET /api/vehicles/{uuid}/gaps`, and are not paged. A month
header states the whole month's, and a figure that grew as rows scrolled in would state a number
that is not true yet. Each Gap names the trip that opened it, the distance and the two moments that
bracket it. The screen sums them per month and reads them only under Logbook Mode.

**A Gap is closed** by `POST /api/vehicles/{uuid}/gaps/{trip}/close`, carrying the distance and the
two moments the driver confirmed. The server finds the Gap again and closes it only if all three
still match; otherwise it answers 412 `conflict`, like a stale token. It holds the vehicle's row
before it looks, so two confirmations of one Gap at once close it once and refuse the other. The
Reconciliation Trip runs from the Reading the Gap was measured against to the start of the trip
that claimed it and carries the Gap's distance, so rule 6 counts its Reading onto the claim and the
Gap is gone on the next read. That holds over an Entry between the two, which is why an Entry at
that Reading's own moment reading another number opens no Gap: rule 6 would count from the Entry.

`odometer#index` stays where it is. The counter's own chain — flags, segments, what the vehicle
stands at — is a different question from what happened to the vehicle.

### The Fahrtenbuch export

`GET /apps/nextfleet/vehicles/{uuid}/logbook/{year}` answers with the page itself, not with data for
one ([ADR 0005](adr/0005-no-pdf-library.md)), so it sits outside `/api`. The Reports screen opens it
by navigating to it, and learns which countries have a renderer from `logbook_export` on each
jurisdiction `GET /api/preferences` lists. A navigation carries no request token, so the route
takes none; it writes nothing, and Nextcloud still demands the same-site cookie. Like every export
it is rate-limited and logged by ids ([security](security.md)). Its policy allows inline style and
nothing else, so a renderer that broke its promise to load nothing still could not.

**The core decides what is in it, the country how it reads.** `LogbookExport` hands a
`LogbookReport` to the jurisdiction's `IReportRenderer`: the trips that set off in the year by their
local date ([time](#time)), voided ones included, each with what its ruleset finds missing and its
late changes, the periods the mode was on with the plates carried in each, and the ruleset's source
URL. The periods are read off the vehicle's flips ([logbook mode](features.md#logbook-mode)): the
first flip's `before` says whether the vehicle was created under the mode, and with no flip the
column says it. The plates are read off the same trail: the header names today's, and each period
names the one it was kept under and when a new one took over inside it, because the receipts carry
the old. A jurisdiction with no renderer answers 404, not an empty page that would pass for a logbook.

**A late change is on its trip's line.** The law accepts later changes that are documented, and one
the auditor cannot see is not. So each line carries its trip's late audit rows (edits, voids and
restores), read for the whole year in one batched query. **Late is decided at export**, against the
export's own rules: a change is late when it came more than the lock delay after the earliest end
the trail had stated by then, or after the trip was entered if that is earlier. The row's stored
`late` answered the rules of its day and is not read, and an `ended_at` set far ahead no longer
stops the clock. A later edit that moves the end back accuses itself, not the changes before it. The page says when, what
each edited field said before, and for a restore since when the trip had been voided: a restore
months later would otherwise erase the void without a trace. A late void is the line's own
`Annulliert` note, stated once. A change inside the lock delay is still the entry being made and is
not listed. Each change also carries the trip's offsets as they stood before it, walked back from the
trip through every later row, so a time it replaced reads in the offset it was entered in.

**A change that left no row is on its line too.** Every change to a trip set off inside a period
writes a row, so a trip whose `updated_at` is newer than the token its newest row left it — with no
row, than its own `created_at` — was changed around the app, by a statement run against the
database. The Fahrtenbuch says *Geändert ohne Protokoll am* and when. Not marked: a change made
before the trip's period began, when no logbook covered it yet, and a trip whose newest row is
older than the token key, which cannot say. Defence in depth, not a rule anything relies on.

**What the page says outside the mode.** Every trip in the year is listed, but only a trip that set
off inside a period states what it lacks. That is the app's one rule
([logbook mode](features.md#logbook-mode)) asked of the mode as it stood when the trip set off, not
as it stands now: the timeline answers for today, the export for a year, and an auditor must not
read "incomplete" on months no logbook was kept for. A period runs from its flip on up to, not including, its flip off. The
server's own instants — when a trip was entered, voided, when the mode flipped — carry no offset, so
they print in the reader's Nextcloud time zone (`IDateTimeZone`) with the offset of that day written
beside them (`LogbookReport::$zone`): the trips next to them are in their own offsets. The trips are sorted into the year by their local date, so a
period is stated when it overlaps the year widened by the furthest offsets, fourteen hours east and
twelve west: exactly the instants some trip of the year could set off at.

**Under `generic` it is a plain listing** (`Generic\LogbookRenderer`): date, time, route, purpose,
counters, distance and category per trip, the year's distance per category above the table,
voided trips listed and marked. Distance is in the vehicle's unit, kilometres or hours. Trips with no
distance stated are counted aloud and left out of the split; a category with only such trips reads
"Not stated", not 0. No period section, no missing fields, no late changes: without a
ruleset nothing is missing and nothing is late. No authority names its language, so it prints in the
reader's, dates and times in their locale; the profile takes `IL10N` for it.

### The mileage claim

`GET /apps/nextfleet/vehicles/{uuid}/mileage/{year}` is a page like the Fahrtenbuch, and served like
it: no request token, rate-limited, logged by ids, the same policy. The Reports screen offers it
where `mileage_claim` is set on the vehicle's jurisdiction and the vehicle counts kilometres.

**The core values, the country prints.** `MileageClaimExport` takes the year's business trips by
their local date, voided ones left out, and hands a `MileageClaim` to the jurisdiction's
`IClaimRenderer`. Each line is the trip's kilometres (`Trip::kilometres()`, the figure the
Fahrtenbuch prints) times `IRateProvider::mileageRateAt()` for the vehicle type on the trip's local
day, in tenths of a cent, rounded half up to the cent on the absolute value. A trip with no rate for
its day or no kilometres prints "not stated" and stays out of the total. Counters the wrong way
round state no kilometres: `TripService` refuses them now, and a row kept from before must not
lower the sum. **What the tax office would not accept is listed and not summed**: a line whose trip
leaves a field of the ruleset unstated (`Completeness::missing()`, whether or not the vehicle is
under Logbook Mode) carries `missing`, and a Reconciliation Trip carries `reconciled`. Either keeps
its amount out of the total, and the German page marks the line and says why. It also says the
flat rate is only for vehicles outside the business assets. The total is null when no remaining
line has an amount. There is no claim (404) where the jurisdiction has no rates or no renderer, or the vehicle
counts hours. **Commutes are not on it**: they are a different deduction under different rules,
and a sum mixing them would be wrong where nobody could see it. **A claim is the reader's**: it
lists only the trips whose `created_by` is the reader, so on a granted car the owner's does not
carry the driver's, and the page says so in its own language ("Fahrten, die Sie eingetragen
haben"). On a car nobody else logs on, that is every trip.

### CSV export

`GET /apps/nextfleet/vehicles/{uuid}/csv/{year}/{table}` answers one file to save, `table` being
`trips`, `energy`, `maintenance` or `expenses`. The Costs screen's *Export* menu links the year on
screen. It sits beside the logbook for the logbook's reasons: a link, no request token, rate-limited
and logged by ids, and the VIEW check a single row takes. An unknown table is a 404.

**A row is in the year its own offset puts it in** (`LocalYear`), as a trip is in the Fahrtenbuch, so no zone is
asked for. The Costs screen cuts its months in the reader's zone instead; the two disagree only
about a row entered in another zone near New Year. A voided trip is in the file with `voided` 1. A
deleted row of the other tables is not, since only a trip is voided rather than deleted.

**The figures are the integers stored**, under the field's own name: cents, millilitres or watt-hours
(`amount` by `energy`), tenths of a cent for `unit_price`, basis points for `vat_rate`, the counter
in `odo_unit`. A money column has `currency` beside it. An instant is its local wall clock
(`started`, `YYYY-MM-DD HH:MM`) plus its offset in minutes (`started_offset_min`). Booleans are 1
and 0, an unstated value an empty cell. So nothing depends on the reader's decimal separator. Every
row ends in `entered_by`, the display name of its author by the timeline's rule (`EnteredBy`): empty
on a vehicle nobody else was ever given access to. A trip also carries `created_at`, the server's
clock in UTC (`YYYY-MM-DD HH:MM:SS`).

**The file is for a spreadsheet** (`Csv`): UTF-8 with a BOM, `,`, CRLF, a cell quoted when it holds a
separator, quote or line break. A string that would start a formula gets a leading `'`, at its start
and after each `;`, tab or line break in it ([security](security.md#hostile-content)). Only strings
are defused: a number is ours, and a negative amount stays a number.

### Personal data export

One person's data, as Nextcloud's *user_migration* app exports an account (`FleetMigrator`, an
`IMigrator`). It writes `nextfleet/<table>.json` for every table that names an account
(`AccountTables`, the list the erasure rewrites): each row whose account column holds the uid,
deleted rows included, with every column as stored. A grant counts when it names the account as
grantee, a transfer's audit row when its diff names it as an owner. A grant to a group the account
is in does not name it and is not exported.

**A row on a vehicle the account no longer sees is withheld.** The vehicle, its grants, its
reminders, its recipient lists and its audit rows mirror the vehicle's shared state, and the export
would show it as it is now, which can be somebody else's: a vehicle handed to a new owner, a
reminder edited after a manager lost the grant. Where such a row names the account only as its
writer and the account may not `view` the vehicle, it is exported as its uuid, `created_by`,
`created_at` and a line saying the rest is withheld (Art. 15(4) GDPR). What the account wrote of its
own doing - trips, fill-ups, maintenance, expenses, readings, bookings, documents, receipts - is
exported whole either way (`FleetMigrator::AUTHORED`).

**Export only.** An import writes a line saying NextFleet does not restore, and reads nothing:
whose vehicle a row belongs to cannot be decided on a server with other people and vehicles. A throw
would fail every other app's import with it.

### Documents

`GET`, `POST /api/vehicles/{uuid}/documents`, `DELETE …/documents/{document}` and `POST
…/documents/{document}/restore` list, attach, detach and restore a vehicle's papers
(`DocumentService`). Listing takes VIEW. The writes take LOG, the vehicle's hold, and the rule of
the row the paper hangs on: on an Entry the Entry's own (`edit`, or `log` when
the caller entered it), on a booking the booking's (`VehicleAccess::mayBooking`), and `edit` for the
vehicle's own papers. A driver files the receipt of their own fill-up; the registration and the
insurance stay a manager's. Detaching asks the same rule of the same row, whoever attached the
paper, and so does restoring it. Each answers with the list as it now stands, so there is no token:
nothing edits a document.

**Attaching takes a `file_id` from Nextcloud's file picker**, with a `kind` and optionally a
`linked_type` (`energy`, `maintenance`, `expense`, `booking`) plus `linked_uuid`. A booking's papers
are its handover photos, `kind: photo` by convention, not by rule. There is no upload path. The
file must be one the attacher owns and can read in their own Files, or it is a 404: the download
serves it to everyone who may view the vehicle, so this is where the file's own access is checked
([security](security.md)). A file shared with the attacher is refused, since the download would
outlive a revoked share and ignore a view-only one. So is a file in a group folder or an admin's
external storage: both name whoever asks as the owner, so the file must also sit in the attacher's
home storage (`OwnFiles::owns`, which the inbox and the import ask too). A link to an entry of another vehicle is a 404 too. The same file on the same entry twice is
one document, and its first `kind` stands: nothing edits a document, so a new kind means detaching
and attaching again. Detaching soft-deletes the row and leaves the file alone. Restoring takes the
stamp off again; a paper that is live already, or whose file is on the same row again by then, is
left as it is, so restoring never makes two papers of one. A paper whose entry or booking was deleted
since does not come back: like attaching, restoring takes a live row only.

**A listed document carries** `uuid`, `kind`, `file_id`, `name`, `mime`, `linked_type`,
`linked_uuid`, and `may`: `detach` where the caller may take it off. The name is looked up by id
in the attacher's own Files, so a move within them is followed; each file once per list or sync
page, however many rows it is on. A file that is no longer there lists with `name` and `mime`
null: deleted (trash bin included), moved into a share, a group folder or an external storage, or
its attacher erased. A paper is the attacher's word for their own file, so a file that left them
is not served from wherever it went, even onto another account's home storage, as a received share
or `occ files:transfer-ownership` puts it: that account never attached it. The row stays, and the
screen says the file is no longer in the attacher's Files.

**The download** is `GET /vehicles/{uuid}/documents/{document}`, outside `/api` beside the CSV
because it is a link. It takes VIEW, so a driver gets the owner's file without a share. It serves
the file only while it is still the attacher's own, as attaching would take it, asked live:
anything else is a 404. The list asks less often: a file's name and type, or that it is no longer
the attacher's, are kept five minutes in the distributed cache (`OwnFiles::shown()`), since
mounting the attacher's Files per paper made long lists slow. So a list may name a file for five
minutes after it was renamed, moved away or deleted; its download is refused at once.
The file always goes out as an attachment, with `nosniff` and a CSP that runs nothing
([security](security.md#hostile-content)). The file is found through `getUserFolder` of the
paper's `created_by`, not `IRootFolder::getById`: in a web request that searches only the
signed-in account's mounts, and a driver's include none of the owner's. The screen fetches the file
rather than following the link, so a refusal is said in place (`src/utils/papers.js`): a link
followed into a 404 replaces the app with the refusal's JSON.

**The screen** is a *Documents* section on the vehicle screen (`VehicleDocuments.vue`), the only
place a paper is added. It reads the list once and hands it to the timeline and the Bookings
section, which put a paperclip on the row of each linked entry or booking. A paper is not a timeline
row of its own: a registration has no date to sort by. The row a paper may belong to is offered from
the newest fill-ups, maintenance records and expenses whose timeline row carries `edit` — the first
page of each kind holding any, at most four pages back, since a driver's own may lie behind other
drivers' — and from the bookings whose `may` carries `attach` (handed over, and the caller's by the
booking rule). Typing searches the whole history instead (`src/utils/owners.js`): the first
keystroke of a dialog reads every page of those kinds, at most forty each, and every booking since
the first, and every word typed must be in the option's title, a booking's purpose, or its date —
as the label writes it, or as `YYYY-MM-DD`. The client searches because the labels are its own:
an energy or a category is a code until the screen words it. Without `edit` on the vehicle one of
them must be chosen, so the search is offered even when none of the newest is the caller's. A 404 on
attach is shown as "not a file of your own", which is what it nearly always means.

### The inbox

A receipt photographed on the phone reaches an entry without the file picker: the Nextcloud mobile
app's auto-upload fills a folder, and the app lists what in it belongs to no vehicle yet. **The
folder is a preference**, `inbox_folder` in `GET`/`PUT /api/preferences`: a file id, or null. As for
documents, the id is the identity and the path a label, so a renamed or moved folder is still the
inbox. `PUT` takes only a folder in the user's own Files — their home storage, so neither a share
nor a group folder — or it is a 400: what lands in somebody else's folder is not the user's to
attach, and attaching would refuse it anyway.

**`GET /api/inbox`** (`InboxService`) answers `folder` (`file_id`, `path`), `files` and `count`.
`files` are the folder's direct children whose MIME is `image/*` or `application/pdf`, which the
user owns, and whose file id no live paper on a vehicle they reach references (one query,
`DocumentMapper::findAttachedFileIds`): newest `mtime` first, at most 100, each with `file_id`,
`name`, `mime`, `mtime` and `size`. `count` is all of them, past the hundred. Direct children only:
auto-upload sorts into subfolders only when asked to, and a recursive walk over a large folder is a
slow request. `folder` is null, and the list empty, when none is chosen or the folder is gone —
deleted, or no longer the user's own — while the preference keeps the id it was given.

The inbox only lists. Attaching is the documents route as before, and the app never moves, renames
or deletes a file in it: a receipt the app moved out of sight is one the user cannot find. Nothing
watches the folder; it is read when the app loads, for the count in the navigation, and again when
the [Inbox screen](ui.md#the-inbox-screen) opens.

### Import

Another tool's CSV export becomes entries (`ImportService`). The file is picked in Files, never
uploaded, and must be the caller's own, by the check a paper's attach makes (`OwnFiles`). Importing
takes `edit` on a vehicle that is not disposed: it writes many entries at once, other people's
history among them. It never creates a trip: neither format keeps a logbook a ruleset accepts, and
trips made up from counter values would be the batch of invented trips that closing a Gap refuses
to make ([Logbook Mode](features.md#logbook-mode)).

- **The seam.** An importer (`lib/Import/IImporter.php`, [contributing](contributing.md)) has a
  key, a nominative label ([legal](legal.md)), the record types of its export — one file each — and
  the questions such a file leaves open. It places the header's columns and turns the rows into
  proposals: a kind, the fields in canonical units, the row, and whether it is new, a duplicate or
  unreadable, with a reason word and its column. It writes nothing. Neither format states VAT, so
  an imported row's `vat_rate` is null: not stated.
- **Formats are evidence, not promises.** Each importer's class names where its headers were read
  and when, or that no source was found. A header it cannot place is reported as ignored, never
  guessed into a field.
- **Bounded.** `CsvReader` reads under the caps [security](security.md) names and refuses the whole
  file past one. It reads RFC 4180, or with the escape character an importer names. `Values` turns a cell into a number, a moment or a yes or no, judging a decimal
  mark or a date order on the whole column, and converts to canonical units, rounding half up once.

**The preview**, `POST /api/vehicles/{uuid}/import/preview`, writes nothing. It takes `file_id`,
`importer` (`lubelogger`, `spritmonitor`), `record_type`, `units`, `tz` (the zone a date without a
time is noon in) and the answers `date_order`, `energy`, `category_map` and `include_duplicates`.
It answers the columns placed and ignored, the `questions` still open with their choices, the
file's cost `categories` and `category_defaults` (what a code the format names becomes unless
answered), `counts` (`new`, `duplicate`, `unreadable`, and `creates` under these
answers), the unreadable rows' `reasons`, the first 50 proposals, and the file's `etag`.

- **Stateless.** Nothing is kept between preview and import: a table would be a migration, a cache
  entry a second place for the truth. The import sends the same body again with the etag, and the
  file is read again.
- **What the vehicle says is not asked.** Money is in the vehicle's currency; a row in another is
  unreadable. A sign stands for every currency written with it, so `$` is never the euro
  (`Values::mayName`). A vehicle currency that is no code, written before the vehicle sheet
  checked it, leaves out each row with money (`currency`, no column) and nothing else. The energy is asked only for rows that name none, which is every LubeLogger row and
  a Spritmonitor row whose `Kraftstoff` code or word decides nothing, and only of a vehicle that
  takes several. Meanwhile the rows are read with the first, as an open date order is read day
  first (`Answers::energy`). A vehicle with no energy type yet takes no fuel import.
- **A Spritmonitor cost type is a code** with a default meaning (`SpritmonitorImporter`), which
  the user may change; a text without one is asked. A purchase price and a refund are no running
  cost and are never imported.
- **A fill-up of an energy the vehicle does not take is imported**, flagged `foreign_energy` as the
  entry sheet's is.
- **A date before 1970 is unreadable** (`date`): no entry route takes one, so the preview leaves
  it out rather than the import failing on it.
- **A duplicate** is a live entry of the same kind on the same local day with the same counter or,
  where either has none, the same amount (`Duplicates`); a fill-up also of the same energy, an
  odometer entry on the same counter chain. Of each kind, only the entries from two days before
  the file's first row to two days after its last are loaded.
- **A refused file is a 422** with the reader's reason word and row, whole: nothing of it is
  previewed. One somebody is writing is a 423.

**The import**, `POST /api/vehicles/{uuid}/import`, takes the preview's body plus the `etag` it
answered. It reads the file again and writes what the preview counted as `creates`.

- **409 when the etag moved**, before the read or during it: the user agreed to counts that no
  longer describe the file, so the screen previews again. The preview answers the etag it found
  before reading, and the import looks the file up again once it has read it. **400 while a
  question is open**: the rows were read with a provisional answer.
- **One transaction under the hold.** Duplicates are marked again inside it, so an entry somebody
  logged since the preview is a duplicate now. Each row goes through its entry service's `add()`,
  the same write `record()` makes, entered by the caller. A row the service refuses stops the whole
  import with a 400 that names the row; nothing is written.
- **Readings settle once.** `OdometerService::batch()` defers each chain's settle to after the last
  row, since settling reads the whole chain and once per row would be quadratic. The flags and the
  cached value come out as if each row had been entered by hand: a lower counter is flagged, not
  refused. `ImportTest` holds 5 000 fill-ups to 30 seconds.
- **The answer is the import's identity**: the counts, and every created entry as `{type, uuid}`
  in file order, `type` being the timeline's. Undoing an import names that list; nothing else
  records it.
- **Sent again, it is answered, not imported** ([retried creates](api.md#retried-creates)). The
  last import's answer is kept per vehicle in the distributed cache, under a hash of the caller and
  the request, the `etag` in it. It is written under the hold before the commit and looked for again
  under the hold, so a twin that waited there finds it; a rollback removes it.
- **Stamped at the commit.** Every row the import wrote gets `updated_at` again just before the
  commit (`BaseMapper::restamp()`). An import longer than sync's two-minute settle window would
  otherwise commit rows older than a sync that ran meanwhile and passed them.

**The undo**, `POST /api/vehicles/{uuid}/import/undo`, takes that list as `created` and
soft-deletes every entry on it, all or nothing, under the hold. It takes `edit`, as the import did.

- **Only what the import left.** Each entry must be live on this vehicle and entered by the caller,
  or the undo is a 409 and deletes nothing: a list naming one entry already deleted, entered by
  somebody else, on another vehicle or a Reading a fill-up wrote is not this import's. All four get
  one answer, so a foreign uuid tells nothing. An entry edited since is still undone. Once one entry
  is gone, the rest are deleted one by one.
- **Through the services' own deletes.** Each entry goes through its service's `remove()`, the
  write `delete()` makes, so a fill-up's or a record's Readings go with it. Inside
  `OdometerService::batch()`, the chains settle once, after the last. Its rows are stamped at the
  commit, as the import's are.

**From a script**, `occ nextfleet:import <uid> <vehicle-uuid> <path> --importer= --record-type=
--units=km,l [--date-order=] [--energy=] [--map=text:choice …] [--include-duplicates] [--dry-run]`
builds the screen's request and runs it as that user, through the same `ImportService`: a preview,
printed, then the import with its etag unless `--dry-run`. The path is resolved in the user's Files
and the file then checked as a picked one, so a share is refused here too. `tz` is the user's zone,
else the server's (`UserZone`). A refusal exits 1 with its reason, and so does a `--map` text the
file does not hold, which can only be a typo. There is no undo command; `-v`
prints the created list the undo route takes.

## Nextcloud integration

| Concern | Mechanism |
|---|---|
| Identity, ACL | `OCP\IUserSession`, `IGroupManager`. Every query runs through `VehicleAccess::may` from M1 on: owner, or a row in `fleet_access` with a sufficient role ([ADR 0001](adr/0001-own-access-table.md)); the strongest row wins and none narrows another. Five operations: `view` (viewer, driver, manager), `log` (driver, manager: add an entry, change one you entered), `edit` (manager: vehicle settings, reminders, recipients, the vehicle's own documents, any entry), `delete` (manager: delete any entry) and `own` (the owner alone: access, deleting and restoring the vehicle). A route that names one vehicle asks `may`; a route that lists them asks `reachable` instead, so the widening is one query and not one per row. An Entry route asks for `log`, then `VehicleService::change` for the Entry it found: `edit` or `delete`, or `log` alone when its `created_by` is the caller. The vehicle JSON carries `may`, the caller's operations, from the same rows, plus `book` where they hold `log` on an `active` vehicle — the booking rule as a word to hide by, not a sixth operation — and each timeline row carries `may` too — `edit` and `delete`, or neither, as `VehicleService::changes` reads them off the vehicle with no further query; the UI hides by these ([screens follow the role](ui.md#screens-follow-the-role)). On a vehicle with any `fleet_access` row, revoked ones included, timeline rows and the logbook name who entered each Entry from its `created_by` (`EnteredBy`, [who entered it](ui.md#who-entered-it)). `…/grants` lists, grants, re-roles (`PUT …/grants/{grant}`) and revokes, all `own`, each answering with the list and each grantee's display name. A grantee is a user or a group the instance has, never the owner, and one the admin's sharing settings let the owner reach — none while the Share API is off or the owner is excluded from sharing; under "members only" a user sharing a group with the owner, minus the exempt groups, or a group the owner is in, and no group at all where group sharing is off — read as core's own share checks read them; any other is the same 400 as nobody. Granting one again changes the role. A revoke takes off the reminder recipients who reached `view` before it and do not after; one who never did, such as a bookkeeper on the owner's list, stays. Taking a member out of a group does the same for each car the group reached when they left, since no revoke runs; a queued job does it (`GroupMemberRemovedListener`, `ForgetMemberJob`, `GrantService::forgetMember`), so a group admin's click waits on no vehicle, and it counts grants revoked since, as deleting the group before cron came round revokes them. A grant adds none. A new grant notifies the user at once, or each member the group has when a queued job runs (`GrantNoticeJob`; a grant revoked by then tells nobody), never the owner; the notification's object is the grant, so a revoke withdraws it, and `Notifier::prepare()` names owner, vehicle and role as they stand when it is read, or drops it once the reader no longer reaches `view`, as a former group member does. A role change sends nothing. `…/access` is the caller's own, at `view`: `GET` answers their own grant's role, each group that reaches the vehicle, and `holders`, who reads the vehicle — the owner (role `owner`), then each grant — by display name and role, without uids, leaving out a grantee the instance no longer has; empty for the owner and for a caller who holds nothing; `DELETE` leaves, giving back only the grant in their own name, with a revoke's rules (recipients, notification). Through a group there is nothing to leave — a personal opt-out would be a weaker row beating a stronger one — and the owner holds no grant, so both 404. A deleted group's grants are revoked ([data model](#data-model)). A booking route asks for `view` to list and `log` for the rest, then `VehicleAccess::mayBooking` for the booking it found: `edit`, or `log` alone when the caller is the booker — the Entry rule with the booker in the author's place, not a sixth operation. Each listed booking carries `may`: `edit`, `cancel`, `check_out`, `check_in`, `log_trip` and `attach` as its state and that rule allow; a trip logged from a booking takes the same rule. A document route asks for `log`, then the rule of the Entry or booking the paper hangs on, or `edit` for the vehicle's own ([documents](#documents)). A cancel by anyone but the booker notifies the booker, without naming who cancelled ([legal](legal.md)); the notification's object is the booking, `Notifier::prepare()` drops it once the booking is no longer cancelled, and deleting the vehicle withdraws it (`BookingNotices`). |
| Reminders → push | Own `TimedJob` (hourly) evaluates due reminders, then `OCP\Notification\IManager` + an `INotifier`. It reaches the phone through the Nextcloud app. |
| Reminders → mail | `OCP\Mail\IMailer` + `IEMailTemplate`, using the server's configured SMTP. Digest, not one mail per item. |
| Files, receipts | `OCP\Files\IRootFolder`. Papers stay wherever their owner keeps them in Files and are **referenced only by `file_id`** ([documents](#documents)), so moving or renaming one breaks nothing. The app creates no folder of its own: `fleet_vehicles.folder_file_id` is reserved for one and always null. |
| …but served by us | Downloads go **through our controller**, so access follows the vehicle's access grant, not the file's. Otherwise a receipt on a car others have access to is invisible to the other driver unless the owner shares their folder. A `file_id` survives a move but not a delete — handle the missing node instead of 500ing. |
| Talk (optional) | Post due items into a fleet room, only when the Talk app is present. In the backlog, cheap, and very much the reason someone runs Nextcloud. |
| Activity stream | `OCA\Activity` provider — optional, after v1. |
| Dashboard | `OCP\Dashboard\IAPIWidgetV2` over `ReminderService::due()`: the overview's reminder read (`fleet()`), open ones only, in the overview's urgency order, sorted in PHP because the widget has no browser code. It runs no query of its own. It lists red and amber only: the dashboard is what needs you now. |
| Unified search | `OCP\Search\IProvider`: find a vehicle by plate (separators ignored), manufacturer or model, among the ones `VehicleService::list` gives the searcher, disposed ones left out. Vehicles only: searching trip purposes or notes would take the access check somewhere nobody tests it. |
| Settings | Personal settings (default jurisdiction, "I reclaim VAT", grid factor, the [inbox](#the-inbox) folder). The mail cadence is per vehicle. |
| CLI | `occ nextfleet:import` imports a CSV export as the user it names ([import](#import)); `occ nextfleet:transfer` hands a vehicle to another account ([data model](#data-model)); `occ nextfleet:seed` writes a demo fleet ([development](development.md)). The admin commands, with their options and exit codes, are in [occ commands](admin/occ.md); what they go through is here. Each is a thin shell and writes no SQL. `vehicles`, `access` and `audit` read the mappers rather than as the owner, so a vehicle in the trash or an erased owner's answers. `check` (`CheckService`) changes nothing. It reports a row whose `vehicle_id` names no vehicle (in the tables `Db\VehicleTables` lists), the drift `OdometerService::drift()` finds, an Entry's Readings out of step with it (rule 5; their values are not compared), twin live grants, a grantee no backend knows with nothing pending, and a pseudonym from before 0.3.0. `recompute` fixes the drift through `OdometerService::recompute()`, the settling a write runs, under each vehicle's hold. `pending --finish` and `reminders --send` run `PendingJob`'s and `ReminderJob`'s rounds. `mail-test` goes through `MailService::test()`. `restore` goes through `VehicleService::restore()` as the owner, so it leaves what the owner's undo leaves. `Command\Format` is the shared `--output`. |

**No calendar.** Public `OCP` on NC 31–34 can create a calendar event but cannot update or delete
one. A reminder that is changed, snoozed or completed would leave an event that still rings, so
reminders write none.

## Reminder engine

One rule set, used by the templates — HU/AU, oil change, brake fluid, tyre swap — and by a reminder
with its own title, such as an insurance renewal or a licence check.

1. A reminder is due by date, by odometer, or by whichever comes first.
2. A date reminder warns at its ticked warning points — a month before, at the start of the month
   it is due, on the due date; an odometer reminder at `due_odo − lead_odo`.
3. On trigger: in-app notification, mail digest entry.
4. Completing it is a `fleet_maintenance` record that names it (`reminder_id`). If it recurs, the
   reminder moves to its next `occurrence`, due from the **actual** completion date/km — not the
   planned one; otherwise it is `done`.
5. **Prediction needs data to be honest.** Predicted km/day comes from the last 90 days and requires
   a floor of 30 days and two readings. Below that, the UI says "not enough data yet" rather than
   inventing a date. An odometer reminder with no usable prediction stays odometer-only: no
   estimated date, no false precision.
6. **States:** `planned → warned → due → overdue → done`, plus `snoozed` and `dismissed`.
   **Dismissing skips this occurrence; the recurrence continues.** Ending it is deleting the
   reminder — an explicit act. Someone who dismisses one oil change does not mean "never again", and
   the alternative silently terminates a legal duty. Snooze is not a nicety either: a reminder you
   cannot postpone gets muted permanently, and then the app is lying to you.
7. Notification receipts are per channel, in `fleet_reminder_receipts`, so a broken SMTP server
   does not silence the in-app notification — and so "what did we send, when" is a query. Not
   `fleet_reminder_notifications`: Nextcloud refuses a table name over 27 characters.

**The state at an instant.** `ReminderEngine::evaluate()` is pure: a reminder, a plain day and the
main chain's newest value in, a state and a point out. Everything that shows or sends a state asks
it, so a test moves the same clock the job does.

- By date: `warned` from the earliest ticked point, `due` on the due date, `overdue` from the day
  after. A month before a 31st is the month's last day, and a month's last day counts back to the
  previous month's last day (30 April → 31 March). With no point ticked, the reminder is `planned`
  until it is due.
- By km: `warned` from `due_odo − lead_odo` (no lead, no warning), `due` from `due_odo`. A counter
  has no day after, so by km a reminder is due and stays due. `either` takes whichever axis is
  further along; its overdue is the date's.
- The point is the newest one reached, and a receipt is written under it: `month_before`,
  `month_start`, `due_date`, `odo`, `odo_due`, `overdue`. An unticked due date sends nothing on the
  day, but the reminder is still due.
- Snoozed until a day: `snoozed` until that day, and on it back where it stands. The due date stays.
- Dismissing a recurring reminder moves it one recurrence on from the **planned** due, since nothing
  was done, and evaluates it at once. One that does not recur stays `dismissed`. An `either`
  reminder recurs on both axes or on neither; the sheet refuses one, since its next occurrence
  would be due at once. Done and dismissed take no snooze and no second dismissal.
- The day is the server's, `ITimeFactory`'s. A due date is a plain day with no zone.
- `…/reminders` lists each reminder evaluated at now, against the main chain's newest value, and
  writes nothing. The stored state is what the job last persisted; the banner shows where the
  reminder stands. `GET /api/reminders` answers the same for every vehicle the user may see but a
  disposed one, each row naming its `vehicle`, so the overview reads the fleet in one request.

**The estimate.** `ReminderEngine::estimate()` answers rule 5 for a reminder by km or by either,
and `…/reminders` lists it beside the state. The pace is the main chain's newest segment inside the
last 90 days: flagged Readings do not count, and a lower unflagged value is an answered reset, so
the pace starts again there. It needs two Readings 30 days apart and a rising counter; the day is
where it reaches the due km, and never earlier than today. A due km already reached has no
estimate; the state says it is due. Without a pace the screen says "not enough data yet". The state
never reads it. Only the window is read, for every listed vehicle in one query; the state reads the
newest value off the vehicle's `odo_value`.

**What the sheet writes.** `…/reminders` takes the mode, the due date or km, the lead, the warning
points and the recurrence; the state and the occurrence are the engine's. A template fills what the
request left out, at creation only, so an edit can clear a recurrence. A field the request names
empty is one the user cleared, and the template does not fill it. `…/reminder-templates`
lists the templates the vehicle offers and what each fills in, so the sheet can show it; the
inspection also states `first_due_months`, for the sticker question to prefill. Its title stays null and
translates from `template_key`. A date reminder refuses a km field and an odometer one a date
field: the engine would never read them. The owner and managers write reminders, delete included;
drivers and viewers read them.

**What a maintenance record closes.** `…/maintenance` takes `closes`, a reminder uuid, and every
maintenance row answers with it (the timeline too), since `reminder_id` stays off the wire. It
names a live, open reminder on the same vehicle, or the one the record already closes; a done or
dismissed one is not closed twice. The record's day is `done_at` in its own offset. A reminder that
recurs by km needs the record's counter, or its next occurrence would be due at once. The edit
sheet is a full replace, so a record saved without `closes` closes nothing any more.

Withdrawing the work takes the occurrence back, in the same transaction:

- An edit that moves the day or the counter, or names another reminder, takes back the old close
  and closes again from the record as it now is.
- A delete takes it back, and its undo closes again.
- Taking back undoes rule 4's step: the occurrence counts down, and the due is the record's own day
  and km. The planned due is not stored anywhere, so it cannot come back. A one-off goes from `done`
  back to where it stands.
- A reminder something else has moved since — a dismissal, an edit, another record — no longer
  stands where this record put it (`ReminderEngine::retreat()` checks), and is left alone. So is a
  deleted one. An undo closes again only while the reminder stands where the delete left it;
  otherwise the record comes back closing nothing, so withdrawing it again moves nothing.

**Who is told.** Each vehicle has a list of recipients; a new vehicle starts with its owner on it.
`…/recipients` lists it, `POST` adds an account (`user_id`), `DELETE …/recipients/{recipient}`
takes one off, the owner included, and each answers with the list as it now stands. Reading the list
takes `EDIT` as writing it does: who gets told is the managers' business, and the sheet shows the
list only to whoever may change it. An added account must exist and, since a reminder names the
plate, be one the sharing settings let the caller reach — the grantee rule (`Sharable`), the same 400 as a missing
account. Whoever already reaches `view`, the caller included, knows the plate and is always
accepted. Before 0.3.1 neither was asked, so the upgrade from there takes off every recipient whom
neither the owner nor whoever listed them may share with and who does not see the vehicle (repair
step `StrangerRecipients`). It runs
only while the installed version is below 0.3.1: a later `occ maintenance:repair` must not drop
people because an admin tightened sharing. Being on the list grants nothing, since Vehicle Access
decides what the notification's link opens. A removal is a hard delete, so the
same account can be added again under the unique index. A removal, and a prune by a revoke, a
leave or a member leaving a group, withdraws the reminder notifications that account was sent for
the vehicle, after the commit (`NotificationService::withdrawFrom`). Their receipts stay, so being
added again tells them the next point, not one already told. `Notifier::prepare()` also drops a
reminder notice whose reader is no longer on the list. The mail cadence is the vehicle's
`reminder_mail`, written with the vehicle like any other column.

**The job.** `ReminderJob` runs hourly (`info.xml`), or at once with `occ nextfleet:reminders
--send`, and hands the round to `NotificationService`.
One query finds each vehicle not disposed of that has a reminder neither done nor dismissed, with
its recipients (`VehicleMapper::findReminded()`); a fleet's other vehicles cost the job nothing.
Each is held, one transaction each, and read again under the hold; every live reminder is evaluated at
`ITimeFactory`'s now against the newest main-chain value, and a state that changed is persisted.
The newest point reached goes to each recipient once: the `app` receipt is claimed first, and only
a new receipt sends. So a run missed for a day skips the points in between, and a second run
sends nothing. Sending waits for the commit, so a rollback cannot leave a notification without its
receipt. A vehicle that fails is logged and the round goes on; so does a send that fails, whose
receipt is then deleted so the next run tries it again. Both catch errors as well as exceptions,
so the digest always runs.

- The notification stores parameters — vehicle, plate, template key or title, due date and km,
  occurrence — and `Notifier::prepare()` translates them in the reader's language and locale. It links to
  `…/apps/nextfleet/?vehicle=<uuid>` only for someone with `VIEW`; everyone else on the list gets
  the plate and title. It has no actions.
- Its object is the reminder. A new point replaces the recipient's old one. A point already sent
  stays up while the state moves on: an unticked due date leaves "due on" standing. The job takes
  it back when the reminder moves to where nothing is told, and so do snoozing, dismissing,
  deleting, and a maintenance record closing the reminder or being withdrawn. Deleting the vehicle
  takes back every one of its reminders' notifications, since the job no longer reads it, and
  its grants' too (`GrantNotices`); an undo sends nothing, and the next round tells what is still
  due. Each of these withdraws after its commit — a maintenance record's write through
  `AfterCommit`, so a refused one takes nothing back — and the job sends after its own, so a
  notice can go out after the change that ended it. `Notifier::prepare()` therefore drops a
  reminder notice whose reminder is deleted, done, dismissed or snoozed, or no longer has the
  occurrence, due date or due km the notice names. A notice from before 0.3.1 names no
  occurrence; the rest decides.
- `laid_up` evaluates and persists but sends nothing. Back to `active`, the point it stands at has
  no receipt yet, so it sends once. `disposed` is not evaluated at all.
- A receipt is per point and occurrence, and the schema has no more. A snooze that ends on a point
  already sent therefore sends nothing until the next point.
- An edit that moves a point — a new due date moves every date point, a new due km both km points,
  a new `lead_odo` the km warning, a warning ticked on or off its own point
  (`ReminderEngine::moved()`) — lets those points ring again where they now fall
  (`NotificationService::rearm()`). Their `app` receipts go. A recipient whose standing notice is
  for a moved point has it withdrawn after the commit and loses every `app` receipt of the
  occurrence, so the next round tells them where the reminder now stands; unticking the day that
  rang leaves "due on" standing, as above. A notice for a point the edit left alone stays. A
  `mail` receipt of a moved point is kept under `~<point>`: the point is news again, and the mail
  still counts for the day.

**The digest.** After the notifications, the job hands the round to `MailService`. Each enabled
recipient with an address gets at most one mail a day, from 07:00 in their time zone (`core`
`timezone`, else the server's `default_timezone`). It covers every active vehicle on their list
whose `reminder_mail` falls that day — daily, weekly on Monday, monthly on the 1st, in the
recipient's day — and has news: a point this user has no `mail` receipt for. No news, no mail. The
points are the ones the notification tells, and the lines say what it says, without the plate
(`ReminderWords`), grouped under each vehicle's plate; the plate links only for someone with
`VIEW`. The receipts are claimed under each vehicle's hold, one vehicle at a time, and the mail is
sent after those commits, so no vehicle waits on the mail server. A refused or failed mail deletes
the receipts it claimed: the notification has its own receipt and stays, and the next run tries
again. One recipient's failure leaves the others' mail. A day counts as mailed by its newest `mail`
receipt up to now. A day is checked once: the user value `nextfleet` `digest_checked` names the
recipient's local day whose check went through, mail or none, and a hash of the vehicles on their
list then; later runs that day skip them while the list is the same. News found after it on those
vehicles goes with the next mail; a vehicle that joins the list, added or given its first live
reminder, is checked that day. Before, every hour re-evaluated every vehicle of every recipient
with nothing new.

The same prediction could warn on a leasing mileage overrun. That is in the
[backlog](features.md#feature-backlog) rather than a planned milestone: it needs the contract's end
date and mileage cap, two columns no milestone has added.

## Numbers: consumption, cost, emissions

Every fuel tracker gets this wrong at least once, so specify it before writing code.

**Consumption is only defined between two full tanks.** Take a full fill-up A and the next full
fill-up B: sum the amounts of every fill-up *after* A up to and including B, divide by
`odo(B) − odo(A)`, times 100. Partial fill-ups are aggregated into the segment, never divided on
their own, and a vehicle's first fill-up yields no consumption at all.

- A `missed_previous` flag (paid cash, forgot the receipt) **skips** the segment. So does a flagged
  or derived reading at either end, an end without a counter, or a counter replaced in between
  ([odometer rules](#odometer-rules)).
  A gap must produce no number rather than a wrong one.
- Each energy is a chain of its own, and the distance is always the main counter's: per 100 km, or
  per hour on a vehicle counted in hours. A truck that also counts engine hours is measured against
  its kilometres, so its figure includes the fuel burnt working. `ConsumptionService` is the one
  place this is computed.
- **Electric gets a second figure, not a broken first one.** There is no wall-side equivalent of a
  full tank: people charge to 80 %, at three different chargers, and never to 100 %. The segment
  rule stays as it is and simply rarely fires for electricity. Alongside it, show a **rolling
  wall-side kWh/100 km** over all charges in the period, labelled approximate and noted as
  including charging losses. Two honestly-labelled numbers beat one that is silently wrong on most
  rows. It is every live charge with its time in the period, over the period's distance (below);
  no charge or no such distance, no figure. On a hybrid that distance includes the kilometres
  driven on fuel, one more reason it is approximate.
- **EVs count from the wall.** kWh drawn ≠ kWh stored — AC charging loses 10–20 %. Home vs. public
  is a separate dimension, not a correction factor.
- **Hybrids show both figures side by side**, driven by `energy_types`. A blended number means
  nothing to anybody.

**A period's distance** runs from where the counter stood at its start — the newest unflagged
Reading at or before it, or the first one after when there is none — to the newest unflagged
Reading at or before its end. So consecutive months add up to the year, and a month with one
Reading in it still moved. An answered reset in between ends one segment and starts the next, and
the segments add up ([rule 3](#odometer-rules)). A segment that ran backwards hides a question
nobody answered, so the period has no distance. `ConsumptionService::distance()` reads the two
ends, the resets between them and the end of each segment a reset closes, one indexed lookup
each — never the whole chain. The resets are filtered by `kind`, which no index names: the
database scans the period's index range and hands back only those few rows.

**Cost per 100 km** = energy + maintenance + expenses in the period ÷ km driven in the period, × 100.
Show it next to energy-only cost per 100 km; the distance between the two is the actual story of the vehicle.
`CostService` computes both, per 100 km or per hour as consumption is, over the same distance the
wall-side figure uses. A fill-up in the period without a price makes the figure *incomplete*. With no
distance in the period, the cost is the period's total. A period with no fill-up, record or expense
has no cost rather than zero — and so no TCO — since 0 would mean both "nothing spent" and "nothing
recorded", and a new vehicle would swing against months in which it did not exist. The header shows
such a period as zero and compares nothing with it. Under "I reclaim VAT" each row counts net of
its own rate; a row without a stated rate counts gross, and the figure says so.

**The Costs screen's year** is the same figure, once for the year and once per month, from
`GET /api/vehicles/{uuid}/costs/{year}?tz=`. The client names the zone and the server cuts the
twelve months at its midnight. Old IANA aliases count, since browsers still report some zones by
them (`Asia/Calcutta`). Each cost also states its maintenance total and its expenses per
category, in the sheet's order, then any word the sheet does not offer, then the uncategorised:
they are unstated, not "other". The
three bands are energy, maintenance and the expenses' sum. An empty month has no cost, like any
empty period. The thirteen costs come from one read of each table over the year and one
`ConsumptionService::distances()` call, which looks each month edge up once; the holding's distance
is read once for all of them. The year costs the same number of queries however long the
vehicle's history is (`KpiTest`).

**TCO** adds depreciation: (purchase − estimated residual) ÷ km over the holding period. Both fields
are optional, and an empty field hides the KPI rather than inventing it. The holding period's
distance is the main counter's distance over its whole life, segments summed — not the period's.
Neither price carries a VAT rate, so both count as entered, net preference or not. This is the
number that decides buy vs. lease, and none of the tools in
[the prior art](features.md#what-existing-tools-teach-us) shows it.

**Nothing is summed across currencies or units.** Currency and `odo_unit` are per vehicle. A
cross-vehicle report groups by both and shows sections — never a total, and no FX conversion, ever.
KPI labels derive from the vehicle (`€/100 km`, `€/h`), and a vehicle with no distance in the
period shows cost as a period total instead.

**CO₂** = amount × emission factor per energy type, from a versioned table in code with the
source cited (`IRateProvider::emissionFactorAt()`), read on the fill-up's own day, and the source's
year beside it where the country states one (`emissionSourceYear()`). Electricity uses
a grid factor instead: the person's own from the personal settings, or the country's newest average
with its year and source (`gridFactor()`). One figure, not a table, because a grid average is
published years late. The personal figure is the reader's, so two people sharing a car can see
two estimates. A fill-up without a factor is left out and the figure says so; CNG has none,
since it is entered in litres of gas at no stated pressure. `EmissionService` computes it, once
per year, for the Costs screen. Always labelled an estimate. Under the generic jurisdiction there
are no factors, so the figure is unavailable rather than zero.

**VAT:** store gross plus `vat_rate`, derive net — on energy, maintenance and expenses alike. A
freelancer's largest reclaimable VAT is a workshop invoice, not a tank of diesel.
