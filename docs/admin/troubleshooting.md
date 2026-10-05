# Troubleshooting

Each section gives a problem, then the checks and the correction. The commands are in
[occ commands](occ.md).

## Reminders or reminder mails do not arrive

1. Go to **Administration settings** > **Basic settings**.
2. Make sure that background jobs use **Cron** mode.
3. Ask the owner or a manager to open **Edit vehicle**. The account must be in
   **Reminders go to**. Only the accounts on that list get notifications and mails.
4. Show the open reminders of the account:

   ```bash
   sudo -E -u www-data php occ nextfleet:reminders alice
   ```

   If the list is empty, the account has no open reminder on a vehicle that it can see.
5. Send a test mail to the account:

   ```bash
   sudo -E -u www-data php occ nextfleet:mail-test alice
   ```

   If the command shows an error, correct the email server settings. If the account has no email
   address, add one in the account settings.
6. Run the reminder job now:

   ```bash
   sudo -E -u www-data php occ nextfleet:reminders --send
   ```

A vehicle that is laid up or disposed of sends no reminders. The owner or a manager of a vehicle
can also stop its reminder mails with the **Reminder mail** setting.

## A user cannot give access to an account or group

NextFleet obeys the sharing settings of Nextcloud. Go to **Administration settings** > **Sharing**
and examine these settings:

- The sharing API is on.
- The owner is not in a group that is excluded from sharing.
- Group sharing is on, if the owner wants to give access to a group.
- If accounts can share only with members of their own groups, the owner and the other account
  must be in the same group.

## The counter of a vehicle shows an incorrect value

1. Find the UUID of the vehicle. `--user` shows only the vehicles that this account owns. To see
   all vehicles, do not use `--user`.

   ```bash
   sudo -E -u www-data php occ nextfleet:vehicles --user alice
   ```

2. Check the vehicle:

   ```bash
   sudo -E -u www-data php occ nextfleet:check --vehicle <vehicle-uuid>
   ```

3. If the command shows a `cache` or `flag` finding, calculate the counter again:

   ```bash
   sudo -E -u www-data php occ nextfleet:recompute --vehicle <vehicle-uuid>
   ```

If the check finds nothing, the value comes from the counter readings. NextFleet flags a counter
reading that is lower than the reading before it. The user who recorded the entry, the owner or a
manager must answer the question on that entry in the timeline.

## A vehicle is missing

1. Find the vehicle in the trash:

   ```bash
   sudo -E -u www-data php occ nextfleet:vehicles --deleted
   ```

2. If the vehicle is there, restore it:

   ```bash
   sudo -E -u www-data php occ nextfleet:restore <vehicle-uuid>
   ```

If the vehicle is not deleted, `nextfleet:vehicles` shows its lifecycle. A vehicle that is disposed
of is in the **Disposed of** list on the **Overview** page.

If the account cannot see the vehicle at all, it possibly has no access to it now. Show who has
access:

```bash
sudo -E -u www-data php occ nextfleet:access <vehicle-uuid> --all
```

## A document does not open

NextFleet keeps only the ID of a file. The file stays in the Files of the account that attached
it. If that account deleted the file or moved it out of its Files, the document cannot open.
Restore the file from the Nextcloud trash bin of that account.

## A deleted account or group still has access

NextFleet removes the access when you delete an account or a group. If an error stopped that work,
an hourly job completes it. To see the work that is not complete, and to complete it now:

```bash
sudo -E -u www-data php occ nextfleet:pending
sudo -E -u www-data php occ nextfleet:pending --finish
```

If the command shows an error, look in the Nextcloud log for the cause.

`nextfleet:pending` can warn that an account with the same name exists again. If that account is a
different person, delete it. NextFleet then erases the name.

## You must know who changed a trip

Show the audit trail of the vehicle:

```bash
sudo -E -u www-data php occ nextfleet:audit <vehicle-uuid> --entry <trip-uuid>
```

NextFleet writes an audit row for each change to a trip while Logbook mode is on. It also writes
one for each change to a trip that started in a period with Logbook mode on. For a vehicle, it
writes one for each change to the plate, vehicle type, currency, country and Logbook mode. It also
writes one for each transfer to a new owner.

## A vehicle has an owner that does not exist

`nextfleet:check` shows an `owner` finding when no account has the name of a vehicle owner. This
happens when you delete an account while the app is disabled. If somebody makes a new account with
that name, the new account owns the vehicle and can read all its rows.

Before you start, make sure that the account is really deleted. If it comes from LDAP or a
different user backend, make sure that the backend is available. Else you erase the rows of a live
account.

1. Make a temporary account. Use the account name from the `owner` finding.
2. Give the account a random password.
3. If you want to keep a vehicle in the trash, restore it with `nextfleet:restore`.
4. If you want to keep a vehicle, give it to a different account with `nextfleet:transfer`.
5. Delete the temporary account.

   NextFleet replaces the name in the rows and moves the vehicles that the account still owns to
   the trash.

Do the same for a `grantee` finding of a deleted account. For a `grantee` finding of a deleted
group, make a temporary group with that name, then delete it.

## The upgrade output names an account

The repair step `ErasedPseudonyms` names a live account whose name has the format of a deleted
account from before 0.3.0. NextFleet keeps that account and its rows. If somebody made the account
to take the rows of the deleted account, delete the account. NextFleet then erases the name in
those rows. If not, do nothing.
