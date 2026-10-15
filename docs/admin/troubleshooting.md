# Troubleshooting

Each section names a problem, then how to find the cause and fix it.
The commands are in [occ commands](occ.md).

## Reminders do not arrive

1. In **Basic settings**, make sure that background jobs use **Cron**.
2. Make sure that the account is in **Reminders go to** of the vehicle.
   Only that list gets notifications and mails.
3. Run `occ nextfleet:reminders <uid>`.
   An empty list means no open reminder on a vehicle the account sees.
4. Run `occ nextfleet:mail-test <uid>`.
   On an error, fix the email server, or give the account an email address.
5. Run `occ nextfleet:reminders --send`.

A vehicle that is laid up or disposed of sends nothing.
Each vehicle can also turn its mails off.

## A user cannot give access

NextFleet follows the sharing settings.
In **Sharing**, check that the sharing API is on, that the owner is not excluded from sharing, and that group sharing is on for a group.
With "share only with group members", the owner and the other account must share a group.

## A counter shows a wrong value

1. Find the vehicle: `occ nextfleet:vehicles --user <uid>`.
2. Run `occ nextfleet:check --vehicle <uuid>`.
3. For a `cache` or `flag` finding, run `occ nextfleet:recompute --vehicle <uuid>`.

If the check finds nothing, the value comes from the readings.
A reading lower than the one before is **In question**.
The person who recorded it, the owner or a manager answers that question in the timeline.

## A vehicle is missing

1. Run `occ nextfleet:vehicles --deleted`.
2. If it is there, run `occ nextfleet:restore <uuid>`.

If it is not deleted, it may be disposed of: it is then in the **Disposed of** list on the overview.
If the account cannot see it at all, check who has access: `occ nextfleet:access <uuid> --all`.

## A document does not open

The file is in the Files of the account that attached it.
If that account deleted the file or moved it out of its Files, the document cannot open.
Restore the file from that account's trash bin.

## A deleted account or group still has access

An error stopped the deletion.
Run `occ nextfleet:pending` to see it and `occ nextfleet:pending --finish` to complete it.
If it fails, read the Nextcloud log.

If it warns that an account with the same name exists again, and that account is a different person, delete it.
NextFleet then erases the name.

## Who changed a trip

Run `occ nextfleet:audit <vehicle-uuid> --entry <trip-uuid>`.

There is an audit row for each change to a trip under Logbook mode, or to a trip that started under it.
A vehicle gets one for each change of plate, vehicle type, currency, country, Logbook mode and owner.

## A vehicle has an owner that does not exist

`nextfleet:check` shows an `owner` finding.
This happens when an account is deleted while the app is disabled.
A new account with that name would own the vehicle and read all its rows.

First make sure the account is really gone.
With LDAP or another user backend, make sure the backend is reachable, or you erase the rows of a live account.

1. Make an account with the name from the finding, with a random password.
2. Restore the vehicles you want to keep with `nextfleet:restore`, and move them with `nextfleet:transfer`.
3. Delete the account.

   NextFleet replaces the name in the rows and moves the vehicles the account still owns to the trash.

Do the same for a `grantee` finding of an account.
For a deleted group, make and delete a group of that name.

## The upgrade output names an account

`ErasedPseudonyms` found a live account whose name looks like a deleted account from before 0.3.0.
NextFleet keeps it.
If someone made the account to take over those rows, delete it.
NextFleet then erases the name.
Otherwise, do nothing.

This is the end of the admin guide.
Back to the [start](README.md).
