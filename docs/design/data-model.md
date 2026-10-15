# Data model

Every `fleet_*` table with its columns, and how the rows point at each other, for someone who writes a migration or a query.
The rules and the meaning of each value are in [the data model](../architecture.md#data-model).
[The index](README.md) explains the notation.

Every table also has `id`, `uuid`, `created_at`, `updated_at`, `deleted_at` and `created_by`.
The diagrams leave those out.
Types are Doctrine's, a string with its length in characters.

The database has no foreign keys.
`FK` marks a column that holds another row's `id`, drawn as a solid line.
A dashed line points by a type column and an id, such as `source_type` and `source_id`.

## Vehicle and its entries

Each Entry with a counter writes one Reading per counter.
The vehicle caches the newest values in `odo_value` and `second_value`.
Each instant a user entered has its offset in a `_off` column ([time](../architecture.md#time)).

```mermaid
erDiagram
    fleet_vehicles ||--o{ fleet_odo_readings : "has"
    fleet_vehicles ||--o{ fleet_trips : "has"
    fleet_vehicles ||--o{ fleet_energy : "has"
    fleet_vehicles ||--o{ fleet_maintenance : "has"
    fleet_vehicles ||--o{ fleet_expenses : "has"
    fleet_trips |o..|| fleet_odo_readings : "writes"
    fleet_energy |o..o{ fleet_odo_readings : "writes, one per counter"
    fleet_maintenance |o..o{ fleet_odo_readings : "writes, one per counter"

    fleet_vehicles {
        string(64) user_id
        string(32) plate
        string(64) display_name
        string(64) manufacturer
        string(64) model
        string(32) vehicle_type
        string(8) eu_category
        string(4) body_type
        string(16) engine
        json energy_types
        integer tank_ml
        integer battery_wh
        date first_reg
        date disposed_at
        string(32) vin
        bigint odo_value
        string(8) odo_unit
        string(8) second_unit
        bigint second_value
        bigint purchase_price
        bigint residual_est
        string(3) currency
        string(8) jurisdiction
        boolean logbook_mode
        string(16) lifecycle
        bigint folder_file_id
        integer retention_months
        string(32) color
        text notes
        string(8) reminder_mail
    }
    fleet_odo_readings {
        bigint vehicle_id FK
        bigint read_at
        integer read_at_off
        bigint value
        string(8) counter
        string(16) kind
        string(16) origin
        boolean flagged
        string(16) source_type
        bigint source_id
    }
    fleet_trips {
        bigint vehicle_id FK
        bigint started_at
        integer started_at_off
        bigint ended_at
        integer ended_at_off
        bigint start_odo
        bigint end_odo
        bigint distance
        string(255) from_label
        string(255) to_label
        string(255) purpose
        string(255) partner
        string(16) category
        boolean reconciled
    }
    fleet_energy {
        bigint vehicle_id FK
        bigint filled_at
        integer filled_at_off
        bigint odo
        bigint second_odo
        string(16) energy
        bigint amount
        bigint unit_price
        bigint total
        integer vat_rate
        boolean full_tank
        boolean missed_previous
        string(255) station
        boolean is_dc
        string(16) location_kind
    }
    fleet_maintenance {
        bigint vehicle_id FK
        string(16) type
        bigint done_at
        integer done_at_off
        bigint odo
        bigint second_odo
        string(255) title
        string(255) vendor
        bigint cost
        integer vat_rate
        text notes
        bigint reminder_id FK
    }
    fleet_expenses {
        bigint vehicle_id FK
        bigint spent_at
        integer spent_at_off
        string(16) category
        bigint amount
        integer vat_rate
        text notes
    }
```

## The audit trail

A row about a trip or a vehicle, written with the change ([the audit trail](../architecture.md#the-audit-trail)).
It points by `entity` and `entity_id`, so it outlives the row it describes.

```mermaid
erDiagram
    fleet_trips |o..o{ fleet_audit : "revisions"
    fleet_vehicles |o..o{ fleet_audit : "flips and changes"

    fleet_audit {
        string(32) entity
        bigint entity_id
        json diff_json
    }
```

## Reminders

A maintenance record may close a reminder.
A receipt is one send: unique per occurrence, point, channel and recipient, so a point sends once.
Recipients belong to the vehicle.

```mermaid
erDiagram
    fleet_vehicles ||--o{ fleet_reminders : "has"
    fleet_vehicles ||--o{ fleet_reminder_recipients : "reminders go to"
    fleet_maintenance }o--o| fleet_reminders : "closes"
    fleet_reminders ||--o{ fleet_reminder_receipts : "sent as"

    fleet_reminders {
        bigint vehicle_id FK
        string(32) template_key
        string(255) title
        string(8) mode
        date due_date
        bigint due_odo
        bigint lead_odo
        boolean warn_month_before
        boolean warn_month_start
        boolean warn_due_date
        integer recur_months
        bigint recur_odo
        string(16) state
        date snoozed_until
        integer occurrence
    }
    fleet_reminder_receipts {
        bigint reminder_id FK
        integer occurrence
        string(16) point
        string(8) channel
        string(64) user_id
        bigint sent_at
    }
    fleet_reminder_recipients {
        bigint vehicle_id FK
        string(64) user_id
    }
```

## Access and bookings

A grant names a user or a group in `grantee`.
There is one live row per grantee, and a revoke soft-deletes it.
A booking's handover is its own columns, and `trip_id` points at the trip logged from it ([bookings](../architecture.md#bookings)).

```mermaid
erDiagram
    fleet_vehicles ||--o{ fleet_access : "access through"
    fleet_vehicles ||--o{ fleet_bookings : "booked through"
    fleet_bookings |o--o| fleet_trips : "becomes"

    fleet_access {
        bigint vehicle_id FK
        string(64) grantee
        string(8) grantee_type
        string(16) role
    }
    fleet_bookings {
        bigint vehicle_id FK
        string(64) user_id
        bigint starts_at
        integer starts_at_off
        bigint ends_at
        integer ends_at_off
        text purpose
        string(16) state
        bigint out_at
        integer out_at_off
        bigint out_odo
        integer out_level
        text out_notes
        bigint in_at
        integer in_at_off
        bigint in_odo
        integer in_level
        text in_notes
        bigint trip_id FK
    }
```

## Documents

A document points at its file by `file_id`, never by path ([documents](../architecture.md#documents)).

```mermaid
erDiagram
    fleet_vehicles ||--o{ fleet_documents : "papers"
    fleet_energy |o..o{ fleet_documents : "papers"
    fleet_maintenance |o..o{ fleet_documents : "papers"
    fleet_expenses |o..o{ fleet_documents : "papers"
    fleet_bookings |o..o{ fleet_documents : "papers"

    fleet_documents {
        bigint vehicle_id FK
        bigint file_id
        string(16) kind
        string(16) linked_type
        bigint linked_id
    }
```

Next: [Flows](flows.md)
