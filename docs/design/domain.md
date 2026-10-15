# Domain

The classes the rules are written against, and how they relate.
One diagram per area.
A class shows the attributes that identify it or that a rule reads.
[The data model](data-model.md) draws every column.
[The index](README.md) explains the notation.

Every entity extends `BaseEntity`, so it also has `uuid`, `created_at`, `updated_at`, `deleted_at` and `created_by`.
The diagrams leave those out.

## Vehicle and its entries

A vehicle's counters are chains of `OdoReading`s.
Each Entry with a counter writes its Reading, and the vehicle caches the newest value ([odometer rules](../architecture.md#odometer-rules)).
An Odometer Entry is a Reading with `source_type` `manual`.
The audit trail points at a trip or a vehicle by `entity` and `entity_id`, not by a key.

```mermaid
classDiagram
    class Vehicle {
        user_id
        plate
        display_name
        vehicle_type
        eu_category
        body_type
        energy_types
        odo_unit
        odo_value
        second_unit
        second_value
        currency
        jurisdiction
        logbook_mode
        lifecycle
    }
    class OdoReading {
        read_at
        value
        counter
        kind
        origin
        flagged
        source_type
        source_id
    }
    class Trip {
        started_at
        ended_at
        start_odo
        end_odo
        distance
        category
        reconciled
    }
    class Energy {
        filled_at
        energy
        amount
        total
        vat_rate
        full_tank
        missed_previous
    }
    class Maintenance {
        done_at
        type
        cost
        vat_rate
        reminder_id
    }
    class Expense {
        spent_at
        category
        amount
        vat_rate
    }
    class Audit {
        entity
        entity_id
        diff_json
    }
    Vehicle "1" --> "*" OdoReading : counters
    Vehicle "1" --> "*" Trip
    Vehicle "1" --> "*" Energy
    Vehicle "1" --> "*" Maintenance
    Vehicle "1" --> "*" Expense
    Trip "1" --> "1" OdoReading : writes
    Energy "1" --> "0..2" OdoReading : writes one per counter
    Maintenance "1" --> "0..2" OdoReading : writes one per counter
    Vehicle "1" --> "*" Audit : mode, plate, jurisdiction, currency, type, owner
    Trip "1" --> "*" Audit : revisions under Logbook Mode
```

## Reminders

A reminder comes from a `ReminderTemplate` or has its own title.
`ReminderEngine` is static: it reads a reminder and the counter and answers the state and the newest warning point, and moves an occurrence on or back ([the reminder engine](../architecture.md#reminder-engine), [lifecycles](lifecycles.md#a-reminder)).
A `ReminderReceipt` records each point sent.
The recipients belong to the vehicle, not to a reminder.

```mermaid
classDiagram
    class Reminder {
        template_key
        title
        mode
        due_date
        due_odo
        lead_odo
        recur_months
        recur_odo
        state
        snoozed_until
        occurrence
    }
    class ReminderReceipt {
        occurrence
        point
        channel
        user_id
        sent_at
    }
    class ReminderRecipient {
        user_id
    }
    class ReminderTemplate {
        key
        mode
        recurMonths
        recurOdo
        leadOdo
    }
    class IServiceTemplates {
        <<interface>>
        all()
    }
    class IInspectionScheme {
        <<interface>>
        template(vehicleType)
        firstDueMonths(vehicleType)
    }
    class ReminderEngine {
        evaluate(reminder, today, odo)$
        advance(reminder, fromDay, fromOdo)$
        retreat(reminder, fromDay, fromOdo)$
        estimate(reminder, chain, now)$
    }
    Vehicle "1" --> "*" Reminder
    Vehicle "1" --> "*" ReminderRecipient : tells
    Reminder "1" --> "*" ReminderReceipt : points sent
    Maintenance "*" --> "0..1" Reminder : closes
    Reminder ..> ReminderTemplate : template_key
    IServiceTemplates --> "*" ReminderTemplate
    IInspectionScheme --> ReminderTemplate
    ReminderEngine ..> Reminder : reads, advances, retreats
```

## Access and sharing

The vehicle's `user_id` is its owner.
Everyone else reaches it through an `Access` grant with a role.
`VehicleAccess` alone turns the two into the five operations ([access](../architecture.md#access), [authorization](../security.md#authorization)).
`GrantService` grants only whom `Sharable` allows.
`TransferService` hands a vehicle to a new owner.

```mermaid
classDiagram
    class Access {
        grantee
        grantee_type
        role
    }
    class VehicleAccess {
        may(userId, operation, vehicle)
        mayChange(userId, operation, vehicle, createdBy)
        mayBooking(userId, vehicle, bookerId)
        reachable(userId)
    }
    class Sharable {
        reaches(userId, name, type)
    }
    class GrantService
    class GrantNotices
    class TransferService
    class ReminderRecipient {
        user_id
    }
    Vehicle "1" --> "*" Access : one live grant per grantee
    Vehicle "1" --> "*" ReminderRecipient
    VehicleAccess ..> Vehicle : owner
    VehicleAccess ..> Access : role
    GrantService --> Access : grants, re-roles, revokes
    GrantService ..> Sharable : may the owner reach the grantee
    GrantService ..> GrantNotices : tells the grantee
    GrantService ..> ReminderRecipient : prunes who lose view
    TransferService --> Vehicle : new user_id
    TransferService --> Access : former owner as viewer, new owner's own grant revoked
    TransferService --> ReminderRecipient : new owner
```

## Bookings, handover and trips

A `Booking` is a plan, not an Entry, and writes no Reading.
The handover is its `out_*` and `in_*` columns.
The driver logs the trip from a returned booking, and `trip_id` ties the two ([bookings](../architecture.md#bookings), [lifecycles](lifecycles.md#a-booking)).

```mermaid
classDiagram
    class Booking {
        user_id
        starts_at
        ends_at
        purpose
        state
        out_at
        out_odo
        out_level
        in_at
        in_odo
        in_level
        trip_id
    }
    class Trip {
        started_at
        ended_at
        start_odo
        end_odo
        category
    }
    class BookingService
    Vehicle "1" --> "*" Booking
    Booking "0..1" --> "0..1" Trip : becomes
    Trip "1" --> "1" OdoReading : writes
    Booking "1" --> "*" Document : handover photos
    BookingService --> Booking
    BookingService ..> VehicleAccess : mayBooking
```

## Documents and the inbox

A `Document` points at a Nextcloud file by `file_id`.
It hangs on the vehicle, or on one fill-up, maintenance record, expense or booking ([documents](../architecture.md#documents)).
`InboxService` lists the inbox files that no live Document references yet ([the inbox](../architecture.md#the-inbox)).

```mermaid
classDiagram
    class Document {
        file_id
        kind
        linked_type
        linked_id
    }
    class DocumentService
    class InboxService
    class OwnFiles
    class PreferencesService
    class VehicleAccess
    Vehicle "1" --> "*" Document : papers
    Document "*" --> "0..1" Energy : linked
    Document "*" --> "0..1" Maintenance : linked
    Document "*" --> "0..1" Expense : linked
    Document "*" --> "0..1" Booking : linked
    DocumentService --> Document : attaches, detaches, restores
    DocumentService ..> OwnFiles : find
    InboxService ..> PreferencesService : inbox_folder
    InboxService ..> VehicleAccess : reachable
    InboxService ..> Document : leaves out attached files
    InboxService ..> OwnFiles : owns
```

## Import

An importer reads one tool's CSV export and proposes entries.
It writes nothing.
`ImportService` reads the file through `CsvReader`, marks `Duplicates`, and writes what the user agreed to through each entry service's `add()` ([import](../architecture.md#import)).

```mermaid
classDiagram
    class ImportService
    class Importers {
        get(key)
    }
    class IImporter {
        <<interface>>
        key()
        recordTypes()
        questions(recordType)
        propose(recordType, header, rows, answers)
    }
    class LubeLoggerImporter
    class SpritmonitorImporter
    class CsvReader {
        header
        separator
        rows()
    }
    class Cells
    class Values
    class Proposals
    class Proposal {
        kind
        fields
        row
        outcome
        reason
        column
    }
    class Duplicates
    ImportService --> Importers
    Importers "1" --> "*" IImporter
    IImporter <|.. LubeLoggerImporter
    IImporter <|.. SpritmonitorImporter
    ImportService ..> CsvReader : rows
    IImporter ..> Proposals : proposes
    Proposals "1" *-- "*" Proposal
    LubeLoggerImporter ..> Cells
    SpritmonitorImporter ..> Cells
    Cells ..> Values
    ImportService ..> Duplicates : marks
```

## Jurisdiction

A vehicle names its jurisdiction by key.
`Jurisdictions` resolves it, and falls back to `generic` for a key it does not know.
A part a jurisdiction lacks is null, and a report that needs it is unavailable ([contributing](../contributing.md#still-one-directory-per-country)).

```mermaid
classDiagram
    class Jurisdictions {
        get(key)
    }
    class IJurisdiction {
        <<interface>>
        key()
        odoUnit()
        currency()
    }
    class ILogbookRules {
        <<interface>>
        mandatoryFields(category)
        lockDelayDays()
    }
    class IRateProvider {
        <<interface>>
        vatRateAt(when)
        emissionFactorAt(energy, when)
        mileageRateAt(vehicleType, when)
    }
    class IReportRenderer {
        <<interface>>
        render(report)
    }
    class IClaimRenderer {
        <<interface>>
        render(claim)
    }
    class IInspectionScheme {
        <<interface>>
    }
    class FahrtenbuchRenderer
    class LogbookRenderer
    class MileageClaimRenderer
    Vehicle ..> Jurisdictions : jurisdiction
    Jurisdictions "1" --> "*" IJurisdiction
    IJurisdiction --> "0..1" ILogbookRules
    IJurisdiction --> "0..1" IRateProvider
    IJurisdiction --> "0..1" IReportRenderer : logbook
    IJurisdiction --> "0..1" IClaimRenderer : mileage claim
    IJurisdiction --> "0..1" IInspectionScheme
    IReportRenderer <|.. FahrtenbuchRenderer
    IReportRenderer <|.. LogbookRenderer
    IClaimRenderer <|.. MileageClaimRenderer
```

Next: [Data model](data-model.md)
