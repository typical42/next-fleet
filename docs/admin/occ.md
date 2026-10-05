# occ commands

NextFleet adds commands to the Nextcloud `occ` tool. With them, you can find, check, repair and
restore data without SQL.

## Before you start

- Run each command as the HTTP user from the Nextcloud folder. The examples use `www-data`.
- A command identifies a vehicle by its UUID. Use [`nextfleet:vehicles`](#nextfleetvehicles) to
  find the UUID.
- To see all options of a command, run `occ help <command>`.

### Output

The commands `vehicles`, `check`, `recompute`, `access`, `audit`, `pending` and `reminders` show a
table. To get JSON, add `--output=json`. To get indented JSON, add `--output=json_pretty`. Error
messages go to the standard error output, so the JSON output stays valid.

### Exit codes

| Code | Meaning |
|---|---|
| 0 | The command is complete, or it found nothing. |
| 1 | `check` or `recompute --dry-run` found a problem, or the command refused or failed. |
| 2 | The command refused a value that you gave. `import`, `transfer` and `seed` return 1 for this error. |

If an argument is missing, or an option is unknown, `occ` itself stops with exit code 1.

## Commands to find data

### nextfleet:vehicles

Shows all live vehicles with their UUID, plate, name, owner and lifecycle.

Usage: `occ nextfleet:vehicles [--user <uid>] [--deleted] [--output <format>]`

| Option | Description |
|---|---|
| `--user` | Show only the vehicles that this account owns. |
| `--deleted` | Show the deleted vehicles instead of the live vehicles. |

```console
$ sudo -E -u www-data php occ nextfleet:vehicles --user alice
+--------------------------------------+-----------+---------------------------+-------+-----------+------------+
| uuid                                 | plate     | name                      | owner | lifecycle | deleted_at |
+--------------------------------------+-----------+---------------------------+-------+-----------+------------+
| e95df47f-772d-4af6-8991-76d4adfdac33 | NF-DE 100 | Volkswagen Passat Variant | alice | active    |            |
+--------------------------------------+-----------+---------------------------+-------+-----------+------------+
```

### nextfleet:access

Shows the owner of a vehicle and each account or group with access to it.

Usage: `occ nextfleet:access <vehicle> [--all] [--output <format>]`

| Option | Description |
|---|---|
| `--all` | Show also the revoked grants, with the date of the revocation. |

```bash
sudo -E -u www-data php occ nextfleet:access e95df47f-772d-4af6-8991-76d4adfdac33
```

### nextfleet:audit

Shows the audit trail of a vehicle and its trips, oldest first. Each row shows each changed field
before and after the change.

Usage: `occ nextfleet:audit <vehicle> [--entry <uuid>] [--since <date>] [--output <format>]`

| Option | Description |
|---|---|
| `--entry` | Show only the rows for this trip. Give the vehicle UUID to show only the rows for the vehicle. |
| `--since` | Show only the rows from this day or later. Use the format `YYYY-MM-DD`, in UTC. |

```bash
sudo -E -u www-data php occ nextfleet:audit e95df47f-772d-4af6-8991-76d4adfdac33 --since 2026-09-01
```

### nextfleet:reminders

Shows the open reminders of an account, most urgent first: overdue, due, coming up, planned and
snoozed. The dashboard widget of that account shows only the overdue, due and coming-up reminders.

Usage: `occ nextfleet:reminders [<uid>] [--send] [--output <format>]`

| Option | Description |
|---|---|
| `--send` | Run the hourly reminder job now, for all accounts. It sends the notifications and the reminder mails. |

The reminder mail normally goes to each account one time per day at most. If the hourly job runs at
the same time as `--send`, an account can get the news in two mails. If a mail to a recipient fails,
the command writes the error to the Nextcloud log.

With `--send` and `--output=json`, and without an account, the command shows
`{"sent":true}`, or `{"sent":false}` if the job failed.

```bash
sudo -E -u www-data php occ nextfleet:reminders alice
```

### nextfleet:pending

Shows the account deletions and group deletions that an error stopped, with their start time.

If an account with the same name exists again, the command shows a warning. The account deletion
is then dropped, and the new account keeps the vehicles and rows of the deleted account.

Usage: `occ nextfleet:pending [--finish] [--output <format>]`

| Option | Description |
|---|---|
| `--finish` | Complete the work now, as the hourly job does. Then show what is not complete. |

```bash
sudo -E -u www-data php occ nextfleet:pending --finish
```

## Commands to check and repair

### nextfleet:check

Finds rows that break a rule of the data model. The command changes nothing. It finds:

- Rows that link to a vehicle that does not exist.
- A stored counter value or reading flag that does not agree with the counter readings (`cache`,
  `flag`).
- Counter readings of an entry that do not agree with the entry.
- Two live grants for the same account or group on one vehicle.
- A grant to an account or group that does not exist (`grantee`).
- A vehicle owner that no account has, and for whom no account deletion is pending (`owner`).
- A name of a deleted account in the format from before 0.3.0.

If a live account has a name in that old format, the command shows a warning. A warning does not
change the exit code.

Usage: `occ nextfleet:check [--vehicle <uuid>] [--output <format>]`

| Option | Description |
|---|---|
| `--vehicle` | Check only this vehicle. The checks for the full server do not run. |

```console
$ sudo -E -u www-data php occ nextfleet:check --vehicle e95df47f-772d-4af6-8991-76d4adfdac33
No findings.
```

### nextfleet:recompute

Calculates the counter of a vehicle again, as a write does. It shows each value that it changed,
before and after.

Usage: `occ nextfleet:recompute (--vehicle <uuid> | --all) [--dry-run] [--output <format>]`

| Option | Description |
|---|---|
| `--vehicle` | Calculate only this vehicle. |
| `--all` | Calculate all vehicles, also the deleted vehicles. |
| `--dry-run` | Show what would change, but change nothing. The exit code is 1 if something would change. |

Use `--vehicle` or `--all`, not both.

```bash
sudo -E -u www-data php occ nextfleet:recompute --all --dry-run
```

### nextfleet:mail-test

Sends one test mail to the address that the reminder mail of an account goes to. If the mail
fails, the command shows the error of the mail server.

Usage: `occ nextfleet:mail-test <uid>`

```bash
sudo -E -u www-data php occ nextfleet:mail-test alice
```

## Commands to restore and move data

### nextfleet:restore

Takes a deleted vehicle out of the trash, as the undo of the owner does. The command refuses a
vehicle that is not deleted. It also refuses a vehicle whose owner account is deleted, or whose
account deletion is pending.

Usage: `occ nextfleet:restore <vehicle>`

```bash
sudo -E -u www-data php occ nextfleet:restore e95df47f-772d-4af6-8991-76d4adfdac33
```

### nextfleet:transfer

Gives a live vehicle to a different owner. The grants stay. If the account of the old owner exists,
it keeps the role **Viewer**.

Usage: `occ nextfleet:transfer <vehicle> <owner>`

```bash
sudo -E -u www-data php occ nextfleet:transfer e95df47f-772d-4af6-8991-76d4adfdac33 bob
```

### nextfleet:import

Imports a CSV export from the Files of an account into one of its vehicles. The import runs as
that account. The [import](../architecture.md#import) section describes the formats.

Usage: `occ nextfleet:import <user> <vehicle> <path> [options]`

| Option | Description |
|---|---|
| `--importer` | The format of the file: `lubelogger` or `spritmonitor`. |
| `--record-type` | The type of records in the file, for example `fuel`. |
| `--units` | The distance and volume units of the file, for example `km,l` or `mi,us_gal`. |
| `--date-order` | `dmy` or `mdy`. Necessary only if each date in the file can have the two meanings. |
| `--energy` | The energy of the energy entries, if the vehicle uses more than one energy. |
| `--map` | Maps a cost category to a NextFleet type, for example `"Steuer:expense.tax"` or `"Wäsche:skip"`. You can give this option more than one time. |
| `--include-duplicates` | Import also the rows that are already in NextFleet. |
| `--dry-run` | Show the preview and write nothing. |

```bash
sudo -E -u www-data php occ nextfleet:import alice e95df47f-772d-4af6-8991-76d4adfdac33 fuel.csv --importer=spritmonitor --record-type=fuel --units=km,l --dry-run
```

### nextfleet:seed

Adds a demonstration fleet to an account. Use it to try the app.

Usage: `occ nextfleet:seed <user> [--grant-to <uid>]`

| Option | Description |
|---|---|
| `--grant-to` | Give this account driver access to one vehicle, with one trip of its own. |

```bash
sudo -E -u www-data php occ nextfleet:seed alice
```
