# Components

The parts the app is built from and which part calls which, for someone looking for where a change
goes. First the backend by layer and its services by area, then the frontend, then the path one
request takes through both. [The index](README.md) explains the notation.

## Backend

### Layers

Controller → Service → QBMapper → Entity, as [stack and layers](../architecture.md#stack-and-layers)
sets it out. Every rule lives in a service; both controller doors only translate a request into a
call and a refusal into a status.

```mermaid
flowchart TB
    subgraph doors["«layer» controllers"]
        Placeholder["«component»<br/>PlaceholderMiddleware"]
        Controller["«component»<br/>Controller<br/>EntryAnswers, ImportAnswers"]
        Ocs["«component»<br/>Controller\Ocs<br/>OcsAnswers"]
    end
    subgraph rules["«layer» services"]
        Service["«component»<br/>Service"]
        Jurisdiction["«component»<br/>Jurisdiction<br/>Jurisdictions, De, Generic"]
        Import["«component»<br/>Import<br/>Importers, CsvReader, Proposals"]
        Exception["«component»<br/>Exception"]
    end
    subgraph storage["«layer» persistence"]
        Mappers["«component»<br/>Db mappers<br/>BaseMapper extends QBMapper"]
        Entities["«component»<br/>Db entities<br/>BaseEntity extends Entity"]
        Migration["«component»<br/>Migration"]
    end
    db[("«device»<br/>database<br/>oc_fleet_*")]
    Placeholder -.->|before every controller| Controller & Ocs
    Controller & Ocs --> Service
    Controller & Ocs -->|answer| Exception
    Service -->|throws| Exception
    Service --> Jurisdiction & Import
    Service --> Mappers
    Mappers --> Entities
    Mappers -->|IDBConnection| db
    Migration -->|ISchemaWrapper| db
```

`PlaceholderMiddleware` refuses a body field named like a URL placeholder with a 400 before either
controller runs; its docblock says why.

### Other doors into the services

The controllers are one way in. Nextcloud calls the app through the interfaces `Application`
registers, through the timed jobs, commands and repair steps in `appinfo/info.xml`, and through
the jobs the app queues itself (`GrantNoticeJob`, `ForgetMemberJob`). Some read the
mappers directly, as an admin or as Nextcloud, where no user's access applies. Drawn from
[Nextcloud integration](../architecture.md#nextcloud-integration).

```mermaid
flowchart LR
    NC["«component»<br/>Nextcloud server"]
    subgraph nextfleet
        Controllers["«component»<br/>Controller, Controller\Ocs"]
        Jobs["«component»<br/>BackgroundJob<br/>ReminderJob, PendingJob,<br/>GrantNoticeJob, ForgetMemberJob"]
        Commands["«component»<br/>Command<br/>nextfleet:*"]
        Listeners["«component»<br/>Listener<br/>UserDeletedListener,<br/>GroupDeletedListener,<br/>GroupMemberRemovedListener"]
        Notifier["«component»<br/>Notification\Notifier"]
        Search["«component»<br/>VehicleSearchProvider"]
        Widget["«component»<br/>DueWidget"]
        Migrator["«component»<br/>FleetMigrator"]
        Repair["«component»<br/>Repair<br/>ErasedPseudonyms,<br/>StrangerRecipients"]
        Service["«component»<br/>Service"]
        Mappers["«component»<br/>Db mappers"]
    end
    NC -->|HTTP| Controllers
    NC -->|cron| Jobs
    NC -->|occ| Commands
    NC -->|events| Listeners
    NC -->|INotifier| Notifier
    NC -->|IProvider| Search
    NC -->|IAPIWidgetV2| Widget
    NC -->|IMigrator| Migrator
    NC -->|IRepairStep| Repair
    Controllers & Jobs & Commands & Listeners & Notifier & Search & Widget & Migrator & Repair --> Service
    Jobs & Commands & Listeners & Notifier & Migrator --> Mappers
```

`Settings\Personal` is left out: it only renders the template the settings bundle mounts on.

### Services by area

An arrow is a service another one is given in its constructor, or, for `ReminderEngine`, calls
statically. An unlabelled arrow into `VehicleService` is a call of `reach()`. Mappers, `OCP`
interfaces and the helpers of `lib/Service` (`AfterCommit`, `Field`, `Once`, `OwnFiles`,
`MoneyRows`, `Registration` and the like) are left out, and `Jurisdictions` is drawn only where the
figures are read, though most services ask it. `InboxService` asks only `PreferencesService` and
`VehicleAccess`; [the domain](domain.md#documents-and-the-inbox) draws it.

#### Writes pass the gate

Every service that writes for a user reaches the vehicle through `VehicleService::reach()`, the one
access check ([Nextcloud integration](../architecture.md#nextcloud-integration)). An Entry that
knows a counter settles it through `OdometerService` ([odometer rules](../architecture.md#odometer-rules)).

```mermaid
flowchart LR
    Trip["TripService"]
    Energy["EnergyService"]
    Maintenance["MaintenanceService"]
    Expense["ExpenseService"]
    ImportSvc["ImportService"]
    Booking["BookingService"]
    Document["DocumentService"]
    Reminder["ReminderService"]
    Recipient["RecipientService"]
    Grant["GrantService"]
    Odometer["OdometerService"]
    Vehicle["VehicleService"]
    Access["VehicleAccess"]
    Gaps["Gaps"]
    Periods["LogbookPeriods"]
    ImportSvc --> Energy & Maintenance & Expense
    Trip & Energy & Maintenance & ImportSvc --> Odometer
    Trip --> Booking & Gaps & Periods
    Maintenance --> Reminder
    Trip & Energy & Maintenance & Expense & ImportSvc & Booking & Document & Reminder & Recipient & Grant & Odometer --> Vehicle
    Vehicle --> Access
    Booking & Document & Recipient & Grant --> Access
```

#### Reading the figures

What the timeline, the KPI header and the exports compute. The five in the top row each reach the
vehicle through `VehicleService` first, left out here. Country rules come from `Jurisdictions`.
Drawn from [the timeline](../architecture.md#the-timeline),
[numbers](../architecture.md#numbers-consumption-cost-emissions),
[the Fahrtenbuch export](../architecture.md#the-fahrtenbuch-export),
[the mileage claim](../architecture.md#the-mileage-claim) and [CSV export](../architecture.md#csv-export).

```mermaid
flowchart TB
    Kpi["KpiService"]
    Timeline["TimelineService"]
    Export["ExportService"]
    Logbook["LogbookExport"]
    Claim["MileageClaimExport"]
    Cost["CostService"]
    Emission["EmissionService"]
    Preferences["PreferencesService"]
    Consumption["ConsumptionService"]
    Gaps["Gaps"]
    EnteredBy["EnteredBy"]
    Periods["LogbookPeriods"]
    Completeness["Completeness"]
    Jurisdictions["Jurisdictions"]
    Kpi --> Cost & Emission & Preferences & Consumption
    Cost --> Consumption
    Timeline --> Consumption & Gaps & EnteredBy & Completeness
    Export --> EnteredBy
    Logbook --> EnteredBy & Periods & Completeness & Jurisdictions
    Claim --> Completeness & Jurisdictions
    Emission & Preferences & Completeness --> Jurisdictions
```

#### Reminders and notices

`ReminderEngine` is pure: it computes a reminder's state from the reminder, the day and the
counter, and stores nothing. The `…Notices` classes write and withdraw Nextcloud notifications;
`Notifier` words them when they are read, telling them apart by each class's `OBJECT` constant (the
dashed arrows). Drawn from [the reminder engine](../architecture.md#reminder-engine).

```mermaid
flowchart LR
    Job["ReminderJob"]
    Notifier["Notifier"]
    Reminder["ReminderService"]
    Recipient["RecipientService"]
    Grant["GrantService"]
    Booking["BookingService"]
    Vehicle["VehicleService"]
    Notification["NotificationService"]
    Mail["MailService"]
    Engine["ReminderEngine"]
    GrantNotices["GrantNotices"]
    BookingNotices["BookingNotices"]
    Lookalike["LookalikeNotices"]
    Sharable["Sharable"]
    Zone["UserZone"]
    Access["VehicleAccess"]
    Job --> Notification & Mail
    Reminder & Recipient & Grant & Vehicle --> Notification
    Notification & Mail & Reminder --> Engine
    Mail --> Zone
    Grant & Vehicle --> GrantNotices
    Booking & Vehicle --> BookingNotices
    Recipient & Grant --> Sharable
    Mail & Notifier --> Access
    Notifier -.->|OBJECT| GrantNotices & BookingNotices & Lookalike
```

#### Accounts, checks and sync

What runs for an account rather than a vehicle: erasing and transferring, the admin's check, and
the client's sync. `Pending` records work a failure cut short, for `PendingJob` to finish. Drawn
from [data model](../architecture.md#data-model), [sync](../api.md#sync) and
[ADR 0008](../adr/0008-erasing-a-driver-pseudonymises.md).

```mermaid
flowchart LR
    Erasure["ErasureService"]
    Transfer["TransferService"]
    Check["CheckService"]
    Sync["SyncService"]
    PendingJob["PendingJob"]
    Grant["GrantService"]
    GrantNotices["GrantNotices"]
    Pending["Pending"]
    Epoch["SyncEpoch"]
    Vehicle["VehicleService"]
    Odometer["OdometerService"]
    Booking["BookingService"]
    Document["DocumentService"]
    Reminder["ReminderService"]
    Erasure --> Grant & GrantNotices & Pending & Epoch
    Erasure -->|"withdraw()"| Vehicle
    Transfer --> GrantNotices & Epoch
    Check --> Erasure & Odometer & Pending
    Grant --> Pending
    PendingJob --> Erasure & Grant
    Sync -->|"list()"| Vehicle
    Sync --> Grant & Booking & Document & Reminder
```

`SyncService` reads the epoch `SyncEpoch` writes from the app config, not through the class.

## Frontend

Vue 3 with Pinia, two bundles ([stack and layers](../architecture.md#stack-and-layers)). The screens
are described in [screens](../ui.md#screens).

### Pages and screens

`main.js` mounts the fleet on the element `templates/main.php` renders; `settings.js` mounts the
settings block on `templates/personal.php`'s. `App.vue` picks the view from the URL and the
navigation.

```mermaid
flowchart TB
    subgraph mainBundle["«artifact» nextfleet-main"]
        main["main.js"]
        boot["boot.js"]
        App["App.vue"]
        List["VehicleList"]
        Sheet["VehicleSheet"]
        Undo["UndoToast"]
        Overview["OverviewView"]
        VehicleView["VehicleView"]
        Costs["CostsView"]
        Inbox["InboxView"]
        Reports["ReportsView"]
    end
    subgraph settingsBundle["«artifact» nextfleet-settings"]
        settings["settings.js"]
        SettingsView["SettingsView"]
    end
    main -->|"import()"| boot --> App
    App --> List & Sheet & Undo
    App --> Overview & VehicleView & Costs & Inbox & Reports
    VehicleView --> Sheet
    settings --> SettingsView
```

### The vehicle screen

```mermaid
flowchart LR
    VehicleView["VehicleView"]
    Sticker["VehicleSticker"]
    Kpi["KpiHeader"]
    Tile["KpiTile"]
    Due["DueBanner"]
    ReminderSheet["ReminderSheet"]
    Bookings["VehicleBookings"]
    BookingSheet["BookingSheet"]
    Handover["HandoverSheet"]
    Documents["VehicleDocuments"]
    Timeline["Timeline"]
    Row["TimelineRow"]
    Entry["EntrySheet"]
    ImportSheet["ImportSheet"]
    Leave["LeaveVehicle"]
    Inspection["InspectionSticker"]
    VehicleView --> Sticker & Kpi & Due & Bookings & Documents & Timeline & Entry & ImportSheet & Leave
    Kpi --> Tile
    Due --> ReminderSheet & Inspection
    Bookings --> BookingSheet & Handover
    Timeline --> Row
```

### The other screens

```mermaid
flowchart LR
    Overview["OverviewView"]
    Hint["CompleteHint"]
    Inbox["InboxView"]
    InboxSheet["InboxSheet"]
    Entry["EntrySheet"]
    Costs["CostsView"]
    Tile["KpiTile"]
    Sheet["VehicleSheet"]
    Recipients["ReminderRecipients"]
    Grants["VehicleGrants"]
    Inspection["InspectionSticker"]
    Overview --> Hint
    Inbox --> InboxSheet & Entry
    Costs --> Tile
    Sheet --> Recipients & Grants & Inspection
```

### State and the server

Three stores hold what more than one screen reads. Views and components call `services/api.js`
themselves for the rest; nothing else in `src/` talks to the server. `src/utils/` is plain
functions, left out here.

```mermaid
flowchart LR
    Screens["«component»<br/>App.vue, src/views, src/components"]
    Vehicles["useVehiclesStore<br/>store/index.js"]
    InboxStore["useInboxStore<br/>store/inbox.js"]
    Prefs["usePreferencesStore<br/>store/preferences.js"]
    api["«component»<br/>services/api.js"]
    internal["/apps/nextfleet/api/…<br/>internal routes"]
    pages["/apps/nextfleet/vehicles/…<br/>printed pages, CSV, documents"]
    core["/ocs/v2.php/core/autocomplete/get"]
    preview["/core/preview<br/>inbox thumbnails"]
    Screens --> Vehicles & InboxStore & Prefs
    Screens --> api
    Vehicles --> InboxStore
    Vehicles & InboxStore & Prefs --> api
    api -->|fetch, requesttoken| internal
    api -->|URL to open or fetch| pages
    api -->|fetch| core
    api -->|URL to show| preview
```

## A request

Two doors, one rule ([one rule, two doors](../api.md#one-rule-two-doors)): the browser and a
client reach the same service call through twin controllers. Logging a trip, for example; the
answer travels back the same way, a refusal turned into its status by `EntryAnswers` or
`OcsAnswers`.

```mermaid
flowchart TB
    Browser["«actor»<br/>browser"]
    Client["«actor»<br/>client"]
    Routes["appinfo/routes.php"]
    Placeholder["PlaceholderMiddleware"]
    Web["TripController<br/>EntryAnswers"]
    OcsTrip["Ocs\TripController<br/>OcsAnswers"]
    Trip["TripService::record()"]
    Vehicle["VehicleService::reach()"]
    Access["VehicleAccess::operations()"]
    Odometer["OdometerService::fromTrip()"]
    TripMapper["TripMapper"]
    AccessMapper["AccessMapper"]
    db[("database")]
    Browser -->|"POST /apps/nextfleet/api/vehicles/{uuid}/trips"| Routes
    Client -->|"POST /ocs/v2.php/apps/nextfleet/api/v1/vehicles/{uuid}/trips"| Routes
    Routes --> Placeholder
    Placeholder -->|routes| Web
    Placeholder -->|ocs| OcsTrip
    Web & OcsTrip --> Trip
    Trip -->|log| Vehicle --> Access --> AccessMapper --> db
    Trip --> TripMapper --> db
    Trip --> Odometer
```
