# NextFleet

A Nextcloud app for keeping a vehicle logbook. The glossary below is the language the code, the UI
and the docs all use. It holds terms only; the design lives in [plan.md](plan.md) and
[docs/](docs/).

## Language

### Vehicle and its records

**Vehicle**:
A car, motorcycle, van, truck, trailer, tractor or generator someone keeps records for. Identified
by its `uuid`; the plate is a mutable label.
_Avoid_: Car, asset

**Entry**:
Anything a person creates through the entry sheet: a Trip, an Energy Entry, a Maintenance Record, an
Odometer Entry or an Expense.
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
A Nextcloud file linked to a vehicle, to one of its entries, or to one of its bookings (a handover
photo). Referenced by `file_id`; its path is a label, exactly as a plate is.

**Inbox**:
A folder of a user's own Files, chosen by them, whose images and PDFs that no Document references
yet wait to be attached. The app only lists it; it never moves a file out of it.
_Avoid_: Upload folder, queue

**Import**:
Entries written at once from another tool's CSV export, a file from the user's own Files, after a
preview of what it would write. Energy Entries, Maintenance Records, Expenses and Odometer Entries;
never a Trip. Undone as a whole, or Entry by Entry.
_Avoid_: Upload, migration, sync

**Engine**:
A vehicle's drivetrain classification — petrol, diesel, lpg, cng, electric, hybrid. For display,
filtering only; the CO₂ Estimate reads each Energy Entry's energy instead.

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
What a Reading holds — kilometres or engine hours: `odo_unit` on the main counter, `second_unit` on
the second. Each counter is a chain of its own. Never called "km".

**Segment**:
The span between two Observed Readings that consumption may be computed over. A Gap, a Flag or a
Reset ends one.

**Reset**:
A Flagged Reading answered "the counter was replaced" (`kind = reset`): the Reading, not the swap
of the cluster behind it. It is never Flagged, and the counter's Segments start over at it; a
distance adds up the Segments on either side.

**Gap**:
Kilometres before a Trip that no Trip accounts for: its `start_odo` above the Reading the last Trip
before it left. A Reading with no journey around it proves the counter moved and accounts for no
kilometre. Closed one at a time by a Reconciliation Trip, never in bulk.

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
A translatable definition a Reminder can be created from (oil change, HU/AU, tyre swap): mode,
recurrence and lead, never a due date. Service templates are the same everywhere; the inspection
is the Jurisdiction's.
_Avoid_: Preset, rule

**Occurrence**:
One round of a recurring Reminder. Closing or dismissing it moves the same Reminder to the next;
deleting the Reminder ends them all.

**Warning Point**:
A moment a date Reminder tells its Recipients: a month before, at the start of the month it is due,
on the due date. Overdue always tells; an odometer Reminder has one point, `due_odo − lead_odo`.
_Avoid_: Lead days, alert

**Recipient**:
An account on a vehicle's list of whom its Reminders tell. Being on it grants no Vehicle Access.
_Avoid_: Subscriber, watcher

**Digest**:
The one mail a Recipient gets on a day, covering every vehicle on their list with news.

**Notification Receipt**:
The row saying a Warning Point was sent to one Recipient on one channel (`app` or `mail`). Table
`fleet_reminder_receipts`. Not a purchase receipt, which is a Document.

### Access

**Vehicle Access**:
Our own permission to see or change a vehicle: owner, or a grant with role manager, driver or
viewer. It comes as five operations: `view` (every role), `log` (driver and manager: add an Entry,
change one you entered), `edit` and `delete` (manager: the vehicle's settings and anybody's
Entries), and `own` (the owner alone: access, and whether the vehicle exists). Decided in one place,
`VehicleAccess::may`. A grant ends when the owner revokes it, its grantee Leaves, its group is
deleted, or the owner's account is deleted.
_Avoid_: Share, permission, ACL

**Leave**:
A grantee giving back the grant in their own name. A group's grant is the owner's to change, so
reaching a vehicle through a group there is nothing to leave.
_Avoid_: Unshare, opt out

**Share**:
Nextcloud's concept, never ours. Reserved for file shares and `OCP\Share`.

**Owner**:
The Nextcloud user a vehicle belongs to. Holds every operation without a grant, so is never a
grantee. Only an admin changes who it is, by a **Transfer** (`occ nextfleet:transfer`).

**Driver**:
The grant role between viewer and manager: `view` and `log`, so a driver adds Entries and changes
the ones they entered, and books the vehicle. Always an account, or a group of them.

### Pool

**Booking**:
One person's plan to use a vehicle from one instant to another, `booked`, `out`, `returned` or
`cancelled`. A plan, not an Entry: it writes no Reading and is on no timeline or report. Only a
`booked` or `out` one holds the vehicle.
_Avoid_: Reservation, appointment, event

**Handover**:
The two moments a Booking changes hands, Check-out and Check-in, each with the counter, the tank or
battery level and a note. Columns of the Booking, not rows of their own.

**Check-out**:
Taking the car: the booker's Booking goes from `booked` to `out`, never while another Booking of
the vehicle is `out`, nor early into another Booking's time. An early Check-out holds the car from
that moment, and an overdue one until the Check-in.
_Avoid_: Pick-up, start

**Check-in**:
Giving it back: `out` to `returned`. It offers the Trip, prefilled from the Handover; the Trip, not
the Check-in, is the record. The driver logs it, choosing the category; a Booking becomes at most
one Trip.
_Avoid_: Drop-off, return (as the type name)

### Jurisdiction

**Jurisdiction**:
The set of rules that apply to one vehicle: units, currency, logbook requirements, inspection
cadence, rates. One directory under `lib/Jurisdiction/`. Per vehicle, not per instance. The screen
says "Country" (de "Land"): that is the word a user picks it by.
_Avoid_ in code and docs: Country, locale, region, profile

**Generic Jurisdiction**:
The fallback: metric units, no currency, no logbook ruleset, no inspection scheme, no rates, and a
plain logbook. A vehicle under it states its own currency. A report that needs a rate is unavailable under it, never
zero.

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

**Mileage Claim**:
The business Trips one person entered on one vehicle in one year, each valued at its
jurisdiction's statutory rate per km on its own day. Commutes are not on it, nor anyone else's
Trips.
_Avoid_: Mileage report, Reisekosten (in code)

**CO₂ Estimate**:
A year's Energy Entries times their emission factor, electricity at a grid factor. Always an
estimate, and unavailable, not zero, where the jurisdiction sets no factor.
_Avoid_: Emissions, carbon footprint
