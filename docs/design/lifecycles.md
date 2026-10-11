# Lifecycles

The states a row goes through and the calls that move it, for whoever adds a state or a transition.
A state is spelled as the value stored; the line below it names the constant or the column that
holds it. A transition names the method that makes it. [The index](README.md) explains the
notation.

## A reminder

`state` in `fleet_reminders`. While the occurrence is open, each sweep of a vehicle not disposed of stores what
`ReminderEngine::evaluate()` returns: where the reminder stands by date and by km, or snoozed. So a
sweep may skip a state, say from `planned` to `overdue`, and an edit or a corrected Reading can
move it back. Done and dismissed end the occurrence;
a reminder that recurs starts the next one instead, evaluated afresh. The rules are in
[the reminder engine](../architecture.md#reminder-engine).

```mermaid
stateDiagram-v2
    state "open" as open {
        state "byDate, byOdo" as axes {
            state "planned<br/>Reminder::PLANNED" as planned
            state "warned<br/>Reminder::WARNED" as warned
            state "due<br/>Reminder::DUE" as due
            state "overdue<br/>Reminder::OVERDUE" as overdue
            [*] --> planned
            planned --> warned : sweep, a warning point reached
            planned --> due : sweep, due with no warning before
            warned --> due : sweep, due_date or due_odo reached
            due --> overdue : sweep, the day after due_date
        }
        state "snoozed<br/>Reminder::SNOOZED<br/>until snoozed_until" as snoozed
        axes --> snoozed : snooze
        snoozed --> snoozed : snooze
        snoozed --> axes : sweep on snoozed_until
    }
    state "done<br/>Reminder::DONE" as done
    state "dismissed<br/>Reminder::DISMISSED" as dismissed
    [*] --> open : create
    open --> open : dismiss or closeBy, it recurs, occurrence + 1
    open --> open : reopenBy, it recurs, occurrence - 1
    open --> dismissed : dismiss, it does not recur
    open --> done : closeBy, it does not recur
    done --> open : reopenBy, the record withdrawn
```

`closeBy` is a maintenance record that names the reminder in its `reminder_id`. `reopenBy` takes
it back when the record is deleted or names another reminder, and when its day or counter changes,
which then closes it again from there; `recloseBy` closes it again when the record is restored. A
recurring reminder taken back steps back one occurrence (`ReminderEngine::retreat`). `delete` sets
`deleted_at`, `restore` clears it, and both leave `state` as it is.

## A booking

`state` in `fleet_bookings`. Only `booked` and `out` hold the vehicle (`Booking::LIVE`). The
handover writes the `out_*` and `in_*` columns; no write leaves `cancelled` or `returned`. The rules
are in [the data model](../architecture.md#data-model), the screens in
[the bookings section](../ui.md#the-bookings-section).

```mermaid
stateDiagram-v2
    direction LR
    state "booked<br/>Booking::BOOKED" as booked
    state "out<br/>Booking::OUT<br/>out_at, out_odo, out_level" as out
    state "returned<br/>Booking::RETURNED<br/>in_at, in_odo, in_level" as returned
    state "cancelled<br/>Booking::CANCELLED" as cancelled
    [*] --> booked : book
    booked --> booked : change
    booked --> cancelled : cancel
    booked --> out : checkOut
    out --> returned : checkIn
    returned --> returned : tie, trip_id set by TripService
```

## A vehicle

`lifecycle` in `fleet_vehicles`. Any state may follow any other: `update` takes the field like any
other, and the edit sheet clears `disposed_at` with any state but `disposed`. Deleting a vehicle sets
`deleted_at` and leaves `lifecycle` as it is. What each state stops is in
[the data model](../architecture.md#data-model) and [the reminder engine](../architecture.md#reminder-engine).

```mermaid
stateDiagram-v2
    direction LR
    state "active<br/>Vehicle::ACTIVE" as active
    state "laid_up<br/>Vehicle::LAID_UP" as laid_up
    state "disposed<br/>Vehicle::DISPOSED<br/>with disposed_at" as disposed
    [*] --> active : create, unless the request names another
    active --> laid_up : update
    laid_up --> active : update
    active --> disposed : update
    disposed --> active : update
    laid_up --> disposed : update
    disposed --> laid_up : update
```

## A receipt in the inbox

No column holds this state: `InboxService` works it out on each read. An image or PDF of the user's
own in their inbox folder waits while no live `fleet_documents` row on a live vehicle they reach
references it. The app never moves a file. The rules are in [the inbox](../architecture.md#the-inbox) and
[documents](../architecture.md#documents).

```mermaid
stateDiagram-v2
    direction LR
    state "waiting<br/>listed by InboxService" as waiting
    state "attached<br/>a fleet_documents row, deleted_at null" as attached
    [*] --> waiting : the file lands in inbox_folder
    waiting --> attached : attach
    attached --> waiting : detach, or its vehicle deleted or no longer reached
    waiting --> attached : restore, or its vehicle restored or reached again
    waiting --> [*] : moved out of the folder or deleted in Files
```

## A grant

A row in `fleet_access`. It is live from the insert, with its `role`; a revoke sets `deleted_at`,
and nothing restores it: granting again writes a new row. `took()` deletes a row outright when its
grantee was gone by the commit. Erasing the grantee's account keeps a user grant live under the
pseudonym in `grantee`. The rules are in
[Nextcloud integration](../architecture.md#nextcloud-integration) and
[the data model](../architecture.md#data-model).

```mermaid
stateDiagram-v2
    direction LR
    state "live<br/>deleted_at null<br/>role manager, driver or viewer" as live
    state "revoked<br/>deleted_at set" as revoked
    [*] --> live : grant, or transfer for a former owner who still exists
    live --> live : change, or grant to the same grantee
    live --> revoked : revoke, leave, revokeAll, forgetGroup
    live --> revoked : transfer, the new owner's own grant
    live --> [*] : grant, the grantee gone by its commit
```
