# Lifecycles

The states a row goes through, and the calls that move it.
A state is spelled as stored.
The line below it names the constant that holds it.
[The index](README.md) explains the notation.

## A reminder

`state` in `fleet_reminders`.
Each sweep stores what `ReminderEngine::evaluate()` returns, so a sweep may skip a state, and an edit or a corrected Reading may move it back.
Done and dismissed end the occurrence.
A recurring reminder starts the next one instead ([the reminder engine](../architecture.md#reminder-engine)).

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

`closeBy` is a maintenance record that names the reminder.
`reopenBy` takes it back when the record is deleted or changed.
A recurring reminder taken back steps back one occurrence (`ReminderEngine::retreat`).
Delete and restore leave `state` as it is.

## A booking

`state` in `fleet_bookings`.
Only `booked` and `out` hold the vehicle (`Booking::LIVE`).
Nothing leaves `cancelled` or `returned` ([bookings](../architecture.md#bookings)).

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

`lifecycle` in `fleet_vehicles`.
Any state may follow any other.
Deleting sets `deleted_at` and leaves `lifecycle` as it is.

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

No column holds this state: `InboxService` works it out on each read.
The app never moves a file ([the inbox](../architecture.md#the-inbox)).

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

A row in `fleet_access`.
A revoke sets `deleted_at`, and nothing restores it: granting again writes a new row.
A grant whose grantee was gone by the commit is deleted outright ([access](../architecture.md#access)).

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

Back to the [index](README.md).
