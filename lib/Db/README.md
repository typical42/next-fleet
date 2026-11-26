Entities and mappers for the seventeen tables: vehicles, odometer readings, access, trips, the
audit trail, energy, maintenance, expenses, reminders, their receipts and their recipients,
documents, bookings and their recipients, partners, cost centres and budgets. The schema is in
../../docs/architecture.md#data-model.

The base mapper carries optimistic concurrency: an update sends the `updated_at` it read, and a
stale one is rejected with 412. `AuditMapper` refuses every write but the insert — the trail is
append-only.
