# Changelog

## Unreleased

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
