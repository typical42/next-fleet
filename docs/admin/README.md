# Administration

For the administrator of a Nextcloud server that runs NextFleet.
This page installs, configures, upgrades and removes the app.
Users have their own [manual](../user/README.md).

## Requirements

- Nextcloud 31 to 35, on the PHP version it needs.
- A database that Nextcloud supports: MariaDB, MySQL, PostgreSQL, SQLite or Oracle.
- Background jobs in **Cron** mode.
  Reminders and the completion of interrupted work need them.
- An email server in the Nextcloud settings, for reminder mails.

## Install the app

On the **Apps** page, search for **NextFleet** and click **Download and enable**.

From a release archive, extract it into `apps` or `custom_apps` and enable it:

```bash
sudo -E -u www-data php occ app:enable nextfleet
```

The examples use `www-data` as the HTTP user.
Yours can be `apache`, `http` or `wwwrun`.

## Configure the server

NextFleet has no admin settings page.
It uses these Nextcloud settings:

- **Background jobs** (**Basic settings**): select **Cron**.
  Hourly jobs send the reminders and complete account and group deletions that an error stopped.
- **Email server** (**Basic settings**): the reminder mails use it.
  Test it with [`nextfleet:mail-test`](occ.md#nextfleetmail-test).
- **Sharing**: an owner can give access only to whom these settings let them share with.
  With the sharing API off, nobody gets access.
  With group sharing off, no group gets access.
  "Share only with group members" limits vehicles the same way.

## Upgrade the app

Back up the database first ([Data and backups](data.md#make-a-backup)).

1. Upgrade on the **Apps** page, or run `occ app:update nextfleet`.
2. If Nextcloud asks for it, run `occ upgrade`.
3. After an upgrade with `occ`, reload the web server or PHP-FPM.
   Until then, opcache can serve the old code, and the app can show errors.
   The **Apps** page resets opcache itself.

The upgrade changes the database by itself ([How an upgrade changes the data](data.md#how-an-upgrade-changes-the-data)).

### Before a Nextcloud upgrade

Update NextFleet first, to a version that allows the new Nextcloud.
NextFleet 0.4.0 and older stop at Nextcloud 34, so update to 0.4.1 or newer before Nextcloud 35.
If you upgrade Nextcloud first while the app store is off or unreachable, the upgrade disables the app.
It logs an error that the app needs an older Nextcloud, and a warning that it could not replay the app's migrations.
The data stays: install and enable the new version afterwards, and it picks up where the old one stopped.
With the app store reachable, Nextcloud may fetch the new version itself during the upgrade.

## Disable or remove the app

```bash
sudo -E -u www-data php occ app:disable nextfleet
```

The data stays in the database.

> [!IMPORTANT]
> While the app is disabled, it does not see deleted accounts and groups.
> Their names and access stay.
> After you enable it again, run `nextfleet:check`.
> An `owner` or `grantee` finding shows what to clean up ([Troubleshooting](troubleshooting.md#a-vehicle-has-an-owner-that-does-not-exist)).

To remove the app and keep the data, back up first and use `--keep-data`:

```bash
sudo -E -u www-data php occ app:remove nextfleet --keep-data
```

Next: [Data and backups](data.md)
