# NextFleet

A Nextcloud app for keeping a vehicle logbook. The glossary below is the language the code, the UI
and the docs all use. It holds terms only; the design lives in [plan.md](plan.md) and
[docs/](docs/).

## Language

### Vehicle and its records

**Vehicle**:
A car, van, trailer, tractor or generator someone keeps records for. Identified by its `uuid`; the
plate is a mutable label.
_Avoid_: Car, asset

**Entry**:
Anything a person creates through the entry sheet: a Trip, an Energy Entry, a Maintenance Record or
an Odometer Entry.
_Avoid_: Record, item

**Odometer Entry**:
The one Entry that is its own Reading — a `fleet_odo_readings` row with `source_type: manual`,
carrying no content beyond the number. Every other Reading is written by an Entry that has content
of its own.

**Trip**:
One journey with a start and an end, categorised business, private or commute.
_Avoid_: Journey, drive, ride

**Energy Entry**:
One fill-up or one charging session. Table `fleet_energy`.
_Avoid_: Fuel entry, refuel, fill-up (as the type name), charge

**Maintenance Record**:
One piece of work done on a vehicle — service, repair, inspection, tyres or upgrade. Table
`fleet_maintenance`; "service" is one of its types, never the whole class.
_Avoid_: Service record, job, work order

**Expense**:
A money row that is neither energy nor maintenance: insurance, tax, toll, parking, fine, lease.
_Avoid_: Cost (see below)

**Cost**:
Any money figure, whatever its source. Energy, maintenance and expenses are all costs; only
`fleet_expenses` rows are Expenses.

**Document**:
A Nextcloud file linked to a vehicle or to one of its entries. Referenced by `file_id`; its path is
a label, exactly as a plate is.

**Engine**:
A vehicle's drivetrain classification — petrol, diesel, lpg, cng, electric, hybrid. For display,
filtering and emission defaults only.

**Energy Types**:
The set of energy a vehicle actually accepts. Authoritative: it decides which options the entry
sheet offers and which consumption figures exist. A plug-in hybrid is `hybrid` / `[petrol,
electric]`.

**Lifecycle**:
A vehicle is `active`, `laid_up` (seasonal or off the road — reminders pause) or `disposed` (sold or
scrapped — reminders stop, records stay).
_Avoid_: Active flag, archived, inactive

### Odometer

**Reading**:
One row in `fleet_odo_readings`: a vehicle's counter at a moment in time. Written by an Entry, never
edited directly. Apart from an Odometer Entry, a Reading is not an Entry.
_Avoid_: Mileage, km stand

**Observed Reading**:
A Reading whose value a person actually read off the counter.

**Derived Reading**:
A Reading computed rather than read — the one a distance-only Trip writes. An Observed Reading always
wins over a Derived one; contradicted Derived Readings are Flagged, never corrected silently.

**Value**:
What a Reading holds — kilometres or engine hours, per the vehicle's `odo_unit`. Never called "km".

**Segment**:
The span between two Observed Readings that consumption may be computed over. A Gap, a Flag or a
reset ends one.

**Gap**:
Kilometres between one Trip's end and the next one's start that no record accounts for. Closed one
at a time by a Reconciliation Trip, never in bulk.

**Reconciliation Trip**:
A private Trip created to close a Gap. Marked in the audit trail as derived rather than observed.

**Flag**:
A "check this" marker on a record that is implausible but saved anyway. Never a rejection.
_Avoid_: Error, warning, validation failure

**Voided**:
A record deleted under Logbook Mode: soft-deleted, kept, audited, and listed as voided in the
export. Only outside Logbook Mode does a delete eventually disappear.

### Reminders

**Reminder**:
One thing that will come due for one vehicle, by date, by odometer, or by whichever comes first.
_Avoid_: Due item, task, alert

**Reminder Template**:
A seeded, translatable definition a Reminder can be created from (oil change, HU/AU, tyre swap).
_Avoid_: Preset, rule

### Access

**Vehicle Access**:
Our own permission to see or change a vehicle: owner, or a grant with role manager, driver or
viewer. Decided in one place, `VehicleAccess::may`.
_Avoid_: Share, permission, ACL

**Share**:
Nextcloud's concept, never ours. Reserved for file shares and `OCP\Share`.

**Owner**:
The Nextcloud user a vehicle belongs to. Distinct from a Driver, who may be neither owner nor
account holder.

### Jurisdiction

**Jurisdiction**:
The set of rules that apply to one vehicle: units, currency, logbook requirements, inspection
cadence, rates. One directory under `lib/Jurisdiction/`. Per vehicle, not per instance.
_Avoid_: Country, locale, region, profile

**Generic Jurisdiction**:
The fallback: metric units, the instance's currency, no logbook ruleset, no inspection scheme, no
rates. A report that needs a rate is unavailable under it, never zero.

**Logbook Mode**:
The per-vehicle switch that makes a vehicle's trips append-only and auditable, under its
jurisdiction's ruleset.
_Avoid_: Fahrtenbuch mode (in code), strict mode, compliance mode

### Money and time

**Occurred At**:
When the thing happened, in the user's hands and editable. Stored as a UTC instant plus the
originating UTC offset, because a logbook is judged on local calendar dates.

**Created At**:
When the server received the record. Set from `ITimeFactory`, never accepted from a client. The gap
between the two is what timeliness means.

**Partner**:
The business contact a Trip visited. Free text with autocomplete from the vehicle's history — not an
entity.
_Avoid_: Client, customer, contact
