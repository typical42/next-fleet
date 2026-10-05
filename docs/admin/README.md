# Administration

This part is for the administrator of a Nextcloud server that runs NextFleet. It tells you how to
install, configure and upgrade the app. Users find their manual in [docs/user/](../user/README.md).

| Page | Read it to |
|---|---|
| This page | Install, configure, upgrade and disable the app |
| [Data and backups](data.md) | Know where the data is, what protects it and how to restore it |
| [occ commands](occ.md) | Look up a command and its options |
| [Troubleshooting](troubleshooting.md) | Find the cause of a problem and correct it |

## Requirements

- Nextcloud 31, 32, 33 or 34.
- PHP 8.1 or later, as your Nextcloud version requires.
- A database that Nextcloud supports: MariaDB, MySQL, PostgreSQL, SQLite or Oracle.
- Background jobs in **Cron** mode. Reminders, mails and the completion of interrupted work use
  them.
- An email server in the Nextcloud settings, if users want reminder mails.

## Install the app

1. Log in to Nextcloud as an administrator.
2. Open the **Apps** page.
3. Search for **NextFleet**.
4. Click **Download and enable**.

   The **NextFleet** entry shows in the top navigation bar.

To install from a release archive, extract it into the `apps` or `custom_apps` folder. Then enable
the app:

```bash
sudo -E -u www-data php occ app:enable nextfleet
```

> [!NOTE]
> All examples use `www-data` as the HTTP user. Your system can use a different user, for example
> `apache`, `http` or `wwwrun`.

## Configure the server

NextFleet has no administration settings page of its own. It uses these Nextcloud settings:

- **Background jobs.** Go to **Administration settings** > **Basic settings**. Select **Cron**.
  An hourly job sends the reminders. Another hourly job completes account deletions and group
  deletions that an error stopped.
- **Email server.** Go to **Administration settings** > **Basic settings** > **Email server**.
  NextFleet sends the reminder mails through this account. To test it for one account, use
  [`nextfleet:mail-test`](occ.md#nextfleetmail-test).
- **Sharing.** Go to **Administration settings** > **Sharing**. An owner can give access to a
  vehicle only to the accounts and groups that these settings let them share with:
  - If the sharing API is off, or the owner is excluded from sharing, nobody can get access.
  - If group sharing is off, an owner cannot give access to a group.
  - If accounts can share only with members of their groups, the same limit applies to vehicles.

Each user sets their own defaults in **Personal settings** > **Additional settings**. The
[user manual](../user/settings.md) describes them.

## Upgrade the app

Make a backup of the database before you upgrade. [Data and backups](data.md#make-a-backup) tells
you what to include.

1. Upgrade the app on the **Apps** page, or with this command:

   ```bash
   sudo -E -u www-data php occ app:update nextfleet
   ```

2. If Nextcloud asks for an upgrade, run it:

   ```bash
   sudo -E -u www-data php occ upgrade
   ```

The upgrade changes the database automatically. Each new version can add migrations and repair
steps. Nextcloud runs each migration one time only.
[Data and backups](data.md#how-an-upgrade-changes-the-data) gives the details.

## Disable or remove the app

```bash
sudo -E -u www-data php occ app:disable nextfleet
```

When you disable the app, the data stays in the database. Users cannot open it until you enable
the app again.

> [!WARNING]
> If you want to keep the data, do not remove the app without `--keep-data`. Before you remove it,
> make a database backup.

```bash
sudo -E -u www-data php occ app:remove nextfleet --keep-data
```

## Related

- [Data and backups](data.md)
- [occ commands](occ.md)
- [Security](../security.md) and [Licensing and legal](../legal.md)
