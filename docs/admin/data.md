# Data and backups

Where NextFleet keeps its data, what protects it, and what an upgrade does to it.

## Where the data is

- **Database tables** that start with `oc_fleet_`: vehicles, entries, readings, reminders, access, bookings and the audit trail.
- **`oc_preferences`**: the personal settings of each account.
- **`oc_appconfig`**: account and group deletions that are not complete yet.
- **The Files of each account**: attached documents.
  NextFleet keeps only the file ID.
  If the file goes, the row stays without a file.

NextFleet sends nothing off the server except the reminder mails.
Nextcloud's push service can carry the notifications to phones.

## Make a backup

> [!IMPORTANT]
> NextFleet keeps no copy of its own.
> Only your backup protects its data.

Back up the database and the data folder together, and restore them together.
The [Nextcloud admin manual](https://docs.nextcloud.com/server/latest/admin_manual/maintenance/restore.html) shows how.
A backup from before an account deletion brings the account name back into the rows.
Delete that account again after the restore.

A user's export with the **User migration** app is no backup.
Importing it restores nothing.

## What protects the data

- **Deletion is soft.**
  A deleted row stays with a deletion date.
  Nothing purges it.
  [`nextfleet:restore`](occ.md#nextfleetrestore) brings a deleted vehicle back.
- **Account deletion keeps the rows.**
  NextFleet takes the account off the reminder lists and replaces its name everywhere else with a random name ([ADR 0008](../adr/0008-erasing-a-driver-pseudonymises.md)).
  The account's own vehicles lose all access and go to the trash.
  This works only while the app is enabled.
- **Interrupted work completes.**
  If an error stops an account or group deletion, an hourly job finishes it.
  [`nextfleet:pending --finish`](occ.md#nextfleetpending) finishes it now.
- **A write is whole or not done.**
  One write is one transaction.
  Two writes to one vehicle wait for each other.
  If two users change the same record, the second one is told and can save again.

> [!WARNING]
> Before you delete the account of a vehicle owner, move the vehicles to another account with [`nextfleet:transfer`](occ.md#nextfleettransfer).
> After the deletion, nobody can use them.

## How an upgrade changes the data

1. **Migrations** in `lib/Migration/` add tables, columns and indexes.
   Nextcloud runs each one once.
   They keep all rows.
2. **Repair steps** in `lib/Repair/` run after them:
   - `ErasedPseudonyms` renames deleted accounts from versions before 0.3.0 to the new format.
     A live account with an old-format name is reported in the upgrade output, the log and a notification to the administrators.
   - `StrangerRecipients` removes reminder recipients who cannot see the vehicle and whom nobody may share with.
     It runs only on an upgrade from before 0.3.1.

Each release is installed over the one before it in a test.
The test fails if a row is lost, or if the schema differs from a fresh install ([release](../development.md#release)).

## Find and repair damaged data

The database has no foreign keys, so it does not stop every wrong link between rows.

1. Run `occ nextfleet:check`.
2. For a `cache` or `flag` finding, run `occ nextfleet:recompute --vehicle <uuid>`.
3. For other findings, look at the rows it names ([Troubleshooting](troubleshooting.md)).

Next: [occ commands](occ.md)
