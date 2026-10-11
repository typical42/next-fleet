# Flows

The key requests and jobs, step by step, from the browser, client or timer to the database and
back. Read it to see which class does what in what order, before changing one of them. The access
check and the OCS request come first, since every other flow passes them. Mappers are drawn as the
database, each message naming the mapper call. [The index](README.md) explains the notation.

## The access check

`VehicleService::reach()` is the one gate on a vehicle; `VehicleService::change()` is the second
gate, on the Entry the route names. Editing a fill-up as an example. Each operation, role and
reason is in [Nextcloud integration](../architecture.md#nextcloud-integration) and
[authorization](../security.md#authorization).

```mermaid
sequenceDiagram
    participant Controller as EnergyController
    participant Energy as EnergyService
    participant Fleet as VehicleService
    participant Access as VehicleAccess
    participant Groups as IGroupManager
    participant db as database
    Controller->>Energy: update(userId, uuid, fillUp, updated_at, fields)
    Energy->>Fleet: reach(userId, LOG, uuid)
    Fleet->>db: VehicleMapper::findByUuid(uuid)
    break no live vehicle has this uuid
        Fleet-->>Controller: DoesNotExistException → 404
    end
    Fleet->>Access: operations(userId, vehicle)
    alt the caller is an erased account's pseudonym
        Access-->>Fleet: nothing
    else the caller is the vehicle's user_id
        Access-->>Fleet: view, log, edit, delete, own
    else anybody else
        Access->>Groups: getUserGroupIds(user)
        Access->>db: AccessMapper::findGrants(vehicle_id, userId, groupIds)
        Access-->>Fleet: what the grants' roles cover together
    end
    break log is not among them
        Fleet-->>Controller: AccessDeniedException → 403
    end
    Fleet-->>Energy: the Vehicle, carrying may
    Energy->>db: VehicleMapper::hold, EnergyMapper::findOnVehicle(vehicle_id, fillUp)
    Energy->>Fleet: change(userId, EDIT, vehicle, created_by)
    Fleet->>Access: mayChange(userId, EDIT, vehicle, created_by)
    Note over Access: reads may off the vehicle, no query
    break neither edit, nor log on a fill-up the caller entered
        Fleet-->>Controller: AccessDeniedException → 403
    end
    Energy->>db: EnergyMapper::updateChecked(fill-up, updated_at)
    Note over Energy: OdometerService::followEntry moves its Readings, in the same atomicRetry
    Energy-->>Controller: the row
```

A fill-up of another vehicle is not found by `findOnVehicle`, so it is a 404 too. A booking passes
`VehicleAccess::mayBooking()` in the second gate's place, with the booker for `created_by`.
`EntryAnswers` and `OcsAnswers` turn each exception into its status.

## A request with an app password

A client signs in with an app password over Basic auth ([signing in](../api.md#signing-in)).
Nextcloud checks it and the limits before the app runs; the app's middleware and the OCS twin of
the web controller come after. The same edit as above, through the OCS door. The statuses are in
[answers](../api.md#answers), the limits in [rate limits](../api.md#rate-limits), the token in
[writes and tokens](../api.md#writes-and-tokens).

```mermaid
sequenceDiagram
    participant Client as client
    box Nextcloud server
        participant OCS as ocs/v2.php
        participant Dispatcher
        participant Security as SecurityMiddleware
        participant Limit as RateLimitingMiddleware
        participant Envelope as OCSMiddleware
    end
    participant Placeholder as PlaceholderMiddleware
    participant Controller as Ocs\EnergyController
    participant Energy as EnergyService
    Client->>OCS: PUT /ocs/v2.php/apps/nextfleet/api/v1/vehicles/{uuid}/energy/{fillUp}<br/>Basic auth, OCS-APIRequest: true, updated_at in the body
    OCS->>OCS: sign in with the app password
    OCS->>Dispatcher: the matched route
    Dispatcher->>Security: beforeController
    break no login, or a wrong or revoked app password
        Security-->>Client: 401
    end
    Note over Security: OCS-APIRequest: true passes the CSRF check
    Dispatcher->>Limit: beforeController
    break past UserRateLimit, 60 a minute for this method
        Limit-->>Client: 429
    end
    Dispatcher->>Placeholder: beforeController
    break a body field named like a path parameter, with another value
        Placeholder-->>Client: 400
    end
    Dispatcher->>Controller: update(uuid, fillUp, updated_at, …)
    Controller->>Controller: token(): updated_at, else 400
    Controller->>Energy: update(userId, uuid, fillUp, token, params)
    Note over Energy: the access check above
    alt written
        Energy-->>Controller: the row
        Controller-->>Dispatcher: DataResponse 200
    else updated_at is not the row's any more
        Energy-->>Controller: StaleUpdateException
        Controller-->>Dispatcher: DataResponse 412, conflict: true
    else not found, not allowed
        Controller-->>Dispatcher: OCSNotFoundException, OCSForbiddenException
    end
    Dispatcher->>Envelope: afterController, afterException
    Envelope-->>Client: the OCS envelope, its statuscode as the HTTP status
```

The web door is the same from the dispatcher on, with `EnergyController` and `EntryAnswers`; its
CSRF check takes the session's `requesttoken`.

## A fill-up and its figures

### Saving it

The entry sheet posts the fill-up; the service writes it and its Readings under the vehicle's
hold, in one transaction. A retried create is answered, not written again
([retried creates](../api.md#retried-creates), [concurrency](../architecture.md#concurrency)). How a
Reading is flagged is in [odometer rules](../architecture.md#odometer-rules).

```mermaid
sequenceDiagram
    actor User as Driver
    participant Sheet as EntrySheet
    participant Store as useVehiclesStore
    participant api as api.js
    participant Controller as EnergyController
    participant Energy as EnergyService
    participant Once
    participant Odometer as OdometerService
    participant db as database
    User->>Sheet: save
    Sheet->>Store: fill(uuid, entry with client_uuid)
    Store->>api: recordEnergy(uuid, fill)
    api->>Controller: POST /apps/nextfleet/api/vehicles/{uuid}/energy
    Controller->>Energy: record(userId, uuid, params)
    Note over Energy: reach(userId, LOG, uuid), the access check
    Energy->>Once: of(fields, EnergyMapper), run()
    opt the request names a client_uuid
        Once->>db: EnergyMapper::findAnyByUuid(client_uuid)
    end
    break this vehicle has the client_uuid's row
        Once-->>Controller: AlreadyCreatedException → 200, the first row
    end
    break another row has the client_uuid
        Once-->>Controller: InvalidArgumentException → 400
    end
    critical atomicRetry
        Energy->>db: VehicleMapper::hold(vehicle_id)
        Energy->>Once: check()
        Energy->>Energy: add(): apply() reads each field
        Energy->>db: EnergyMapper::insert → fleet_energy
        Energy->>Odometer: followEntry(vehicle, userId, ENERGY, id, filled_at, …, odo, second_odo, fresh)
        loop each counter the fill-up names
            Odometer->>db: OdoReadingMapper::insert → fleet_odo_readings
            Odometer->>db: OdoReadingMapper::findChain, flag() where a flag changes
            Odometer->>db: VehicleMapper::cacheOdoValue or cacheSecondValue, the counter's own
        end
    end
    Energy-->>Controller: the row and its flags
    Controller-->>api: 201
    api-->>Store: the row
    Store->>api: getVehicle(uuid), for odo_value
    Sheet-->>User: closes, emits saved
```

A field the service refuses is a 400 and the sheet stays open with what was typed
([the entry sheet](../ui.md#the-entry-sheet-in-detail)).

### The figures it moves

On `saved`, the vehicle screen reloads its timeline, its KPI header and its bookings; the bookings
are left out here. The first two read the same
segments from `ConsumptionService`, worked out on read and never stored
([numbers](../architecture.md#numbers-consumption-cost-emissions),
[the timeline](../architecture.md#the-timeline)). The calls go through `api.js`, left out here.

```mermaid
sequenceDiagram
    participant View as VehicleView
    participant Header as KpiHeader
    participant Timeline
    participant KpiController
    participant Kpi as KpiService
    participant Cost as CostService
    participant TimelineController
    participant Rows as TimelineService
    participant Consumption as ConsumptionService
    participant db as database
    View->>Header: reload()
    View->>Timeline: reload()
    par this period and the one before
        Header->>KpiController: GET …/vehicles/{uuid}/kpis?from&to&net
        KpiController->>Kpi: of(userId, uuid, params)
        Note over Kpi: reach(userId, VIEW, uuid)
        Kpi->>Cost: of(vehicle, from, to, net)
        Cost->>db: EnergyMapper, MaintenanceMapper, ExpenseMapper::findBetween
        Cost->>Consumption: distances(vehicle, periods), per counter
        Kpi->>Consumption: measuredOnEachCounter(), wallSide()
        Consumption->>db: EnergyMapper::findAllForVehicle, OdoReadingMapper::findOfSourceType, findResets
        Consumption->>Consumption: segments() per counter
        Kpi-->>Header: consumption, cost, measured, hours
    and the newest page
        Timeline->>TimelineController: GET …/vehicles/{uuid}/timeline
        TimelineController->>Rows: page(userId, uuid, type, cursor)
        Note over Rows: reach(userId, VIEW, uuid)
        Rows->>db: each kind's findBefore, OdoReadingMapper::findEntriesBefore
        Rows->>Consumption: ofEachCounter(vehicle), when the page has a fill-up
        Consumption->>db: the same chain reads
        Rows-->>Timeline: rows, each fill-up with consumption, next
    end
```

## A booking to its handover

A driver books a vehicle, takes it, brings it back, and logs the trip from the booking. Booking and
handover write `fleet_bookings` only; the trip writes the Reading. The rules are in the data
model's bookings paragraphs ([data model](../architecture.md#data-model)), the states in
[Pool](../../CONTEXT.md).

```mermaid
sequenceDiagram
    actor Driver
    participant Controller as BookingController
    participant Booking as BookingService
    participant TripController
    participant Trip as TripService
    participant Odometer as OdometerService
    participant db as database
    Driver->>Controller: POST …/bookings, starts_at, ends_at, purpose
    Controller->>Booking: book(userId, uuid, fields)
    Note over Booking: reach(userId, LOG, uuid), an active vehicle
    Booking->>db: VehicleMapper::hold, BookingMapper::findLiveOverlapping(vehicle_id, starts_at, ends_at)
    break a booked or out booking holds part of the span
        Booking-->>Controller: BookingConflictException → 409, that booking
    end
    Booking->>db: BookingMapper::insert, state booked
    Booking-->>Controller: 201, the booking with may
    Driver->>Controller: POST …/bookings/{booking}/check-out, odo, level, notes
    Controller->>Booking: checkOut(userId, uuid, booking, fields)
    Booking->>db: VehicleMapper::hold, BookingMapper::findOnVehicle
    Note over Booking: VehicleAccess::mayBooking(), state booked, before ends_at
    Booking->>db: BookingMapper::findOut, findLiveOverlapping for an early start
    break another booking is out, or an early start meets a live one
        Booking-->>Controller: 409, that booking
    end
    Booking->>db: BookingMapper::updateChecked, state out, out_at, out_odo, out_level, out_notes
    Booking-->>Controller: 200
    Driver->>Controller: POST …/bookings/{booking}/check-in, odo, level, notes
    Controller->>Booking: checkIn(userId, uuid, booking, fields)
    Booking->>db: VehicleMapper::hold, BookingMapper::findOnVehicle
    Note over Booking: VehicleAccess::mayBooking(), state out
    Booking->>db: BookingMapper::updateChecked, state returned, in_at, in_odo, in_level, in_notes
    Booking-->>Controller: 200, the booking with trip_draft
    Driver->>TripController: POST …/trips, the draft's fields, booking_uuid
    TripController->>Trip: record(userId, uuid, fields)
    critical atomicRetry
        Trip->>db: VehicleMapper::hold, TripMapper::insert
        opt a kept logbook
            Trip->>db: AuditMapper::insert
        end
        Trip->>Odometer: fromTrip(vehicle, trip)
        Odometer->>db: the trip's Readings, settled
        Trip->>Booking: tie(userId, vehicle, booking_uuid, trip)
        Booking->>db: BookingMapper::findOnVehicle
        Note over Booking: VehicleAccess::mayBooking()
        break no such booking 404, not the caller's 403, not returned or has its trip 409
            Booking-->>TripController: the exception, the trip rolled back
        end
        Booking->>db: BookingMapper::updateChecked, trip_id
    end
    TripController-->>Driver: 201, the trip
```

Neither handover takes the `updated_at` token. Check-in passes on any lifecycle: a car laid up while
out still comes back. A cancel by anybody but the booker notifies the booker
(`BookingNotices::tellCancelled`).

## The reminder round

`ReminderJob` runs hourly and hands the round to `NotificationService`, then to `MailService`.
Every rule is in [the reminder engine](../architecture.md#reminder-engine): the state at an
instant, who is told, the job and the digest.

### The notifications

Each vehicle is evaluated under its hold, one transaction each. A receipt is claimed before the
send, and the send waits for the commit, so a second run sends nothing and a rollback leaves no
notice without its receipt.

```mermaid
sequenceDiagram
    participant Cron as Nextcloud cron
    participant Job as ReminderJob
    participant Notices as NotificationService
    participant Engine as ReminderEngine
    participant Users as IUserManager
    participant Manager as IManager
    participant db as database
    Cron->>Job: run(), every 3600 s
    Job->>Notices: sweep()
    Notices->>db: VehicleMapper::findReminded()
    loop each vehicle not disposed, with a live reminder
        critical atomic
            Notices->>db: VehicleMapper::hold, findByUuid, OdoReadingMapper::findNewestAtOrBefore(MAIN)
            Notices->>db: ReminderRecipientMapper::findByVehicle, none while laid_up
            Notices->>db: ReminderMapper::findByVehicle
            loop each reminder
                Notices->>Engine: evaluate(reminder, today, odo)
                Engine-->>Notices: state, point
                opt the state changed
                    Notices->>db: ReminderMapper::updateChecked, state
                end
                alt no point, and the state moved
                    Note over Notices: queues withdraw(reminder)
                else a point
                    loop each recipient
                        Notices->>Users: userExists(user_id)
                        Notices->>db: ReminderReceiptMapper::claim(reminder, point, APP, user_id)
                        Note over Notices: a new receipt queues a send
                    end
                end
            end
        end
        loop each queued send, after the commit
            Note over Notices: a queued withdraw: markProcessed for everybody
            Notices->>Manager: markProcessed, the recipient's older point
            Notices->>Manager: notify, the point and its parameters
            opt the send fails
                Notices->>db: ReminderReceiptMapper::forget(receipt)
            end
        end
    end
```

A vehicle or a send that fails is logged and the round goes on. Snoozing, dismissing and deleting
withdraw through `NotificationService::withdraw()` too, from `ReminderService`.

### The digest

After the notifications, at most one mail a day per recipient, from 07:00 in their zone, on the
days each vehicle's `reminder_mail` names, with the points this user has no `mail` receipt for.

```mermaid
sequenceDiagram
    participant Job as ReminderJob
    participant Mail as MailService
    participant Zone as UserZone
    participant Config as IConfig
    participant Engine as ReminderEngine
    participant Mailer as IMailer
    participant db as database
    Job->>Mail: digest()
    Mail->>db: VehicleMapper::findReminded(), with each recipient list
    Mail->>Config: getUserValueForUsers(digest_checked)
    loop each recipient, enabled, with an address
        Mail->>Zone: of(user_id)
        break before 07:00 their time, or this day checked with the same vehicles
            Note over Mail: next recipient
        end
        Mail->>db: ReminderReceiptMapper::lastSent(user_id, MAIL)
        alt not mailed today, and a vehicle's reminder_mail falls today
            loop each such vehicle, one transaction each
                Mail->>db: VehicleMapper::hold, findByUuid, ReminderMapper::findByVehicle
                Mail->>db: OdoReadingMapper::findNewestAtOrBefore(MAIN)
                loop each reminder
                    Mail->>Engine: evaluate(reminder, today, odo)
                    Mail->>db: ReminderReceiptMapper::claim(reminder, point, MAIL, user_id)
                end
            end
            opt news
                Mail->>Mailer: createEMailTemplate, send: ReminderWords lines under each VehicleWords::name
                Note over Mail: a vehicle linked only where the reader may view it
            end
            break the mail fails
                Mail->>db: ReminderReceiptMapper::forget(claimed)
                Note over Mail: logged, next recipient, day not marked
            end
        end
        Mail->>Config: setUserValue(digest_checked, day and vehicles)
    end
```

### Reading a notice

A reminder notice stores parameters. `Notifier::prepare()` words them when they are read, and drops
one that no longer stands.

```mermaid
sequenceDiagram
    actor Reader as Recipient
    participant Manager as IManager
    participant Notifier
    participant Access as VehicleAccess
    participant db as database
    Reader->>Manager: open the notifications
    Manager->>Notifier: prepare(notification, languageCode)
    Notifier->>db: VehicleMapper::findByUuid(vehicle)
    break the vehicle is gone
        Notifier-->>Manager: AlreadyProcessedException
    end
    Notifier->>Notifier: ReminderWords::line, the plate or VehicleWords::name
    Notifier->>db: ReminderMapper::findByVehicle, ReminderRecipientMapper::findByVehicle
    break the reminder is gone, done, dismissed, snoozed or moved on, or the reader is off the list
        Notifier-->>Manager: AlreadyProcessedException
    end
    Notifier->>Access: may(user, VIEW, vehicle)
    opt view
        Notifier->>Notifier: setLink, …/apps/nextfleet/?vehicle=uuid
    end
    Notifier-->>Manager: the worded notification
    Manager-->>Reader: plate: line
```

## An import

Another tool's CSV, picked in the caller's Files, becomes entries in two requests. Nothing is kept
between them: the import sends the preview's body again with the file's `etag` and the file is read
again. Every rule is in [import](../architecture.md#import); the classes are in
[domain](domain.md#import).

### The preview

```mermaid
sequenceDiagram
    actor User as Manager
    participant Sheet as ImportSheet
    participant Controller as ImportController
    participant Import as ImportService
    participant Files as OwnFiles
    participant Reader as CsvReader
    participant Importer as IImporter
    participant Duplicates
    participant db as database
    User->>Sheet: pick a CSV in Files
    Sheet->>Controller: POST …/import/preview, file_id, importer, record_type, units, tz, answers
    Controller->>Import: preview(userId, uuid, fields)
    Note over Import: reach(userId, EDIT, uuid), not disposed, Importers::get(importer)
    Import->>Files: find(userId, file_id)
    break not the caller's own file
        Files-->>Controller: DoesNotExistException → 404
    end
    Import->>Files: open(file)
    Import->>Reader: open(stream), rows()
    break past a cap, or not text: the whole file
        Reader-->>Controller: ImportRefusedException → 422, reason and row
    end
    Import->>Importer: columns(), propose(record_type, header, rows, answers)
    Importer-->>Import: Proposals: each row new or unreadable, the open questions
    Import->>db: each kind's findBetween, the file's days and two either side
    Import->>Duplicates: of(live), mark(proposals)
    Import-->>Controller: columns, questions, counts, reasons, 50 proposals, etag
    Controller-->>Sheet: 200
    Sheet-->>User: the counts and the questions
```

A file being written is a 423. Each answer the user gives previews again.

### The import

```mermaid
sequenceDiagram
    actor User as Manager
    participant Sheet as ImportSheet
    participant Store as useVehiclesStore
    participant Controller as ImportController
    participant Import as ImportService
    participant Cache as ICache
    participant Odometer as OdometerService
    participant Entries as EnergyService, MaintenanceService, ExpenseService
    participant db as database
    User->>Sheet: import
    Sheet->>Store: bring(uuid, the preview's body and etag)
    Store->>Controller: POST …/import, through api.js
    Controller->>Import: import(userId, uuid, fields)
    break the etag moved
        Import-->>Controller: FileChangedException → 409, the sheet previews again
    end
    Import->>Cache: the last import's answer for this vehicle
    break the same request was answered
        Import-->>Controller: that answer
    end
    Import->>Import: propose() again, the file read again
    Import->>Import: OwnFiles::find again
    break the etag moved while it was read
        Import-->>Controller: FileChangedException → 409
    end
    break a question is open
        Import-->>Controller: 400
    end
    critical AfterCommit::run
        Import->>db: VehicleMapper::hold(vehicle_id)
        Import->>Cache: the answer again, for a twin that waited
        Import->>db: each kind's findBetween, duplicates marked again
        Import->>Odometer: batch(vehicle, write)
        loop each row the preview counted as creates
            Import->>Entries: add(vehicle, userId, fields)
            Entries->>db: insert, its Readings
        end
        Odometer->>db: settle(), each chain once
        Import->>db: BaseMapper::restamp, each written row
        Import->>Cache: set the answer, one hour
    end
    Import-->>Controller: counts, created as type and uuid
    Controller-->>Store: 200
    Note over Store: getVehicle(uuid), the vehicle read again
    Sheet-->>User: closes, UndoToast shows the counts and an undo
```

An odometer row goes through `OdometerService::add()` instead. A row a service refuses stops the
whole import with a 400 naming the row, and the cache entry goes.
`occ nextfleet:import` sends the same two calls through `ImportService` as the user it names.

## A personal data export

Nextcloud's *user_migration* app exports an account through `FleetMigrator`. What is exported
whole and what is withheld is in [personal data export](../architecture.md#personal-data-export).

```mermaid
sequenceDiagram
    participant Migration as user_migration
    participant Migrator as FleetMigrator
    participant Tables as AccountTables
    participant Access as VehicleAccess
    participant Destination as IExportDestination
    participant db as database
    Migration->>Migrator: export(user, destination, output)
    Migrator->>Tables: all()
    loop each table that names an account
        Migrator->>db: the table's mapper, findNaming(uid), deleted rows too
        loop each row
            alt AUTHORED, or not written by the account
                Migrator->>Migrator: every column as stored
            else written by the account on a vehicle
                Migrator->>Access: may(uid, VIEW, vehicle)
                Note over Migrator: no view: uuid, created_by, created_at and withheld
            end
        end
        Migrator->>Destination: addFileContents(nextfleet/table.json)
    end
    Migration->>Migrator: import(user, source, output)
    Migrator-->>Migration: a line saying NextFleet does not restore
```

The import reads nothing from the source and writes nothing, so another app's import runs on.
