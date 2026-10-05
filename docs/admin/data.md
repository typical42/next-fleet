# Data and backups

This page tells you where NextFleet keeps its data, what protects it, and how an upgrade changes
it. The reasons for each rule are in [architecture](../architecture.md#data-model).

## Where the data is

- **The Nextcloud database.** All vehicles, entries, counter readings, reminders, access grants,
  bookings and audit rows are in tables that start with `fleet_`. With the default prefix, the
  names start with `oc_fleet_`.
- **The `oc_appconfig` table.** It marks account deletions and group deletions that are not
  complete.
- **The `oc_preferences` table.** It holds the personal settings of each account.
- **The Files of each account.** A document or a receipt is a file in the Files of the account
  that attached it. NextFleet keeps only the file ID. If the owner of the file deletes it or moves
  it out of their Files, the vehicle cannot open it. The row in the database stays.

NextFleet itself sends only the reminder mails off the server. Nextcloud can send notifications to
phones through its push service.

## Make a backup

> [!IMPORTANT]
> Only your database backup protects NextFleet data against a damaged database. NextFleet keeps no
> copy of its own.

Include these items in the backup of Nextcloud:

- The database.
- The data folder. It contains the attached documents and receipts.

To restore NextFleet data, restore the Nextcloud database and data folder from the same backup.
The [Nextcloud administration manual](https://docs.nextcloud.com/server/latest/admin_manual/maintenance/restore.html)
describes the procedure.

A user can export their own data with the Nextcloud **User migration** app. That export is not a
backup. An import of it restores no NextFleet data.

## What protects data from user errors

- **Deletion is soft.** A deleted vehicle, entry, reminder or document stays in the database with
  a deletion date. Nothing removes it later. To restore a vehicle that a user deleted, use
  [`nextfleet:restore`](occ.md#nextfleetrestore).
- **Account deletion keeps the rows.** When you delete an account, NextFleet replaces the account
  name in its rows with a random name
  ([ADR 0008](../adr/0008-erasing-a-driver-pseudonymises.md)). The vehicles of that account close
  and go to the trash.

> [!WARNING]
> Before you delete the account of a vehicle owner, give their vehicles to a different account with
> [`nextfleet:transfer`](occ.md#nextfleettransfer). After the deletion, nobody can use them again.

- **Interrupted work completes.** If an error stops an account deletion or a group deletion, an
  hourly job completes it. To complete it immediately, use
  [`nextfleet:pending --finish`](occ.md#nextfleetpending).
- **Each write is complete or not done.** A change to many rows uses one database transaction.
  Two writes to one vehicle at the same time run one after the other. If two users change the same
  record, the second user gets a message and can save again.

## How an upgrade changes the data

When the version number in `appinfo/info.xml` increases, Nextcloud runs these steps:

1. **Migrations.** Nextcloud runs each class in `lib/Migration/` that it did not run before. It
   records each migration, so a migration never runs two times. The migrations add tables, columns
   and indexes. They keep all rows.
2. **Repair steps.** Then Nextcloud runs the classes in `lib/Repair/`:
   - `ErasedPseudonyms` changes the names of deleted accounts from versions before 0.3.0 to the
     new format. If a live account has a name in the old format, the step tells you. The message is
     in the upgrade output, in the log and in a notification to each administrator.
   - `StrangerRecipients` removes reminder recipients that the owner cannot share with. It runs
     only when you upgrade from a version before 0.3.1.

Before each release, the developers install the new version over the old one with
`tools/upgrade-check.sh`. The test fails if a row is not there after the upgrade, or if the schema
is not the same as after a new installation ([release](../development.md#release)).

## Find and correct damaged data

There are no foreign keys in the database. Thus the database does not prevent all incorrect links
between rows. Use these commands to find and correct them:

1. Find problems:

   ```bash
   sudo -E -u www-data php occ nextfleet:check
   ```

2. If the command shows a `cache` or `flag` finding, correct the counter of that vehicle:

   ```bash
   sudo -E -u www-data php occ nextfleet:recompute --vehicle <vehicle-uuid>
   ```

3. For other findings, examine the rows that the command shows.

[Troubleshooting](troubleshooting.md) gives more examples.
