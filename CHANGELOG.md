# Changelog

## Unreleased

- The M3 schema: `fleet_energy`, `fleet_maintenance` and `fleet_expenses`, a `counter` on each
  Reading (`main` or `second`; every existing Reading reads as `main`), and `second_unit` and
  `second_value` on the vehicle for engine hours beside the kilometres. Nothing writes them yet.
  A vehicle and a Reading now carry the new fields on the wire.
- A vehicle can be a truck. The create sheet asks for the vehicle type and the counter unit beside
  the counter; choosing a tractor or a generator switches the unit to engine hours, still
  changeable, and never on a vehicle that already has Readings. The edit sheet's "Also counts engine
  hours" switch, offered on a km vehicle, sets `second_unit`; switching it off keeps the hour
  Readings. A vehicle counted in hours carries no second counter.
- Engine hours are a chain of their own. An Odometer Entry on a vehicle with a second counter asks
  which counter it read (`counter` on `POST …/readings`; `second` is refused on a vehicle without
  one). Each chain is flagged and cached on its own, `second_value` for the hours; trips, Gaps and
  the Fahrtenbuch read kilometres only. The timeline states an hour Reading in hours.
- A jurisdiction states its VAT rate by date (`IJurisdiction::rates()`). Germany answers 19 %, and
  16 % from 2020-07-01 to 2020-12-31, citing §12 UStG; nothing before 2007. The generic profile
  states no rates. Nothing reads it yet.
- A fill-up on the server: `POST /api/vehicles/{uuid}/energy`. Each counter given (`odo`,
  `second_odo`) writes an Observed Reading at `filled_at` that accounts for no kilometre; none
  given, none written. `second_odo` is refused on a vehicle without engine hours. `unit_price` is
  derived from `total` and `amount` when not given. The answer carries `flags`: `foreign_energy`
  for an energy outside `energy_types`, `no_price` for a missing total.
- Energy in the entry sheet, offered on a vehicle with `energy_types` and only with those. Amount
  (required) and total are typed as the pump shows them, with a comma or a point. VAT comes
  prefilled with the jurisdiction's rate on the fill-up's day and clears to "not stated". The
  station completes from this vehicle's history and prefills the price it last charged; that
  price is sent only when there is no total or the driver changed it. A charge asks home or
  public, and DC only in public. An empty counter says consumption needs it. The prefill is
  `GET /api/vehicles/{uuid}/energy/prefill?at=&off=`.

- Overview, at the top of the navigation. It leads back from a vehicle or from Reports without a
  reload, and is marked whenever the overview is what shows.
- Two writes on one vehicle at once no longer leave its kilometres on the older Reading. Every
  Odometer Entry and trip write now holds the vehicle first, so the second waits for the first.
  An Odometer Entry and its restating are now one transaction.
- Reports, below the vehicles in the navigation. It opens the Fahrtenbuch for a vehicle and a year
  in a tab of its own, prefilled with the first vehicle and this year. It offers only vehicles whose
  country prints a logbook, sold ones included. `GET /api/preferences` now says which countries do
  (`logbook_export`).
- The Fahrtenbuch export: `GET /apps/nextfleet/vehicles/{uuid}/logbook/{year}` opens one vehicle's
  logbook for one year as a page the browser prints, in German. Every trip that set off in the year
  is a line, voided trips listed as voided. A trip that set off while Logbook Mode was on and lacks
  a field is marked incomplete and names it; outside those periods nothing is marked. A trip edited,
  voided or restored after the lock delay says when on its line: an edit with what each changed
  field said before, a restore with since when the trip had been voided. The page
  states the periods the mode was on, or that it was not, and cites the requirement in its footer.
  A country without an export answers 404. The page loads nothing, and the export is rate-limited
  and logged.
- A Gap is closed one at a time. Under Logbook Mode the trip that opened it offers to close it, and
  a confirmation naming the kilometres and the two moments writes one private distance trip marked
  `reconciled`, whose Reading lands on the claim. `POST /api/vehicles/{uuid}/gaps/{trip}/close`
  takes the Gap as the driver confirmed it and answers 412 `conflict` when it has moved since. Under
  the mode its audit row is `created` with `derived: true`. It edits like any other trip, and
  `reconciled` is never set or cleared by a request.
- Gap detection: a trip that sets off above where the last trip left the counter leaves the
  kilometres in between unaccounted. An Odometer Entry between the two accounts for none of them,
  and one that is flagged or above the claim opens no Gap until it is answered. With no trip before,
  the claim is measured against the newest Reading. `GET /api/vehicles/{uuid}/gaps` lists them for every vehicle, each with the two
  moments that bracket it. Under Logbook Mode the timeline's month header states the month's total.
- A trip can be edited: `PUT /api/vehicles/{uuid}/trips/{trip}` takes the whole trip and the token
  it was read with, and rewrites the row in place. A field the request leaves out is emptied, so a
  distance trip can become a counter trip. When the journey moves, its Reading moves with it in the
  same transaction. Under Logbook Mode the edit leaves an `edited` audit row with what changed, and
  an edit after the ruleset's lock delay is still allowed and marked `late`.
- A trip missing a field its jurisdiction requires is saved and flagged, never refused. Under
  Logbook Mode its timeline row says it is incomplete and names what is still missing, in the words
  the sheets ask by. The timeline's trip rows carry `missing` for every vehicle.
- Germany states what it requires of a logbook: a business trip names the plate, the date, both
  counters, where it went, why and whom it visited; a commute states the journey and not its reason;
  a private trip needs only the kilometres it already carries. An entry stays timely for seven days,
  a record is kept for ten years, and the requirement is cited by URL. A country that requires no
  logbook says so, and under it Logbook Mode still keeps trips append-only and audited.
- A trip is deleted by voiding it: `DELETE /api/vehicles/{uuid}/trips/{trip}` stamps `deleted_at`,
  the row survives, and the Reading the trip left on the counter goes with it, so the vehicle stands
  where it did before the journey. `POST …/trips/{trip}/restore` brings both back on the token the
  delete answered with. Under Logbook Mode each of the two leaves a `fleet_audit` row, in the same
  transaction as the write.
- Logbook Mode is switched on and off per vehicle, in the vehicle's edit sheet beside its lifecycle
  and its country. Every flip leaves a `fleet_audit` row on the vehicle, in the same transaction as
  the write, which is how an export later states the periods the mode was on. Switching off asks
  first; switching on does not.
- The vehicle sheet's two date pickers stop taking input while a save is in flight, like every field
  beside them.
- A vehicle under Logbook Mode keeps an audit trail: every trip written on it leaves a `fleet_audit`
  row naming the trip and listing the fields it was written with, in the same transaction as the
  trip itself. Off the mode nothing is recorded.
- The vehicle screen shows its timeline: trips and counter readings as rows, newest first, the month
  a sticky header above them and the next fifty loaded as the bottom comes into view. The chips
  `All · Trips · Odometer` narrow it, and an entry saved in the sheet appears without a reload.
- `GET /api/vehicles/{uuid}/timeline` answers with one vehicle's trips and counter readings in one
  order, newest first, fifty rows at a time. `?type=` narrows it to one kind, `?cursor=` continues
  where the last page stopped, and a trip carries the Reading it left on the counter so the two are
  one row.
- The entry sheet asks what is being entered — a trip or a counter reading — and opens on the trip.
  A trip is entered with the counter it ended on or with the kilometres it covered, whichever the
  driver knows. Neither of a trip's counters is prefilled: the one it set off on is a claim about
  what the dashboard read, and the vehicle's own counter is not that claim.
- A counter a trip actually ended on discredits the Readings the distance-only trips before it
  counted: those rows are flagged and none of them is rewritten. A counter still below the last row
  standing is flagged too, so both questions get asked.
- A trip may carry the kilometres it covered instead of the counter it ended on. Its Reading is
  counted from the newest one at or before the moment it set off, and marked derived. A trip that
  carries both, or neither, is refused.
- `POST /api/vehicles/{uuid}/trips` records a trip. The counter it ended on becomes one Reading at
  `ended_at`, and the vehicle's kilometres move with it. `start_odo` stays a claim and writes no
  Reading.
- A second migration adds `fleet_trips` and `fleet_audit`, the two tables the logbook is made of.
  It is the only schema change this milestone gets.
- `fleet_trips` carries no `cost_center` and no `driver_uid`, and an audit row's author and instant
  are the `created_by` and `created_at` every row has. `docs/architecture.md` said otherwise, and
  `docs/legal.md` and ADR 0008 named `driver_uid` as what a driver's erasure pseudonymises.
- Dismissing the *First registration* or *Disposed on* picker with `Esc` no longer closes the
  vehicle sheet and loses what was typed into it.
