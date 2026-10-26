# Context

What NextFleet does for whom, and where it runs.
Read it first: the actors and their use cases, then the machines around the app.
[The index](README.md) explains the notation.

## Use cases

An actor is a role from [CONTEXT.md](../../CONTEXT.md), an account, a program or a timed job.

### By role

Each role can do all that the role it points to can, and more.
Each use case names the operation it needs.
Why each operation reaches that far is in [access](../architecture.md#access).
What each screen offers is in [screens follow the role](../ui.md#screens-follow-the-role).

```mermaid
flowchart LR
    Owner["«actor»<br/>Owner"]
    Manager["«actor»<br/>Manager"]
    Driver["«actor»<br/>Driver"]
    Viewer["«actor»<br/>Viewer"]
    Owner -->|is a| Manager -->|is a| Driver -->|is a| Viewer
    subgraph NextFleet
        read(["Read a vehicle, its timeline and figures<br/>view"])
        print(["Print the logbook and the Mileage Claim,<br/>export CSV<br/>view"])
        log(["Log an Entry, change one they entered<br/>log"])
        book(["Book an active vehicle<br/>log"])
        edit(["Change the vehicle, its Reminders<br/>and Recipients<br/>edit"])
        any(["Change and delete anybody's Entry<br/>edit, delete"])
        import(["Import Entries from a CSV file<br/>edit"])
        grant(["Grant, re-role and revoke access<br/>own"])
        exist(["Delete and restore the vehicle<br/>own"])
    end
    Viewer --- read & print
    Driver --- log & book
    Manager --- edit & any & import
    Owner --- grant & exist
```

### Access

A grantee is a user, or each member of a group, with a grant on a vehicle.
Nextcloud is an actor too: deleting a group or a membership ends what it reached.

```mermaid
flowchart LR
    Owner["«actor»<br/>Owner"]
    Grantee["«actor»<br/>grantee"]
    Nextcloud["«actor»<br/>Nextcloud"]
    subgraph NextFleet
        grant(["Grant access to a user or a group"])
        notify(["Notify the grantee"])
        rerole(["Re-role a grant"])
        revoke(["Revoke a grant"])
        dropRecipients(["Take off Recipients<br/>who lose view"])
        holders(["See who holds the vehicle"])
        leave(["Leave"])
        groupGone(["Revoke a deleted group's grants"])
        memberGone(["Forget a member who left a group"])
    end
    Owner --- grant & rerole & revoke
    grant -.->|«include»| notify
    notify --- Grantee
    Grantee --- holders & leave
    revoke & leave & groupGone & memberGone -.->|«include»| dropRecipients
    Nextcloud --- groupGone & memberGone
```

### Entries and papers

Every Entry but an Expense writes a Reading.
A paper stays in its owner's Files.
The rules are in [odometer rules](../architecture.md#odometer-rules), [documents](../architecture.md#documents) and [the inbox](../architecture.md#the-inbox).

```mermaid
flowchart LR
    Driver["«actor»<br/>Driver"]
    Files["«actor»<br/>Nextcloud Files"]
    subgraph NextFleet
        trip(["Log a Trip"])
        energy(["Log an Energy Entry"])
        maintenance(["Log a Maintenance Record"])
        expense(["Log an Expense"])
        odometer(["Log an Odometer Entry"])
        reading(["Write a Reading"])
        reconcile(["Close a Gap<br/>with a Reconciliation Trip"])
        close(["Close a Reminder"])
        attach(["Attach a Document"])
        inbox(["File a receipt from the Inbox"])
    end
    Driver --- trip & energy & maintenance & expense & odometer & attach & inbox
    trip & energy & maintenance & odometer -.->|«include»| reading
    reconcile -.->|«extend»| trip
    close -.->|«extend»| maintenance
    inbox -.->|«include»| attach
    attach --- Files
```

### Pool

A booking is the booker's.
A manager may change, cancel, check out and check in anybody's ([bookings](../architecture.md#bookings), [the Bookings section](../ui.md#the-bookings-section)).

```mermaid
flowchart LR
    Driver["«actor»<br/>Driver"]
    Manager["«actor»<br/>Manager"]
    subgraph NextFleet
        book(["Book the vehicle"])
        change(["Change a Booking"])
        checkOut(["Check out"])
        checkIn(["Check in"])
        logTrip(["Log the Trip from the Booking"])
        photo(["Attach a handover photo"])
        cancel(["Cancel a Booking"])
        notify(["Notify the booker"])
    end
    Driver --- book & change & checkOut & checkIn & cancel
    Manager --- change & checkOut & checkIn & cancel
    logTrip -.->|«extend»| checkIn
    photo -.->|«extend»| checkOut & checkIn
    notify -.->|«extend» cancelled by another| cancel
```

### Reminders

`ReminderJob` runs hourly from Nextcloud's cron ([the reminder engine](../architecture.md#reminder-engine)).

```mermaid
flowchart LR
    Manager["«actor»<br/>Manager"]
    Viewer["«actor»<br/>Viewer"]
    Job["«actor»<br/>ReminderJob"]
    Recipient["«actor»<br/>Recipient"]
    Mail["«actor»<br/>mail server"]
    subgraph NextFleet
        set(["Set a Reminder,<br/>from a Reminder Template or not"])
        recipients(["Choose the Recipients<br/>and the mail cadence"])
        snooze(["Snooze or dismiss a Reminder"])
        due(["See what is due:<br/>overview, vehicle, Dashboard"])
        evaluate(["Evaluate the due Reminders"])
        notify(["Notify a Recipient in Nextcloud"])
        digest(["Mail the Digest"])
    end
    Manager --- set & recipients & snooze
    Viewer --- due
    Job --- evaluate
    evaluate -.->|«include»| notify
    evaluate -.->|«include»| digest
    notify --- Recipient
    digest --- Recipient
    digest --- Mail
```

### Accounts and clients

Any account may create a vehicle and so becomes its owner.
A client is a program that acts for one account through the [OCS API](../api.md), with an app password.

```mermaid
flowchart LR
    User["«actor»<br/>account"]
    Client["«actor»<br/>client"]
    Migration["«actor»<br/>user_migration"]
    subgraph NextFleet
        create(["Create a vehicle"])
        settings(["Set the personal settings<br/>and the Inbox folder"])
        search(["Find a vehicle in the search"])
        export(["Export personal data"])
        signIn(["Sign in with an app password"])
        api(["Read and write through /api/v1"])
        sync(["Sync what changed"])
    end
    User --- create & settings & search & export
    Client --- signIn & api & sync
    export --- Migration
    api -.->|«include»| signIn
    sync -.->|«include»| signIn
```

### Administration

The admin works through `occ` and Nextcloud's settings ([occ commands](../admin/occ.md)).
Deleting an account is Nextcloud's use case.
The app answers it by pseudonymising the rows ([ADR 0008](../adr/0008-erasing-a-driver-pseudonymises.md)).
`PendingJob` finishes work that a failure cut short.

```mermaid
flowchart LR
    Admin["«actor»<br/>admin"]
    Pending["«actor»<br/>PendingJob"]
    subgraph NextFleet
        find(["Find vehicles, access and audit rows<br/>vehicles, access, audit"])
        check(["Check and repair<br/>check, recompute, mail-test"])
        rounds(["Run a job's round now<br/>reminders --send, pending --finish"])
        move(["Restore, transfer, import<br/>restore, transfer, import"])
        seed(["Seed a demo fleet<br/>seed"])
        erase(["Erase a deleted account"])
        finish(["Finish pending work"])
    end
    Admin --- find & check & rounds & move & seed & erase
    Pending --- finish
```

## Deployment

One Nextcloud server, and no service of the app's own ([stack and layers](../architecture.md#stack-and-layers), [requirements](../admin/README.md#requirements)).

```mermaid
flowchart LR
    subgraph browser["«device» browser"]
        bundle["«artifact»<br/>js/nextfleet-main.mjs<br/>js/nextfleet-settings.mjs"]
    end
    subgraph phone["«device» phone"]
        clientApp["«artifact»<br/>client"]
        ncApp["«artifact»<br/>Nextcloud app"]
    end
    subgraph host["«execution environment» Nextcloud 31–35 on PHP"]
        web["«component»<br/>web server"]
        cron["«component»<br/>cron.php"]
        occ["«component»<br/>occ"]
        app["«component»<br/>nextfleet<br/>custom_apps/nextfleet"]
        notifications["«component»<br/>notifications app"]
        files[("«artifact»<br/>Files storage")]
    end
    db[("«device» database<br/>MariaDB, MySQL, PostgreSQL,<br/>SQLite or Oracle<br/>oc_fleet_*")]
    smtp["«device»<br/>mail server"]
    bundle -->|"HTTPS /apps/nextfleet"| web
    clientApp -->|"HTTPS /ocs/v2.php/apps/nextfleet/api/v1"| web
    web --> app
    cron -->|"ReminderJob, PendingJob,<br/>queued jobs"| app
    occ -->|"nextfleet:*"| app
    app -->|IDBConnection| db
    app -->|IRootFolder| files
    app -->|INotifier| notifications
    notifications -->|push| ncApp
    app -->|"IMailer, SMTP"| smtp
```

Next: [Components](components.md)
