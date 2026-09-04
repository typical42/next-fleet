Entities and mappers for the three tables M1 ships: vehicles, odometer readings, access.

Every further table (trips, energy, maintenance, expenses, reminders and their notifications,
documents, audit, bookings) arrives with the milestone that needs it — see
../../docs/architecture.md and the "eleven tables" risk in ../../plan.md.

The base mapper carries optimistic concurrency: an update sends the `updated_at` it read, and a
stale one is rejected with 412.
