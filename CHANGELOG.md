# Changelog

## 0.3.0 — not released

Share a car with the people who drive it, book it and hand it over, file receipts from a folder,
and bring your history along from another app.

### Sharing a vehicle

- The owner gives a user or a group access to a vehicle as viewer, driver or manager, in the
  *Access* section of the Edit vehicle sheet, and changes the role or takes it back there.
- A viewer reads. A driver also adds trips, fill-ups, readings, maintenance and expenses, and
  changes or deletes the ones they entered. A manager changes everything else too: anybody's
  entries, the vehicle, its reminders and its papers. Only the owner grants access and deletes the
  vehicle. Each screen offers only what the role allows.
- Granting follows the admin's sharing settings. With the Share API off, or the owner's groups
  excluded from sharing, the owner grants nobody. Under "Restrict users to only share with users in
  their groups" the owner grants only people who share a group with them, and only their own
  groups. With group sharing off, no group can be granted.
- The person granted is told in-app: "Anna gave you access to NF-DE 100 as a driver". Taking the
  access back withdraws that notice and takes them off the vehicle's reminders. A role change, or
  joining the group later, tells nobody. Nothing is mailed.
- A shared vehicle sorts into the overview by urgency like your own, and names its owner: "Owned
  by Anna Adler". Someone granted in person can leave it from its screen. Someone who reaches it
  through a group is told which group.
- Once anybody else has access, each timeline row says who entered it, and the logbook gains an
  "Entered by" column (*Eingetragen von* in the Fahrtenbuch). The mileage claim lists only the
  business trips its reader entered, and says so.
- Deleting a group takes back its access, so a group made later under the same name reaches
  nothing. Deleting an account pseudonymises its bookings too; the handover notes stay, as trips do.

### Bookings and handover

- *Book* on the vehicle screen reserves the car for a time and a purpose. A booking over another
  one is refused, naming whose it is and when. A driver books, changes and cancels their own; a
  manager or the owner anybody's, and the booker is told when somebody else cancels.
- *Take the car* and *Return the car* record the counter, the tank or battery level and a note as
  the car changes hands, and handover photos attach to the booking. A car still out with somebody
  else cannot be taken. A counter that runs backwards, or a car returned late, is flagged, never
  refused. A car nobody holds right now offers *Take it now*.
- Returning the car opens the trip, filled in from the handover; only the category is left to
  choose. A returned booking without its trip offers *Log the trip*.
- The overview and the vehicle header say who has the car: "With Anna until Fri 02/10, 18:00",
  "With you", or overdue. The header also names your own next booking within seven days.
- A laid-up or sold car cannot be booked.

### Receipts and documents

- The receipt inbox. Choose a folder of your own Files as *Inbox folder* in the personal settings,
  and point the Nextcloud mobile app's auto-upload at it. *Inbox* then shows its images and PDFs
  that are not attached yet. Two taps attach one to an entry, or it starts a new fill-up,
  maintenance record or expense dated when the file was saved. Nothing is moved or renamed.
- A driver attaches receipts to the entries they entered, and photos to their own bookings. The
  registration and the insurance papers stay a manager's.
- *Remove* on a document can be undone from the toast, as every other delete can.
- *Belongs to* searches every fill-up, maintenance record, expense and booking of the vehicle by a
  word of its title or purpose, or by its date, not only the newest. Dates there carry the year.
- Tapping a document saves its file. When the file is gone or still being written, the screen says
  so.
- Papers, the inbox and the import take only files from your own home storage, never from a group
  folder or an external storage. A vehicle would otherwise serve such a file past that folder's
  own sharing rules.

### Importing your history

- *Import from a file…* in the vehicle sheet reads a CSV (LubeLogger format) or a CSV (Spritmonitor
  format) file from your own Files: fill-ups, maintenance, expenses and odometer readings, never
  trips. Pick the file, its format and its units. The preview shows each row as new, duplicate or
  unreadable with the reason, and asks what the file leaves open. The import then writes all of it
  or nothing, and the undo toast takes the whole import back.
- LubeLogger files are read as an English or a German LubeLogger server writes them, with their
  dates, currency signs and thousands marks. A negative cost, or a currency sign that is not the
  vehicle's, makes the row unreadable.
- Spritmonitor files are read with English or German headers, and the fuel and the cost type as
  codes. Each cost type has a default meaning, which the preview shows and you may change. A
  purchase price, a refund, AdBlue and hydrogen are never imported.
- A file over 5 000 000 bytes or 20 000 rows, with a line over 64 KiB, or in an encoding other than
  UTF-8 and Windows-1252 is refused with the reason.

### Smaller changes

- The dashboard widget lists only the reminders that are overdue, due or coming up. A planned or
  snoozed one waits on the vehicle screen.
- The entry sheet asks for a cost's VAT rate and the reminder it closes as soon as it opens, not
  only after the date or the kind changes.
- The CO₂ estimate names the year of its fuel factors' source, as it names the grid factor's.
  Germany's are from 2022.

### For apps, scripts and admins

- An OCS API under `/ocs/v2.php/apps/nextfleet/api/v1` for apps such as a phone client. It signs in
  with a Nextcloud app password, which a client gets through Login Flow v2. It does what the web UI
  does, and a sync endpoint hands a client what changed since its last call. v1 only grows: a
  change that would break a client fails the tests. `openapi.json` ships with the app, where
  Nextcloud's OCS API viewer finds it. All of it is in `docs/api.md`.
- `occ nextfleet:import <uid> <vehicle-uuid> <path>` runs the import for scripts, as that user and
  through the same checks. `--dry-run` prints the preview.
- `occ nextfleet:seed <user> --grant-to <uid>` shares the demo Passat with that account as a
  driver, with a trip of theirs and a booking tomorrow.
- The CSV export, each sync page and each import write one `info` line to the Nextcloud log: who, which
  vehicle, what and how many rows. Set `loglevel` to 1 to see them
  ([what is logged](docs/security.md#what-is-logged)).
- `occ upgrade` from 0.2.0 runs one migration, a table for bookings and an index, and keeps every
  row.
  `tools/upgrade-check.sh` checks this on NC 31 and NC 34 before a release.

## 0.2.0 — not released

v1 by scope, 0.x because it is new ([plan](plan.md#milestones)). For Nextcloud 31 to 34, in
English and German, formal and informal.

### Vehicles and the odometer

- A vehicle is a car, van, truck, trailer, tractor or generator, with its plate, the energy it
  takes, its country and its lifecycle: in service, laid up (reminders pause) or disposed
  (reminders stop, the records stay). Its country sets the logbook rules, the VAT rates and the
  inspection. A new vehicle takes the country from your personal settings.
- The odometer is a history of readings, not a running total. A trip entered by its distance
  derives the reading. A counter read off the dashboard wins over a derived one, and a reading it
  contradicts is flagged, never rewritten. A reading lower than the one before is flagged, not
  refused.
- A vehicle can count engine hours instead of kilometres, as a tractor or a generator starts out
  doing, or both, each on its own.
- Two people saving the same thing at once cannot overwrite each other unseen: the later save is
  told so. The entry sheet then offers *Save anyway*, which writes what is on its screen.
- Deleting a vehicle, an entry or a reminder can be undone from the toast.

### Trips and the logbook

- The entry sheet takes a trip with the counter it ended on, or with the kilometres it covered,
  whichever you know, and a category: business, private or commute. The start, the destination,
  the purpose and the partner complete from the vehicle's earlier trips.
- The vehicle screen's timeline lists every entry, newest first and by month, with chips to narrow
  it to one kind. A tap opens the entry to change or delete it.
- Logbook Mode, per vehicle. Trips become append-only: a delete voids the trip, which stays in the
  export, and every write leaves an audit row. Switching it on locks nothing that came before. A
  trip that lacks what the country requires is saved, flagged and names what is missing.
- Gaps. Under Logbook Mode the timeline shows the kilometres no trip accounts for, per month, and
  closes them one at a time with a private trip you confirm.
- The Fahrtenbuch for a German vehicle: one year as a page the browser prints, with voided trips,
  late edits and what they changed, and the periods the mode was on. A vehicle under the generic
  country gets a plain logbook in your language. *Reports* in the navigation opens both.
- The mileage claim: a year's business trips at the statutory rate of each trip's day, in Germany
  0,30 € per km by car, van or truck since 2014, citing §9 EStG.

### Fill-ups, maintenance and expenses

- Fill-ups and charging sessions, typed as the pump shows them, with either decimal mark. The
  station and the price it last charged are prefilled. A plug-in hybrid takes petrol and
  electricity; a charge says home or public.
- Maintenance records (service, repair, inspection, tyres, upgrade) and expenses (insurance,
  vehicle tax, toll, parking, fine, lease, other).
- VAT is prefilled with the country's rate on the day: in Germany 19 %, and 16 % in the second half
  of 2020. It stays empty for insurance, vehicle tax and fines, which carry none. *I reclaim VAT*
  in the personal settings makes the figures net.

### Figures and costs

- Consumption from full tank to full tank, per energy. A plug-in hybrid shows two figures, and an
  approximate wall-side kWh/100 km. A fill-up row states the figure of the stretch it closes.
- The vehicle header: odometer, consumption, cost per 100 km, energy-only cost and the total cost
  of ownership, for a period you pick, each compared with the period before.
- The Costs screen: one year as a bar per month, stacked in energy, maintenance and expenses, and
  the expenses by category beneath. A month with no rows says "No entries".
- A CO₂ estimate on the Costs screen from the year's fill-ups, linked to its sources. Charging uses
  the German grid average (344 g/kWh, 2025) unless your personal settings give your own. None under
  the generic country.
- CSV export of the year from the Costs screen, as four files: trips, fill-ups, maintenance and
  expenses. Voided trips are marked, and a cell that would run as a formula starts with `'`.

### Reminders

- Reminders for HU/AU, oil change, brake fluid, tyre swap or a title of your own, by date, by km or
  by whichever comes first, with warning points. A recurring one moves on from the work actually
  done. A reminder by km shows an estimated date once there is enough driving to go on.
- HU/AU from the sticker: the due banner asks for the month and year on it. Germany repeats it
  every 24 months, every 12 for a truck or a tractor.
- The due banner on the vehicle screen lists its reminders, most urgent first. *Done* opens a
  maintenance record that closes the reminder. A reminder can be snoozed to a day, or one
  occurrence skipped.
- A Nextcloud notification at each warning point, when due and when overdue, to each of the
  vehicle's recipients, in their language. A mail digest daily, weekly on Monday or monthly on the
  1st, from 07:00 in their time zone. A laid-up vehicle sends nothing. No calendar events: Nextcloud
  offers no way to change or remove one once the reminder is done.
- The overview sorts vehicles by urgency, each with a traffic light, its km and its most urgent
  reminder. The dashboard widget *Vehicle reminders* lists them across every vehicle.

### Documents and more

- Papers from Files: attach the registration, the insurance or an invoice from your own Files to
  the vehicle, or to one of its entries. Everyone who may see the vehicle opens it. A linked paper
  shows as a paperclip on its timeline row.
- A QR sticker per vehicle, for the glovebox, opens the entry sheet on that car.
- Nextcloud's unified search finds a vehicle by its plate, with or without spaces and hyphens, its
  manufacturer or its model.
- `occ nextfleet:seed <user>` writes a demo fleet to try it on.

### Your data

- Deleting a Nextcloud account pseudonymises every row that names it and deletes none
  ([ADR 0008](docs/adr/0008-erasing-a-driver-pseudonymises.md)). A new account under the same name
  inherits nothing.
- Deleting a vehicle is a soft delete, and takes back the notifications its reminders sent. Nothing
  purges a vehicle's rows yet.
