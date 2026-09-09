# Changelog

## Unreleased

- A trip may carry the kilometres it covered instead of the counter it ended on. Its Reading is
  counted from the newest one at or before the moment it set off, and marked derived. A trip that
  carries both, or neither, is refused.
- `POST /api/vehicles/{uuid}/trips` records a trip. The counter it ended on becomes one Reading at
  `ended_at`, and the vehicle's kilometres move with it. `start_odo` stays a claim and writes no
  Reading.
- A second migration adds `fleet_trips` and `fleet_audit`, the two tables the logbook is made of.
  It is the only schema change this milestone gets; nothing writes to `fleet_audit` yet.
- `fleet_trips` carries no `cost_center` and no `driver_uid`, and an audit row's author and instant
  are the `created_by` and `created_at` every row has. `docs/architecture.md` said otherwise, and
  `docs/legal.md` and ADR 0008 named `driver_uid` as what a driver's erasure pseudonymises.
- Dismissing the *First registration* or *Disposed on* picker with `Esc` no longer closes the
  vehicle sheet and loses what was typed into it.
