# occ commands

NextFleet adds commands to `occ`.
They find, check, repair and restore data without SQL.

Run them as the HTTP user from the Nextcloud folder, for example `sudo -E -u www-data php occ nextfleet:check`.
A vehicle is named by its UUID; [`nextfleet:vehicles`](#nextfleetvehicles) finds it.
`occ help <command>` lists all options.

**Output.**
`vehicles`, `check`, `recompute`, `access`, `audit`, `pending` and `reminders` print a table.
`--output=json` or `--output=json_pretty` prints JSON.
Errors go to stderr, so the JSON stays valid.

**Exit codes.**

| Code | Meaning |
|---|---|
| 0 | Done, or nothing found. |
| 1 | `check` or `recompute --dry-run` found a problem, or the command failed or refused. |
| 2 | A value you gave was refused. `import`, `transfer` and `seed` return 1 for this. |

## Find data

### nextfleet:vehicles

Lists the live vehicles: UUID, plate, display name, make and model, owner, lifecycle, EU category and body type.

Usage: `occ nextfleet:vehicles [--user <uid>] [--deleted] [--output <format>]`

| Option | Description |
|---|---|
| `--user` | Only the vehicles this account owns. |
| `--deleted` | The deleted vehicles instead. |

### nextfleet:access

Lists the owner of a vehicle and each account or group with access.

Usage: `occ nextfleet:access <vehicle> [--all] [--output <format>]`

| Option | Description |
|---|---|
| `--all` | Also the revoked grants, with the date. |

### nextfleet:audit

Lists the audit trail of a vehicle and its trips, oldest first, with each field before and after.

Usage: `occ nextfleet:audit <vehicle> [--entry <uuid>] [--since <date>] [--output <format>]`

| Option | Description |
|---|---|
| `--entry` | Only the rows of this trip. The vehicle UUID gives the rows of the vehicle. |
| `--since` | Only rows from this day on, `YYYY-MM-DD` in UTC. |

### nextfleet:reminders

Lists the open reminders of an account, most urgent first.

Usage: `occ nextfleet:reminders [<uid>] [--send] [--output <format>]`

| Option | Description |
|---|---|
| `--send` | Run the hourly reminder job now, for all accounts. |

If the hourly job runs at the same time as `--send`, an account can get the news in two mails.
A failed mail goes to the Nextcloud log.
With `--send --output=json` and no account, it prints `{"sent":true}` or `{"sent":false}`.

### nextfleet:pending

Lists the account and group deletions that an error stopped.

Usage: `occ nextfleet:pending [--finish] [--output <format>]`

| Option | Description |
|---|---|
| `--finish` | Complete them now, as the hourly job does. |

If an account with the same name exists again, the command warns and drops that deletion.
The new account then keeps the rows.

## Check and repair

### nextfleet:check

Finds rows that break a rule of the data model.
It changes nothing.
It finds:

- Rows that point to a vehicle that does not exist.
- A cached counter or a reading flag that disagrees with the readings (`cache`, `flag`).
- Readings that disagree with their entry.
- Two live grants for the same account or group on one vehicle.
- A grant to an account or group that does not exist (`grantee`).
- An owner that no account has, with no deletion pending (`owner`).
- A deleted account's name in the format from before 0.3.0.
  A live account with such a name gives a warning, which does not change the exit code.

Usage: `occ nextfleet:check [--vehicle <uuid>] [--output <format>]`

| Option | Description |
|---|---|
| `--vehicle` | Check only this vehicle. The server-wide checks do not run. |

### nextfleet:recompute

Calculates the counters again, as a write does, and prints each value it changed.

Usage: `occ nextfleet:recompute (--vehicle <uuid> | --all) [--dry-run] [--output <format>]`

| Option | Description |
|---|---|
| `--vehicle` | Only this vehicle. |
| `--all` | All vehicles, deleted ones too. |
| `--dry-run` | Show what would change. Exit code 1 if anything would. |

### nextfleet:mail-test

Sends one test mail to the reminder address of an account and prints the mail server's error if it fails.

Usage: `occ nextfleet:mail-test <uid>`

## Restore and move data

### nextfleet:restore

Takes a deleted vehicle out of the trash, as the owner's undo does.
It refuses a vehicle whose owner account is deleted or pending deletion.

Usage: `occ nextfleet:restore <vehicle>`

### nextfleet:transfer

Gives a live vehicle to another owner.
The grants stay.
The old owner keeps **Viewer**, if the account still exists.

Usage: `occ nextfleet:transfer <vehicle> <owner>`

### nextfleet:import

Imports a CSV file from an account's Files into one of its vehicles, as that account.
The formats are in the [user manual](../user/import.md).

Usage: `occ nextfleet:import <user> <vehicle> <path> [options]`

| Option | Description |
|---|---|
| `--importer` | `lubelogger` or `spritmonitor`. |
| `--record-type` | The record type, for example `fuel`. |
| `--units` | Distance and volume of the file, for example `km,l` or `mi,us_gal`. |
| `--date-order` | `dmy` or `mdy`, when the dates can be read both ways. |
| `--energy` | The energy, when the vehicle takes more than one. |
| `--map` | A cost category and what it becomes, for example `"Steuer:expense.tax"` or `"Wäsche:skip"`. Repeat it as needed. |
| `--include-duplicates` | Also import the rows already there. |
| `--dry-run` | Show the preview and write nothing. |

### nextfleet:seed

Adds a demonstration fleet to an account, to try the app.

Usage: `occ nextfleet:seed <user> [--grant-to <uid>]`

| Option | Description |
|---|---|
| `--grant-to` | Give this account driver access to one vehicle, with one trip of its own. |

Next: [Troubleshooting](troubleshooting.md)
