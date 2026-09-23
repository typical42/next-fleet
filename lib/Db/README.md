Entities and mappers for the twelve tables shipped so far: vehicles, odometer readings and access
from M1, trips and the audit trail from M2, energy, maintenance and expenses from M3, reminders,
their receipts and their recipients from M4, documents from M5.

Every further table (bookings) arrives with the milestone that needs it — see ../../docs/architecture.md and the "eleven tables" risk in
../../plan.md.

The base mapper carries optimistic concurrency: an update sends the `updated_at` it read, and a
stale one is rejected with 412. `AuditMapper` refuses every write but the insert — the trail is
append-only.
