# Architecture

How NextFleet works inside, and why.
The words are defined in [CONTEXT.md](../CONTEXT.md).
The pictures are in [design/](design/README.md).

## Stack and layers

NextFleet is a standard Nextcloud app with no external service.
It uses the public `OCP` API only, as the app store asks.

- **Backend.**
  Controller → Service → QBMapper → Entity.
  Controllers are thin.
  Every rule lives in a service.
- **Frontend.**
  Vue 3, Vite, Pinia and `@nextcloud/vue`.
  One bundle runs on Nextcloud 31 to 35.
  The settings block is a second bundle: mounting the fleet in a settings section would load every view for one dropdown.
- **Two doors, one rule.**
  The web UI uses internal routes.
  The OCS API under `/api/v1` twins them and is the public contract ([ADR 0009](adr/0009-the-ocs-api-v1-is-the-public-contract.md), [api.md](api.md)).
  Both doors make the same service call.
  The JSON keys are the column names, so a client reads back what it may send.
  What it may not send is stated once, in the service.

## Data model

The odometer is not a field you edit.
Each Entry that knows a counter writes a Reading, and the vehicle caches the newest value.
So trips, fill-ups and maintenance cannot drift apart.

[design/data-model.md](design/data-model.md) draws every table and column, and [design/lifecycles.md](design/lifecycles.md) the states.
Tables start with `fleet_`.
Names stay under 27 characters, because Nextcloud adds `oc_`.

### Values

- **Integers only:** cents, kilometres, millilitres, watt-hours.
  No floats.
  `unit_price` counts tenths of a cent, because pumps price to a tenth.
- **Money carries a `currency` and a `vat_rate`.**
  A report that mixes net and gross is useless.
- **The currency freezes** once an amount is recorded in it, voided ones included.
  A new code would relabel every amount without converting one: 400 `currency_in_use`.
  A vehicle without a valid code can still take one, because naming a currency only labels the amounts.
- **`vat_rate` null means "not stated"**, never zero.
  A receipt without a VAT line and a zero-rated cost are different facts.
  A category the country charges no VAT on (`IRateProvider::vatFreeCategories()`) is prefilled with 0.
- **No column is named after a unit it might not hold.**
  `odo`, not `km`, because a tractor counts hours.
  `fleet_energy.amount` is millilitres or watt-hours, as `energy` says.
- **`engine` classifies, `energy_types` decides.**
  `engine` is for display and defaults.
  `energy_types` decides which entries and figures exist.
  A create without energies takes them from the engine (`VehicleService::ENGINE_ENERGIES`).
  An update derives nothing.
- **Flags are computed on read, never stored.**
  A fill-up of an energy outside the set, one without a price, and one above `tank_ml` or `battery_wh` are saved and flagged (`EnergyService::flags()`).
  The capacities never refuse a save.
- **`vehicle_type` drives the rules, the EU codes are data** ([ADR 0010](adr/0010-the-vehicle-type-drives-rules-the-eu-codes-are-data.md)).
  The server refuses a code outside the EU lists and a body type that does not fit the category.
  It judges the pair only when the pair changes, so a stored misfit does not block other edits.
- **The plate is a label, the `uuid` is the identity.**
  A new plate renames the vehicle, leaves an audit row, and breaks no history.
  Papers point at files by `file_id` for the same reason.
- **`lifecycle`, not an `active` flag:** `active`, `laid_up` (reminders pause) and `disposed` (reminders stop, the vehicle leaves the fleet list).
  A boolean cannot tell a seasonal plate from a scrapyard.
- **No `hu_due` column.**
  The next inspection is a reminder from the country's scheme.
  A German date in a core table would be a second truth, and a country the core must not know.

### What every table carries

- `uuid`, `created_at`, `updated_at`, `deleted_at` and `created_by`.
  The `uuid` is the identity, `updated_at` the token for [concurrency](#concurrency) and [sync](api.md#sync), `deleted_at` the trash.
- A `<name>_off` beside each instant a user entered ([Time](#time)).
- No catch-all JSON blob.
  A country that needs a field adds a column in a reviewed migration.
  A field outside the schema is a field nobody maintains.
  This is why notification receipts are a table.
- **Booleans are nullable with a default.**
  Nextcloud's schema check refuses a `NOT NULL` boolean, and NC 31 enforces it.
- **Typed text is 4n long on Oracle.**
  Oracle's `VARCHAR2(n)` counts bytes, and UTF-8 needs up to four per character.
  The bounds stay in characters.
  `Db\TypedText` lists these columns, and `TypedTextTest` and `OracleSchemaTest` guard the list.
  Migration 9 keeps its own frozen copy, because a shipped step never changes.
  Codes the app makes stay at n: they are ASCII.
- **Indexes are part of the schema, not an optimisation.**
  A unique `uuid` everywhere, `(vehicle_id, <time>)` on child tables, `(vehicle_id, updated_at, id)` for sync, `(created_by)` for erasure and export.
  Index names stay under 27 characters and unique across tables, because PostgreSQL and Oracle name them per schema.
- **A list in a query goes through `Db\InList`.**
  Oracle refuses an `IN` of more than 1 000 items ([Oracle](development.md#oracle)).

### The audit trail

- **It sees only changes made through the app** ([what that means in a tax audit](legal.md#a-german-tax-audit-can-reject-the-logbook)).
- A row's author and instant are its `created_by` and `created_at`.
  It is written in the same transaction as the change and never updated.
- **What kind of change it was lives in `diff_json`, not in a column.**
  The trail must describe tables that do not exist yet.
- `diff_json` is `{"change": …, "fields": {name: [before, after]}}`.
  A creation starts at null.
  A field nobody stated is left out, because a diff that lists what did not change buries what did.
  Facts the change carried, such as `late` or `derived`, are keys beside these two.
- The words: on a trip `created`, `edited`, `voided`, `restored`; on a vehicle `switched`, `edited`, `transferred`.
  A Reconciliation Trip is `created` with `derived: true`.
- **An undo is a change too.**
  A trail that stopped at the void would say "voided" over a trip the export lists as driven.
- An edit that changes nothing writes no row and moves no token.
- It is written for a trip under [Logbook mode](#logbook-mode), and for each mode flip and each change of a vehicle's plate, jurisdiction, currency or type.

### Deleting and erasing

**Nothing purges yet.**
Deleting a vehicle stamps its `deleted_at` and touches no child row, so an undo brings it back whole.
No route, command or job removes a row.
Two hard deletes exist: taking a recipient off a list, and a grant whose grantee was gone at its commit.
Opt-in retention will be the first purge, and must then take the child rows with it.

**Deleting an account pseudonymises, it purges nothing** ([ADR 0008](adr/0008-erasing-a-driver-pseudonymises.md)).
`UserDeletedListener` hands the uid to `ErasureService`.
In one transaction, one random `erased:…` pseudonym replaces it in every account column: `created_by`, the owner, receipts, bookings and user grants.

- Ownership and grants are renamed too, because Nextcloud lets a deleted uid be taken again.
  The new account must inherit nothing.
  A uid cannot hold `:`, so no account can take the pseudonym.
- The account's reminder-list entries are deleted: a deleted account receives nothing.
- A new table that names an account declares `accountColumns()` and joins `AccountTables`.
  The erasure and the [personal data export](#personal-data-export) share that list.
- Most accounts never used the app, so the erasure first asks each table for one row, and touches only the tables that answer.
- Renamed rows keep their `updated_at`, so every sync starts over (`SyncEpoch`).
- Pseudonyms from before 0.3.0 (`erased-…`) are renamed by the repair step `ErasedPseudonyms`.
  One that a live account carries stays and is reported: it may be a real person.

**An erased owner's vehicles close.**
Nobody is left who may decide over them.
In the same transaction, each one loses its grants and goes to the trash.
An admin who wants a pool to outlive its owner runs `occ nextfleet:transfer` first (`TransferService`).
The grants stand, the former owner keeps `viewer`, the new owner joins the recipients, and an audit row records it.

**Deleting a group revokes its grants and takes it off the booking lists.**
A group made later with the same id must reach nothing.
`GroupDeletedListener` hands the id to `GrantService::forgetGroup`, which revokes one vehicle at a time under its hold and clears the lists in one statement, both under one pending mark.
The members are gone by then, so `BeforeGroupDeletedEvent` notes which recipients were members first.
A grant whose grantee vanished during the grant is removed after the commit.
That erases nothing: an LDAP backend briefly out of reach says "no such user" for a live account, and an erasure cannot be undone.

**Both finish, whatever fails.**
Nothing asks again once the event has passed.
So each job is marked pending in the app config before it starts (`Service\Pending`) and unmarked when done.
The hourly `PendingJob` runs a marked one again, and `occ nextfleet:pending --finish` runs it now.
A second run changes only what the first left.

- A pending erasure whose uid an account holds again is dropped with a warning: that may be a real person.
- A pending group is revoked even if the id exists again.
  A grant the owner can give again costs less than a new group that inherits the old one's cars.

Every write that names an account asks again, under the vehicle's hold, whether the account exists.
The erasure takes all its holds in one id order, so two erasures never deadlock.

### Bookings

**A booking is a plan, not an Entry.**
It writes no Reading and is on no timeline, logbook or report.

- **Its span is half-open**, `[starts_at, ends_at)`, so noon to noon does not collide.
  Only a `booked` or `out` booking holds the vehicle.
  An `out` one holds it from the earlier of start and check-out until it is back, however overdue.
- **A double booking is refused, not flagged.**
  Preventing it is what a pool is for.
  Under the vehicle's hold, an overlap is a 409 that names the booking and its booker.
- Only an `active` vehicle takes a booking, and only one that ends in the future.
  A past booking is a trip.
  Only a `booked` booking changes or is cancelled.
  A cancel keeps the row.
- **The handover is two moments of the booking**, so it is columns, not a table: `out_*` and `in_*`.
  It takes no `updated_at` token: what counts is the state when the car changes hands.
  The instant is the server's.
  Check-in works on any vehicle: a car laid up while out still comes back.
- Its `flags` (`odo_below`, `late`) are computed on read and never refuse.
  `BookingMapper::findCountersAtOut` reads a whole page in three queries: a `MAX` in a join condition grew with the square of the bookings.
- **A returned booking becomes a trip only through the driver.**
  `trip_draft` prefills the entry sheet, and `POST …/trips` with `booking_uuid` ties the two (`BookingService::tie`).
  The check-in logs no trip itself: a business trip needs a purpose and a partner the app would be inventing.
- **A vehicle says who has it:** `out_with`, `my_next_booking` and `ever_granted`.
  The list fills them for all vehicles with one query each, never one per vehicle.
  Overdue is the client's to say.

### Booking approval

The owner may make a vehicle's bookings wait for an answer ([ADR 0011](adr/0011-a-small-firm-may-opt-into-approval-and-hidden-costs.md)).

- **A vehicle follows its owner, or not.**
  The column `booking_approval` is true for always, false for never, and null for as the owner's default says.
  The default is the owner's preference `booking_approval`, off until set ([ADR 0012](adr/0012-nextfleet-also-serves-a-small-fleet.md): one switch for a small fleet).
  `VehicleMapper` reads it with every vehicle, so `Vehicle::needsApproval()` answers alone.
- **Off approves what waits**, as the owner, whichever way approval turns off: the vehicle's rule (`VehicleService::update()`) or the owner's default (`VehicleService::approveByDefault()`).
  The default approves nothing in the trash, so a vehicle restored without approval approves what waits on it then (`VehicleService::restore()`).
  The default is written under the holds of all the owner's vehicles, trashed ones too, and a booking or a restore reads it again under its vehicle's hold, from the database: `IConfig` keeps what it read for the rest of a request.
  A new default moves the `updated_at` of each vehicle that follows it, so sync sends them and a client that read the old answer saves into a 412.
  A transfer and the vehicle's own switch read the default from the database too, under their hold.
- **A transfer pins the rule** to what it was, since the new owner's default is not the one the drivers booked under.
- **The answer sits beside the state, not in it.**
  A booking waiting for one is `booked` with `approval: requested`, so a client that knows the four states still reads it.
  `approval` is null where the vehicle asks for none.
- **The owner and the managers answer** (`VehicleAccess::mayApprove`): approve, ask back, or decline, each with an optional comment.
  A booking they make is approved by making it.
  Declined is `cancelled`, so the span is free; asked back waits for the booker and can still be answered.
  Each answer takes the `updated_at` the approver read, so nobody answers a booking that moved since.
- **A request holds its span**, as any `booked` booking does, and the 409 says so with `approval`.
  It is not checked out until it is approved.
  Approvers may answer it until its end; then `PendingJob` declines it within the hour, by nobody and with no comment.
- **An answer is to a span.**
  A new start or end makes the booking a request again, unless an approver moves it; a new purpose keeps the answer.
  Asked back, the booker answers by changing the booking, a new purpose too: it is a request again.
- **A cancel keeps the last `approval`** as history, so a client reads `approval` with the state.
- ***Take it now* asks nobody** while the car is back by midnight on the booker's clock.
  Midnight is the next one after the server's now, not after the start, which may lie five minutes ahead.
  The clock is the booker's Nextcloud time zone, or the offset the sheet sends when they set none.
  Kept longer, it is a request, and the sheet leaves it booked.
  The owner may block it per driver: `fleet_access.take_now` false takes `VehicleAccess::TAKE_NOW` off that grant, and the driver's take-now is a request like any booking.
  Widest wins, as for roles; a role that covers `edit` approves its own anyway, so its value narrows nothing.
  The vehicle's `may` carries the word, so the sheet foresees the request by the server's answer.
- **The comment is the booker's and the approvers'.**
  Everyone else reads `approval_comment` as null.
- **Notices:** a request goes to the owner and the managers whatever the list says; each answer goes to the booker, in place of the one before.
  A question carries the comment, shortened to 200 characters, and only the booker gets it.
  A new owner and a new manager are asked the requests that wait already, after `TransferService::transfer` and a grant that gives `edit` (`BookingNotices::tellWaiting`).
  A notice shows only while the booking's approval is still the one it was sent for, and a request asked again takes the earlier notices back.
  The list hears of a booking once it is approved: `booked` the first time, `changed` after a new span is approved again (`fleet_bookings.announced`).
  It hears `cancelled` only of a booking it heard of; a request declined or never answered is no news to it.

### Booking notices

Nextcloud notifications only, sent after the commit by `BookingNotices`.

- **The booker hears of a cancel by somebody else.**
- **The vehicle's list hears five events**: `booked`, `taken`, `returned`, `cancelled`, `changed`.
  A booking that starts within five minutes of now is *Take it now*, so it is `taken`, not `booked`; one that waits for approval is `booked` once approved.
  Only a new start or end is `changed`; a new purpose alone is not.
- **The list is `fleet_booking_recipients`**: accounts and groups, the owner's to write, a manager's to read.
  A group is its members when the event happens.
- **Each row hears its own `events`.**
  Somebody on two rows, by name and through a group, hears what either row hears, one notice per event.
  An empty list means the owner, who hears the vehicle's `booking_notices`; a new vehicle's are `booked` and `taken`.
  A new row starts with the vehicle's `booking_notices`, so they are also the list's default.
- **Nobody hears of their own action**, and a booker never hears of a cancel twice.
- **Only those who see the vehicle are told**, then and when they read it.
  Being on the list grants nothing, so a member who lost the car needs no pruning.
- **A notice stores no name.**
  It carries the span, or the counter and level of a return, as they were.
  The notifier names the booker from the booking when the list is read, so an erasure reaches it.
- Deleting the vehicle takes back every notice of its bookings.
  Undoing the delete asks again what waits for an answer: each request of the approvers, each question of its booker.

### Booking for a driver

The owner or a manager may book a vehicle for somebody else ([ADR 0012](adr/0012-nextfleet-also-serves-a-small-fleet.md)).

- **The booking is the driver's.**
  `user_id` is the driver, `created_by` the approver (`Booking::madeByAnother()`).
  The driver changes, cancels and takes it as their own; a move by them asks for an answer again, as any of theirs does.
- **Only somebody who may book the vehicle** is named: `log` on it, through a grant or a group, or the owner.
  The name is stored as the instance spells it (`Names::spelled()`), whatever case the request sends.
  A viewer, an account without access and a group are refused, so a booking hands out nothing.
  `…/bookers` lists them by the same rule (`VehicleAccess::holders()`), which also finds the approvers a request goes to.
- **It stands at once.**
  The approver made it, so it is approved by them where the vehicle asks for approval, *Take it now* too; overlaps are refused as for any booking.
  A retry under the same `client_uuid` asks nothing about the booker again.
- **`seen_at` is the booker's and the approvers'**, as `approval_comment` is; anybody else reads null.
- **The driver confirms it.**
  Their notice has a **Seen** button, which posts `…/bookings/{uuid}/seen`; `seen_at` is the first confirmation.
  Nothing waits on it, and the maker hears nothing of it.
  The notice goes once confirmed, or once the booking is cancelled.
  The trash takes it back, and a restore sends it again while the booking is still to come and not confirmed.
- **Notices:** the list hears `booked`, without the driver, whose own notice is the one with the button.
  It hears `booked` also for a booking that starts now: nobody took the car yet, and the check-out tells nobody.
  A cancel by the driver tells whoever made the booking, also outside the list, once.
- An erasure reaches `user_id` and `created_by` as it reaches every account column ([ADR 0008](adr/0008-erasing-a-driver-pseudonymises.md)); `seen_at` names nobody.

## Time

Every user-facing instant is two facts: the UTC instant and `<name>_off`, the offset in minutes where it happened ([ADR 0007](adr/0007-time-is-an-instant-plus-an-offset.md)).
A Fahrtenbuch is judged on local dates, and a trip that ends 00:30 in Berlin is on the day before in UTC.

- Reports derive the local date from the pair.
  Order and durations use the instant.
- The entry's own instant is the user's and editable.
  A plain date such as `first_reg` takes no offset.
- `created_at` is the server's, from `ITimeFactory`, never from a client.
  Timeliness is the gap between the two.
- Never call `time()` or `new DateTime()` in app code ([testing](development.md#testing)).

## Concurrency

An update sends the `updated_at` it read.
A stale one gets **412**, never a silent overwrite.
Two browser tabs are the case today, and sync stands on the same rule.

- **One statement checks and writes:** `UPDATE … WHERE id = ? AND updated_at = ? AND deleted_at IS NULL`.
  The token always moves at least one second on.
  Otherwise two writes in one second would leave the second token looking fresh.
  `uuid`, `created_at` and `created_by` are never writable.
- **An undo moves the token too.**
  Otherwise a client that syncs by `updated_at` never sees a restore.
  The delete's token is spent.
  A restore that matches nothing is a 412.
- **Recomputed columns check no token.**
  `odo_value` and a Reading's `flagged` follow from the readings.
  Two entries saved together would otherwise tell the second it lost a race it was never in.
  `odo_value` does not move the vehicle's token, or an entry would refuse an open vehicle sheet.
- **A changed flag moves the Reading's token**, so sync sends exactly those Readings.
  The price: a sheet open on such a Reading saves into a 412.
  That is rare, and honest.
- **On the wire**, a `PUT` carries the token in the body and a `DELETE` in the query.
  No token is a
  400. Nextcloud's failed CSRF check is a 412 too, so ours carries `"conflict": true`.
       The client raises its own error for it, and the sheet stays open with the values ([ui](ui.md#the-entry-sheet-in-detail)).
- **A create may arrive twice** ([retried creates](api.md#retried-creates)).
  `Once` looks up the `client_uuid` before validation and again under the vehicle's hold.
  The unique `uuid` index catches twins that the hold does not order.
- The server time header is a diagnostic.
  It warns about a wrong device clock and never rewrites what the user typed.

## Odometer rules

This is where logbooks quietly break.
Six rules:

1. **Readings are ordered by `(read_at, id)`, never by value.**
   A trip entered three days late is dated three days back.
2. **`odo_value` caches the newest Reading**, and each write to the chain recomputes it.
   Nothing is incremented in place.
   Each such write first holds the vehicle's row with an `UPDATE` that changes nothing, so a second writer waits and then reads the first one's Reading.
   No read writes the cache and no nightly job rewrites it: that would only hide a bug.
   `occ nextfleet:check` finds drift, and `occ nextfleet:recompute` settles it under the same hold.
3. **A lower reading is a flag, not an error.**
   Cluster swaps, engine changes and imports really reset counters, and the app cannot tell them from a typo.
   The Reading is saved and flagged, and the timeline asks: *Counter replaced* or *Typo*.
   *Counter replaced* writes `kind = reset`, which starts a new segment.
   A distance adds up its segments.
   A consumption segment over a reset gives no number, and a Gap is measured from the reset.
   An edit that changes the number asks again.
4. **`value` is km or engine hours**, per vehicle.
   `second_unit = h` adds a second chain of engine hours with its own order, flags and cache (`second_value`).
   Trips, Gaps, Logbook mode and the Fahrtenbuch read the main chain only.
   The main unit never changes once Readings exist: their numbers mean what the unit meant when they were read.
5. **An Entry writes its Readings at its own moment:** a trip one, at `ended_at`, on the main chain; a fill-up or maintenance record one per counter given.
   A trip's `start_odo` is a claim, not a Reading.
   Comparing it with the Reading the last trip left is gap detection.
   A Reading no trip wrote accounts for no kilometre.
   The Reading goes where its Entry goes: a void or an undo takes both, in one transaction.
   An edit restates the same Reading, and counts its number again only when the counter, the distance or the start changed.
   An Odometer Entry is its own Reading.
6. **Observed beats derived.**
   A trip with a distance but no counters writes a derived Reading: the latest Reading before the start, plus the distance.
   Consumption needs observed Readings at both ends.
   When a later observed Reading contradicts the derived ones, it wins, and the derived ones are flagged, never silently corrected.
   If it is still below the last Reading left standing, it is flagged too.

## The timeline

One vehicle, one timeline: every dated table merged, newest first, 50 rows a page.

- **The server merges, because the server pages.**
  A client would need every table's rows to know which 50 come first.
- **The order is `(occurred_at, kind, id)`.**
  Two rows can share an instant, and a page boundary without a total order drops or repeats a row.
  `kind` ranks by its place in a fixed list.
  A new kind goes at the end, because reordering would reorder pages someone is scrolling.
- **The cursor is that key, handed back.**
  One the client invented is refused.
  Each table is asked for 51 rows, which says whether a next page exists.
- **A row is an Entry, not a written row.**
  A trip carries the Reading it wrote, so the Readings query lists only Odometer Entries.
  Voided rows are left out.
- A row carries what is computed on read: a trip's `missing` fields and `flags` (`overlap`, `overtaken`), a fill-up's `flags` and `consumption`.
  The consumption reads the whole chain, since the fill-up that opened the segment may be pages away.
- One row reads back alone, for *Save anyway* after a lost race.
- **The Gaps are a route of their own, not paged.**
  A month header states the whole month.
  A figure that grew as rows scrolled in would state a number that is not true yet.
- **Closing a Gap** sends the distance and the two moments the driver saw.
  The server finds the Gap again and closes it only if all three still match, else 412.
  It holds the vehicle first, so two confirmations close it once.

## Numbers: consumption, cost, emissions

Every fuel tracker gets this wrong once, so here it is before the code.

**Consumption exists only between two full tanks.**
From full fill-up A to the next full fill-up B: the amounts after A up to B, divided by `odo(B) − odo(A)`, times 100.
Partial fill-ups go into the segment.
The first fill-up gives no number.

- `missed_previous`, a flagged or derived end, an end without a counter, or a reset in between gives no number.
  A gap must produce no number rather than a wrong one.
- A full fill-up at A's own reading is not a B.
  It measured no distance.
- Each energy is its own chain.
  The distance is always the main counter's.
  `ConsumptionService` is the one place this is computed.
- A vehicle that also counts engine hours gets a figure per hour on the second counter.
  Both figures can rest on different segments.
- `measured` names the energies that ever closed a segment, so the header can say what a figure needs instead of staying silent.
- **Electric gets a second figure, not a broken first one.**
  Nobody charges to a "full tank".
  Beside the segment rule, a wall-side kWh/100 km over all charges in the period is shown as approximate, charging losses included.
  Two honest numbers beat one that is wrong on most rows.
- **Hybrids show both figures side by side.**
  A blended number means nothing.

**A period's distance** runs from the counter at its start to the counter at its end: the newest unflagged Reading at or before each, or at the start the first one after.
So the months add up to the year.
Resets split it into segments that add up.
A segment that ran backwards hides an unanswered question, so the period has no distance.
`ConsumptionService::distance()` reads the ends and the resets with indexed lookups, never the whole chain; the Statistics screen is the exception below.

**Cost per 100 km** is energy plus maintenance plus expenses, divided by the distance, times 100, shown beside the energy-only cost (`CostService`).

- A fill-up without a price makes the figure incomplete.
- Without a distance, the cost is the period's total.
- A period with no rows has no cost, not zero.
  Zero would mean both "nothing spent" and "nothing recorded".
  The header shows it as zero and compares nothing with it.
- Under "I reclaim VAT", each row counts net of its own rate.
  A row without a rate counts gross, and the figure says so.
- The Statistics screen shows the same figure per month or year of its range (`StatisticsService`, [API](api.md#statistics)).
  One exception: a bucket with a distance and no rows rates 0, so the line has a point wherever the vehicle moved.
  The range is its buckets' rated costs over their distances (`CostService::rated()`), so it lies between the lowest and highest point.
  The year route rates its year the same way over its months.
  The client names its time zone, and the server cuts the buckets at that zone's midnight.
  Its buckets may be many, so it reads each vehicle's counter over the range and its fill-ups once, and finds the bucket edges among them (`CostService::lean()`, `ConsumptionService::distancesAlong()`).
  It skips engine hours and TCO, which it does not show.
  The query count does not grow with the buckets (`StatisticsTest::testTheQueriesDoNotGrowWithTheBuckets`).

**TCO** adds depreciation: (purchase − residual) ÷ distance over the holding.
An empty field hides it.
Neither price has a VAT rate, so both count as entered.

**Nothing is summed across currencies or units.**
No exchange rates, ever.
The labels come from the vehicle: `€/100 km`, `€/h`.

**CO₂** is the amount times a versioned factor on the fill-up's day (`IRateProvider::emissionFactorAt()`).
Electricity uses the person's own grid factor, or the country's newest average with its year, because a grid average is published years late.
CNG has no factor: it is entered in litres at no stated pressure.
Under `generic` there are no factors, so the figure is unavailable, not zero.
It is always labelled an estimate.

## Logbook mode

A per-vehicle switch, off by default: private users do not need the friction.
The rules are a *ruleset*, and Germany's is the first.
A German tax office accepts an electronic logbook only if entries are timely, complete and [protected against unnoticed later changes](https://www.haufe.de/personal/entgelt/nachbesserungen-im-fahrtenbuch-sind-unzulaessig_78_170740.html).

Under the mode, trips are append-only.
An edit rewrites the trip in place and writes an audit row with the diff.

- **The trail follows the trip, not the switch.**
  A change is recorded while the mode is on, and later for any trip that set off inside a period the mode was on.
  A trail that followed the switch could be switched off to change a kept logbook unseen.
- **The logbook's questions are computed for every vehicle and shown only under the mode.**
  One rule for completeness and gaps.
  A vehicle that switches on shows at once what its trips lack.
- **Incomplete is a flag.**
  A trip without a required field is saved and listed as incomplete.
  It is computed on read, so a changed ruleset changes it for every trip.
- **A Gap is a claim above where the last trip left the counter.**
  A trip's `start_odo` is measured against the newest Reading a trip wrote before its start ([rule 5](#odometer-rules)).
  A higher claim leaves the difference unaccounted.
  A Reading without a journey, such as a fill-up, proves the counter moved, not who drove.
  So it accounts for no kilometre.
  But a question between the two (a flagged Reading, one above the claim) opens no Gap until it is answered.
- **A Gap is closed by one confirmation, one Gap at a time.**
  It creates one private trip marked `reconciled`, and its audit row says derived.
  Never a batch, never a business trip: the app knows how far, not why.
  An auditor who finds honest reconciliations is better off than one who finds invented business trips.
- **A delete voids.**
  The row stays, an audit row records it, and the export lists it as voided.
  Undo still works everywhere and is recorded too.
- **Switching on locks nothing retroactively** ([ADR 0003](adr/0003-logbook-mode-does-not-lock-the-past.md)).
  The export states when the mode began.
- **Every flip is an audit row**, on and off.
  The export reads the periods from them.
  Switching off is allowed, because a mode that only goes on is a trap.
  It asks first.
- **The lock delay belongs to the ruleset** (`ILogbookRules`, with its source URL).
  Germany's is seven days, and its records are kept ten years.
  A late change is allowed and shown on the export.
  The delay runs from the earliest end the trip ever had, so moving an old trip to yesterday does not reset the clock.
- **What is German:** the required fields, the lock delay, the retention and the export layout, in `lib/Jurisdiction/De/`.
  Append-only, voiding and the audit trail are core.
  Under `generic` the mode works and requires no fields.

It is an aid, not a certification, and no lawyer reviewed it ([legal](legal.md)).

## Reports and exports

### The Fahrtenbuch export

`GET /apps/nextfleet/vehicles/{uuid}/logbook/{year}` answers with the page itself ([ADR 0005](adr/0005-no-pdf-library.md)), so it is outside `/api`.
The browser navigates to it, and a navigation carries no request token.
So the route takes none, writes nothing, and still needs the same-site cookie.
It is rate-limited and logged by ids ([security](security.md)).
Its CSP allows inline style and nothing else.

- **The core decides what is in it, the country how it reads.**
  `LogbookExport` hands a `LogbookReport` to the country's `IReportRenderer`: the year's trips by local date, voided ones included, what each lacks, its late changes, and the mode periods with their plates.
  A country without a renderer answers 404, not an empty page that passes for a logbook.
- **A late change is on its trip's line**, with what each field said before.
  Late is decided at export, against the export's rules, not by the flag the row stored.
- **A change that left no audit row** was made around the app.
  The line says *Geändert ohne Protokoll am*.
  Defence in depth, not a rule anything relies on.
- **Outside the mode**, every trip is listed, but only trips that set off inside a period say what they lack.
  An auditor must not read "incomplete" on months no logbook was kept.
  Server instants print in the reader's time zone with the offset written beside them.
- **Under `generic`** it is a plain listing in the reader's language, with no periods, missing fields or late changes.

### The mileage claim

`GET /apps/nextfleet/vehicles/{uuid}/mileage/{year}` is served like the logbook.
It takes `costs`, not `view`: it puts a money value on the trips.
It exists where the country has rates and a renderer, for vehicles in kilometres.

- **The core values, the country prints.**
  Each business trip of the year is its kilometres times `IRateProvider::mileageRateAt()` for the vehicle type on that day, rounded half up once.
  No rate or no kilometres: "not stated", left out of the total.
- **What the tax office would not accept is listed, not summed.**
  A trip with missing fields or a Reconciliation Trip keeps its amount out of the total, and the page says why.
- **Commutes are not on it.**
  They are a different deduction.
- **A claim is the reader's.**
  It lists only the trips the reader entered.

### CSV export

`GET /apps/nextfleet/vehicles/{uuid}/csv/{year}/{table}`, for `trips`, `energy`, `maintenance` or `expenses`.
A link like the logbook: no token, rate-limited, logged by ids, `view` access.

- A row is in the year of its own offset (`LocalYear`).
  A voided trip is included, with `voided` 1.
- The figures are the stored integers under the field's name, with `currency` beside money.
  An instant is its local time plus its offset.
  So nothing depends on the reader's decimal separator.
- The file is for a spreadsheet (`Csv`): UTF-8 with a BOM, comma, CRLF, and formula injection defused ([security](security.md#hostile-content)).

### Personal data export

`FleetMigrator` exports one account for Nextcloud's *user_migration* app: a JSON file per table in `AccountTables`, every row whose account column holds the uid, deleted rows included.

- **A row on a vehicle the account no longer sees is withheld.**
  Shared state, such as a vehicle handed to a new owner, would show somebody else's data.
  Such a row is reduced to its id, its writer and when it was written (Art. 15(4) GDPR).
  What the account wrote itself is exported whole (`FleetMigrator::AUTHORED`).
- **A booking is the booker's.**
  It is the account's own only where `user_id` names it.
  One it answered (`approval_by`) or made for somebody else (`created_by`) holds the booker's purpose, counter and notes.
  Such a booking is exported whole while the account sees the vehicle.
  Else it is reduced to its id, when it was made, its span as it stands, and what the account wrote: that it made it, its answer (`FleetMigrator::ANSWER`).
  An answer somebody else gave later stays out.
- **A vehicle row carries its prices only while the account may see costs** ([costs](api.md#costs)).
  Else `purchase_price` and `residual_est` are null.
- **Export only.**
  An import reads nothing: on a server with other people and vehicles, nobody can decide whose vehicle a row belongs to.
  It does not throw, because that would fail every other app's import.

## Reminder engine

One rule set for the templates (HU/AU, oil change, brake fluid, tyre swap) and for reminders with their own title.

1. A reminder is due by date, by odometer, or by whichever comes first.
2. A date reminder warns at its ticked points: a month before, at the start of the due month, on the due date.
   An odometer reminder warns at `due_odo − lead_odo`.
3. On trigger: an in-app notification and an entry in the mail digest.
4. **Completing it is a maintenance record that names it** (`reminder_id`).
   A recurring reminder then moves to its next occurrence, due from the **actual** completion, not the planned one.
5. **Prediction needs data to be honest.**
   The pace comes from the last 90 days and needs 30 days and two Readings.
   Below that, the UI says "not enough data yet".
   No usable pace means no estimated date.
6. **States:** `planned → warned → due → overdue → done`, plus `snoozed` and `dismissed`.
   Dismissing skips this occurrence, and the recurrence goes on.
   Ending a reminder means deleting it.
   A reminder you cannot snooze gets muted for good, and then the app lies to you.
7. **Receipts are per channel** (`fleet_reminder_receipts`).
   A broken mail server does not silence the in-app notification, and "what did we send, when" is a query.

**The state at an instant.**
`ReminderEngine::evaluate()` is pure: a reminder, a day and the newest main Reading in, a state and a point out.
Everything that shows or sends a state asks it, so a test moves the same clock as the job.
A km reminder has no "day after", so it stays due and never turns overdue.
Dismissing a recurring reminder moves it on from the **planned** due, because nothing was done.

**The estimate** (`ReminderEngine::estimate()`) uses the newest unflagged segment of the last 90 days.
A reset starts the pace again.
The state never reads the estimate.

**The sheet.**
A template fills fields only at creation, so an edit can clear a recurrence.
A date reminder refuses km fields and the other way round: the engine would never read them.
The owner and the managers write reminders.
Drivers and viewers read them.

**A maintenance record closes a reminder** through `closes`.
Editing or deleting the record takes the occurrence back, unless something else moved the reminder since (`ReminderEngine::retreat()`).
The planned due is not stored, so it cannot come back.

**Who is told.**
Each vehicle has a list of recipients, the owner first.
Reading and writing it takes `edit`: who gets told is the managers' business.
An added account must be one the sharing settings let the caller reach (`Sharable`), because a reminder names the plate.
Anyone who already sees the vehicle passes.
Being on the list grants nothing.
Taking someone off withdraws their notices after the commit.

**The job.**
`ReminderJob` runs hourly and hands the round to `NotificationService`.

- One query finds the vehicles with a live reminder.
  Each is held and read again, one transaction each.
- The newest point goes to each recipient once.
  The receipt is claimed first, and only a new receipt sends.
  So a missed day skips the points between, and a second run sends nothing.
- Sending waits for the commit, so a rollback cannot leave a notice without its receipt.
  A failed vehicle or send is logged and the round goes on.
  A failed send deletes its receipt, so the next run tries again.
- A notification stores parameters.
  `Notifier::prepare()` translates them in the reader's language when read, and drops a notice that no longer stands.
  Snoozing, dismissing, deleting or closing a reminder withdraws its notice.
- A laid-up vehicle is evaluated but sends nothing.
  A disposed one is not evaluated.
- An edit that moves a point lets it ring again (`NotificationService::rearm()`).

**The digest.**
After the notifications, `MailService` sends each recipient at most one mail a day, from 07:00 in their zone, for the vehicles whose `reminder_mail` falls that day and that have news.
No news, no mail.
Receipts are claimed under each vehicle's hold, and the mail goes after the commits, so no vehicle waits on the mail server.
A failed mail deletes its receipts.

## Nextcloud integration

### Access

Every query runs through `VehicleAccess::may`.
Access means being the owner, or holding a row in `fleet_access` with a strong enough role ([ADR 0001](adr/0001-own-access-table.md)).
The strongest row wins, and no row narrows another.

| Operation | Roles | Covers |
|---|---|---|
| `view` | viewer, driver, manager | Read the vehicle and everything on it |
| `log` | driver, manager | Add an entry, change your own |
| `edit` | manager | The vehicle, reminders, recipients, its own documents, any entry; answer a booking; read the booking notice list |
| `delete` | manager | Delete any entry |
| `own` | the owner alone | Access; delete and restore the vehicle; whether the costs are shared, whether bookings need approval, and who hears of bookings |
| `costs` | manager; viewer and driver while `costs_shared` | Every amount, sum and price, and the mileage claim |
| `take_now` | manager; driver while a grant's `take_now` is true | *Take it now* without approval while the car is back by midnight ([booking approval](#booking-approval)) |

- A route about one vehicle asks `may`.
  A list asks `reachable`, one query instead of one per row.
- Without `costs`, a reader still sees the amounts they entered (`VehicleAccess::mayCost`).
  `CostsShown` takes the rest off each door: others' fill-up and record amounts null, others' expenses dropped, every sum null ([API](api.md#costs)).
- An Entry route asks `log`, then `VehicleService::change`: `edit` or `delete`, or `log` alone for the caller's own Entry.
  A booking does the same with the booker in the author's place (`VehicleAccess::mayBooking`).
  A document takes the rule of the row it hangs on.
- The vehicle JSON carries `may`, and each timeline row its own `edit` and `delete`.
  The screen hides by these ([screens follow the role](ui.md#screens-follow-the-role)).
  The server still refuses on its own.
- **A grantee is someone a share could reach.**
  A user or group the instance has, never the owner, and one the sharing settings let the owner reach, read as core's own share checks read them.
  Anyone else is the same 400 as nobody.
- A revoke takes off the recipients who no longer reach `view`.
  A member who leaves a group gets the same, through a queued job (`ForgetMemberJob`), so a group admin's click waits on no vehicle.
- A new grant notifies the grantee, or each group member through `GrantNoticeJob`.
  A revoke withdraws the notice.
- `…/access` is the caller's own view: their role, their groups, and who reads the vehicle, by display name.
  Leaving gives back a grant in the caller's own name.
  Through a group there is nothing to leave: a personal opt-out would be a weaker row beating a stronger one.
- A cancel by anyone but the booker notifies the booker, without naming who ([legal](legal.md)).
- Once a vehicle has any `fleet_access` row, revoked ones too, each row names who entered it (`EnteredBy`).

### The other parts

| Concern | How |
|---|---|
| Push | Our hourly `TimedJob`, then `INotifier`. The Nextcloud app brings it to the phone. |
| Mail | `IMailer` and `IEMailTemplate` on the server's SMTP. A digest, not one mail per item. |
| Files | Papers stay in their owner's Files and are referenced by `file_id` only ([documents](#documents)). The app makes no folder. |
| Downloads | Through our controller, so access follows the vehicle, not the file. |
| Dashboard | `IAPIWidgetV2` over `ReminderService::due()`. Red and amber only: what needs you now. |
| Unified search | `IProvider`, vehicles only, by plate, display name, make or model. Searching trip text would put the access check where nobody tests it. |
| Settings | The personal settings page ([the inbox](#the-inbox)). |
| CLI | Thin `occ` commands, no SQL ([occ commands](admin/occ.md)). `vehicles`, `access` and `audit` read the mappers directly, so trashed vehicles show too. |

**No calendar.**
The public `OCP` API on Nextcloud 31 to 35 can create an event but not change or delete one.
A snoozed or completed reminder would leave an event that still rings.

## Documents

A paper is a Nextcloud file, attached by `file_id` from the file picker (`DocumentService`).
There is no upload path.

- **Who attaches.**
  Listing takes `view`.
  A paper on an Entry whose amounts are kept from the reader is not listed, synced or served (`CostsShown::showsPaper()`, [costs](api.md#costs)): a receipt shows the price.
  So is a receipt on the vehicle itself, where its costs are kept, and a receipt on a booking, except to its booker; the vehicle's other papers and a booking's photos stay, since drivers need them.
  To such a reader the paper is not there: removing or restoring it is a 404, not a 403.
  Attaching, removing and restoring take `log` plus the rule of the row the paper hangs on, or `edit` for the vehicle's own papers.
  A driver files the receipt of their own fill-up.
  The registration stays a manager's.
- **Only the attacher's own file.**
  The download serves the file to everyone who may view the vehicle and list the paper, so attaching is where the file's own access is checked.
  A shared file is refused: the download would outlive a revoked share.
  A group folder or external storage is refused: both name whoever asks as the owner (`OwnFiles::owns`).
- Nothing edits a document, so there is no token.
  A new kind means removing and attaching again.
  Removing soft-deletes the row and leaves the file alone.
- **The name follows the file.**
  It is looked up by id in the attacher's Files and cached five minutes (`OwnFiles::shown()`), because mounting the Files per paper made long lists slow.
  A file that left the attacher lists without a name and is never served from where it went.
- **The download** is outside `/api`, because it is a link.
  It checks the file live, sends it as an attachment with `nosniff`, and finds it through the attacher's `getUserFolder`: `getById` searches only the signed-in account's mounts.
- **On screen**, a paper is a paperclip on its row, never a timeline row of its own: a registration has no date to sort by.

## The inbox

A receipt photographed on the phone reaches an entry without the file picker.
The mobile app's auto-upload fills a folder, and the app lists what in it belongs to no vehicle yet (`InboxService`).

- **The folder is a preference**, `inbox_folder`, held by file id.
  It must be in the user's own Files: what lands in somebody else's folder is not theirs to attach.
- `GET /api/inbox` lists the folder's direct images and PDFs that no live paper references, newest first, 100 at most.
  Direct children only: a recursive walk over a large folder is a slow request.
- **The inbox only lists.**
  The app never moves, renames or deletes a file there.
  A receipt moved out of sight is one the user cannot find.

## Import

Another tool's CSV export becomes entries (`ImportService`).
The file is picked in Files and must be the caller's own (`OwnFiles`).
Importing takes `edit`, because it writes many entries at once.

- **It never creates a trip.**
  Neither format keeps a logbook a ruleset accepts, and trips made up from counters would be the invented trips that closing a Gap refuses to make.
- **The seam.**
  An importer (`IImporter`, [contributing](contributing.md)) places the columns, turns rows into proposals, and writes nothing.
  Neither format states VAT, so `vat_rate` is null.
- **Formats are evidence, not promises.**
  Each importer names where and when its headers were read.
  A header it cannot place is reported as ignored, never guessed.
- **Bounded.**
  `CsvReader` reads under the limits in [security](security.md#hostile-content).
  `Values` judges a decimal mark or a date order on the whole column.
- **The preview writes nothing and keeps nothing.**
  A table would need a migration, and a cache entry would be a second truth.
  The import sends the same body again with the file's `etag`, and the file is read again.
  A file that changed since is a 409.
- **What the vehicle says is not asked.**
  Money is in the vehicle's currency.
  A row in another is unreadable.
  A sign never stands for the euro by default.
- **Duplicates** are live entries of the same kind on the same local day with the same counter, or the same amount.
- **One transaction under the hold.**
  Each row goes through its entry service's `add()`.
  A refused row stops the whole import.
  The Readings settle once at the end (`OdometerService::batch()`), because settling per row would be quadratic.
- **The answer is the import's identity:** the counts and every created entry.
  Undoing names that list, and nothing else records it.
  Sent again, it is answered, not imported ([retried creates](api.md#retried-creates)).
- **Stamped at the commit** (`BaseMapper::restamp()`).
  A long import would otherwise commit rows older than a sync that ran in between.
- **The undo** deletes the whole list or nothing.
  Each entry must be live, on this vehicle and entered by the caller, or the answer is 409.
  All failures get one answer, so a foreign uuid tells nothing.
- `occ nextfleet:import` builds the same request and runs it as the user it names.

Next: [Interface and languages](ui.md)
