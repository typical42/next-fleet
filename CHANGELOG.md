# Changelog

## 0.4.1 — 2026-10-26

Supports Nextcloud 35. NextFleet now runs on Nextcloud 31 to 35.

Update NextFleet to 0.4.1 before you upgrade Nextcloud to 35. If you upgrade Nextcloud first while
the app store is off or unreachable, the upgrade disables NextFleet 0.4.0 and logs an error and a
warning about it; installing and enabling 0.4.1 afterwards brings it back with all its data. With
the app store reachable, Nextcloud may fetch 0.4.1 itself during the upgrade.

## 0.4.0 — 2026-10-15

A vehicle says what it is: a display name, and the EU type codes from its registration
certificate. A vehicle that counts kilometres and engine hours gets its figures per engine hour too.

### Vehicles

- A vehicle can carry a display name, such as "Blue van" or "Anna's car", up to 64 characters. It
  is a second label: the plate stays what mails, exports and the logbook name the vehicle by.
  Without a plate, the display name is what the vehicle is called, in reminder notifications too.
  Search finds it and shows it under the plate.
- A vehicle without a plate is named by its display name, else make and model, on its printed
  logbook and mileage claim and in the names of its CSV files. These left the plate blank before,
  and the files carried a long code.
- The navigation shows each vehicle on two lines: the plate in bold, and under it the display name,
  or else make and model. The overview and the vehicle page say the same under the plate.
- The edit sheet asks for the EU vehicle category from field J of the registration certificate,
  such as M1 or L3e-A2, with an off-road switch for M and N. The list is grouped, searchable by
  name or code (spaces, hyphens and dots aside), and puts what suits the vehicle type first.
  Choosing a category can set the vehicle type.
- The edit sheet also asks for the EU body type, field 4 on a German certificate, such as AC for a
  station wagon, with its two-digit add-on where there is one, such as BA03 for a lorry with a box
  body. It offers only what fits the category. Without a display name, the second label reads
  make, model and body type, such as "Volkswagen Passat · Station wagon".
- A help link under each code field leads to the user docs, which say where the codes are on the
  certificate and list every one of them.

### Figures and costs

- A vehicle that counts kilometres and engine hours shows each energy's consumption per engine hour,
  such as 4.5 l/h, next to its figure per 100 km. It counts the full tanks that both have engine
  hours. Its cost and energy cost per engine hour follow their per-100 km tiles, and a full
  fill-up's timeline row states both figures. The Costs screen stays per 100 km.
- A consumption tile that cannot have a figure yet says what it needs: "Needs two full fill-ups",
  or, beside a figure per 100 km, "Enter engine hours on full fill-ups".

### For apps, scripts and admins

- The OCS API carries the new fields, and v1 now promises them: a vehicle's `display_name`,
  `eu_category` and `body_type`, which create and update take too; in `…/kpis` and
  `…/costs/{year}`, `consumption_per_hour`, `measured` and `measured_per_hour`, and the cost's
  `hours`, `value_per_hour` and `energy_value_per_hour`; a timeline row's `consumption_per_hour`.
  An unknown code, or a body type that does not fit the category, is a 400.
- `occ nextfleet:vehicles` lists the display name, the EU category and the EU body type.
- From 0.3.1, `occ upgrade` adds the three vehicle columns, empty, and changes no row. After an
  update with `occ`, reload the web server or PHP-FPM, as the
  [administration guide](https://github.com/typical42/next-fleet/blob/main/docs/admin/README.md#upgrade-the-app)
  now says: until PHP's opcache lets go of the old code, reading a vehicle fails.

### Fixed

- On Oracle, a plate, name, label, title or other short text with letters such as ü or é could fail
  to save at its full length. It now saves up to the length the form allows, as on MariaDB and
  PostgreSQL. `occ upgrade` widens those columns on Oracle only, and changes no row.
- Two full tanks at the same odometer reading, such as a top-up at the same stop, dropped the
  second one's fuel from the consumption. It now counts towards the next full tank.

## 0.3.1 — 2026-10-07

The first release: a logbook for your vehicles, with their fill-ups, maintenance, costs and
reminders, and access for the people who drive them, book them and hand them over. For Nextcloud
31 to 34, in English and German, formal and informal. 0.x because it is new.

### Vehicles and the odometer

- A vehicle is a car, motorcycle, van, truck, trailer, tractor or generator, with its plate, the
  energy it takes, its country and its lifecycle: in service, laid up (reminders pause) or disposed
  (reminders stop, the records stay, and the vehicle moves to a *Disposed of* list under the
  overview, where it still opens and can be set back). Its country sets the logbook rules, the VAT
  rates and the inspection. A new vehicle takes the country from your personal settings. Its
  currency is a three-letter code such as EUR, and it stays once a cost is recorded in it. A change
  of the plate, the country, the currency or the type is kept on the vehicle's audit trail.
- A new vehicle takes the energy from its engine, so the first fill-up needs no trip to *Edit
  vehicle*. The overview asks once whether a German vehicle keeps a logbook for the tax office;
  Logbook mode stays off until you say yes. An empty timeline offers *New entry* and *Import from a
  file…*, and *Settings* in the navigation opens your personal settings.
- The odometer is a history of readings, not a running total. A trip entered by its distance
  derives the reading. A counter read off the dashboard wins over a derived one, and a reading it
  contradicts is flagged, never rewritten. A reading lower than the one before is flagged, not
  refused, and its timeline row asks whether the counter was replaced or it is a typo. A replaced
  counter starts a new segment: distances, costs per 100 km and TCO add up the kilometres on
  either side, and no consumption or Gap is measured across the swap. A month's distance counts
  from where the counter stood when the month began.
- A vehicle can count engine hours instead of kilometres, as a tractor or a generator starts out
  doing, or both, each on its own.
- Two people saving the same thing at once cannot overwrite each other unseen: the later save is
  told so. The entry sheet then offers *Save anyway*, which writes what is on its screen.
- A save tried again after a dropped connection does not write the entry twice: the entry sheet
  and the new-vehicle sheet name what they create before sending it.
- Deleting a vehicle, an entry, a reminder or a document can be undone from the toast. A save says
  "Saved."; an edited entry can be put back as it was from the same toast.
- With no signal, the entry sheet says so and keeps everything you typed for the next try.

### Trips and the logbook

- The entry sheet takes a trip with the counter it ended on, or with the kilometres it covered,
  whichever you know, and a category: business, private or commute. The start, the destination,
  the purpose and the partner complete from the vehicle's earlier trips. The category is the one
  you last used on the vehicle, else none; the departure is the last trip's arrival when that was
  today; and the counter the last trip ended at is offered under the start counter, taken with one
  tap. An arrival before the departure, a trip with neither end counter nor distance, and an end
  counter below the start are caught before sending; an arrival more than a day ahead is refused
  with words that say so.
- The vehicle screen's timeline lists every entry, newest first and by month, with chips to narrow
  it to one kind; *Expenses* is the chip for insurance, tax, tolls and the like. However far you
  scroll, it keeps four pages of rows on screen and brings the newer ones back on the way up. A
  tap opens the entry to change or delete it. Two trips whose times overlap are flagged, and a
  private trip that closed a Gap says when a trip entered later overtook it, with *Void trip* on
  its row.
- Logbook mode, per vehicle. Trips become append-only: a delete voids the trip, which stays in the
  export, and every write leaves an audit row, also after the mode is switched off for a trip
  that set off while it was on. Switching it on locks nothing that came before. A trip that lacks
  what the country requires is saved, flagged and names what is missing.
- Gaps. Under Logbook mode the timeline shows the kilometres no trip accounts for, per month, and
  closes them one at a time with a private trip you confirm.
- The Fahrtenbuch for a German vehicle: one year as a page the browser prints, with voided trips,
  late edits and what they changed, trips changed without an audit row, and the periods the mode
  was on with the plate each was kept under. Whether a change was late is judged when the page is
  printed, from when the trip ended or was entered. When a trip was entered, changed or voided
  prints in your time zone with its offset. The categories read *Geschäftlich*, *Privat* and
  *Wohnung – erste Tätigkeitsstätte*, as on the mileage claim. A vehicle under the generic
  country gets a plain logbook in your language. *Reports* in the navigation opens both, on last
  year until the end of February.
- The mileage claim: a year's business trips at the statutory rate of each trip's day, in Germany
  0,30 € per km by car, van or truck and 0,20 € by motorcycle since 2014, citing §9 EStG. A trip
  that lacks what the country requires, or that closed a Gap, is listed and marked but left out
  of the sum, and the page says why. It says, too, that the flat rate is only for vehicles that
  are not business assets.

### Fill-ups, maintenance and expenses

- Fill-ups and charging sessions, typed as the pump shows them, with either decimal mark, or as a
  receipt prints them (`1.234,56`). The counter comes right after the total, and counters open
  the number pad. The station and the price it last charged are prefilled. A plug-in hybrid takes
  petrol and electricity; a charge says home or public.
- Maintenance records (service, repair, inspection, tyres, upgrade) and expenses (insurance,
  vehicle tax, toll, parking, fine, lease, other). *Done* on a reminder opens a record with its
  title and type filled in.
- VAT is prefilled with the country's rate on the day: in Germany 19 %, and 16 % in the second half
  of 2020. It is 0 for insurance, vehicle tax and fines, which carry none. *I reclaim VAT*
  in the personal settings makes the figures net.

### Figures and costs

- Consumption from full tank to full tank, per energy. A plug-in hybrid shows two figures, and an
  approximate wall-side kWh/100 km. A fill-up row states the figure of the stretch it closes.
- The vehicle header: odometer, consumption, cost per 100 km, energy-only cost and the total cost
  of ownership, for a period you pick, each compared with the period before. Figures that could
  not be read offer *Try again*.
- The Costs screen: one year as a bar per month, stacked in energy, maintenance and expenses, and
  the expenses by category beneath. It opens as fast on a vehicle with years of history as on a
  new one. A month with no rows says "No entries".
- A CO₂ estimate on the Costs screen from the year's fill-ups, linked to its sources with their
  years: Germany's fuel factors are from 2022. Charging uses the German grid average (344 g/kWh,
  2025) unless your personal settings give your own. None under the generic country.
- CSV export of the year from the Costs screen, as four files: trips, fill-ups, maintenance and
  expenses. Voided trips are marked, each trip says when it was entered, every row on a shared
  vehicle says by whom, and a cell that would run as a formula starts with `'`.

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
  1st, from 07:00 in their time zone; news on a vehicle found after that day's mail was checked
  goes with the next one. A laid-up vehicle sends nothing. No calendar events: Nextcloud
  offers no way to change or remove one once the reminder is done.
- Editing a reminder's due date, due km or warnings takes back the notice it sent for what moved,
  and tells it again where it now falls, by notification and by mail. A notice whose reminder was
  done, dismissed, snoozed or deleted meanwhile is no longer shown.
- A notification or mail that fails to go out is tried again on the next hourly run, and takes
  nobody else's with it. A maintenance record refused on save takes back no notice.
- The overview sorts vehicles by urgency, each with a traffic light, its km and its most urgent
  reminder; on a phone the row wraps rather than cutting that reminder off. The dashboard widget
  *Vehicle reminders* lists the overdue, due and coming ones across every vehicle; a planned or
  snoozed one waits on the vehicle screen.

### Access to a vehicle

- The owner gives a user or a group access to a vehicle as viewer, driver or manager, in the
  *Access* section of the Edit vehicle sheet, and changes the role or takes it back there, after
  one question.
- A viewer reads. A driver also adds trips, fill-ups, readings, maintenance and expenses, and
  changes or deletes the ones they entered. A manager changes everything else too: anybody's
  entries, the vehicle, its reminders and its papers. Only the owner grants access and deletes the
  vehicle. Each screen offers only what the role allows.
- Granting follows the admin's sharing settings. With the Share API off, or the owner's groups
  excluded from sharing, the owner grants nobody. Under "Restrict users to only share with users in
  their groups" the owner grants only people who share a group with them, and only their own
  groups. With group sharing off, no group can be granted. The reminder recipients follow the same
  settings; anyone who already sees the vehicle, yourself included, can always be added.
- The person granted is told in-app: "Anna gave you access to NF-DE 100 as a driver"; a group's
  members are told on the next run of Nextcloud's background jobs. The notice goes when the access
  does, leaving the group included. Taking the access back, or taking them out of the group that
  gave it (on the next background run), also takes them off the vehicle's reminders and withdraws the
  reminder notices they were sent, as taking anyone off the list does; recipients who never
  had access, such as a bookkeeper, stay on the list. A role change, or joining the group after
  its members were told, tells nobody. Nothing is mailed.
- A vehicle someone gave you access to sorts into the overview by urgency like your own, and names
  its owner: "Owned by Anna Adler". Someone granted in person can leave it from its screen. Someone
  who reaches it through a group is told which group. Under *Who can see this vehicle* every
  grantee sees the owner and everybody else with access, by name and role.
- Once anybody else has access, each timeline row says who entered it, and the logbook gains an
  "Entered by" column (*Eingetragen von* in the Fahrtenbuch). The mileage claim lists only the
  business trips its reader entered, and says so.
- Deleting a group takes back its access, so a group made later under the same name reaches
  nothing. An account or a group deleted while it is being granted holds nothing either.

### Bookings and handover

- *Book* on the vehicle screen reserves the car for a time and a purpose. Bookings show on a
  vehicle anybody else has or had access to; alone with a car, there is nobody to book against. A
  booking over another one is refused, naming whose it is and when. A driver books, changes and cancels their own; a
  manager or the owner anybody's, and the booker is told when somebody else cancels: "Your booking
  of NF-DE 100 on 1 May 2036 at 09:00 was cancelled". The notice does not say who cancelled. A
  booking lasts at most 90 days, ends at most a year ahead and does not start in the past. The
  sheet says an end before the start, one gone by, or a span over these bounds before sending.
- *Take the car* and *Return the car* record the counter, the tank or battery level and a note as
  the car changes hands, and handover photos attach to the booking. A car still out with somebody
  else cannot be taken. Taken early, the car is gone from that moment; overdue, until it is back:
  nobody books those hours. A counter that runs backwards, or a car returned late, is flagged, never
  refused. *Take the car* starts from the counter the car last came back at when that is further
  than the vehicle's. A car nobody holds right now offers *Take it now*.
- Returning the car opens the trip, filled in from the handover; only the category is left to
  choose. A returned booking without its trip offers *Log the trip*. It stays listed, as does a car
  still out, however long ago its booking ended.
- The overview and the vehicle header say who has the car: "With Anna until Fri 02/10, 18:00",
  "With you", or overdue. The header also names your own next booking within seven days.
- A laid-up or sold car cannot be booked.

### Documents and receipts

- Papers from Files: attach the registration, the insurance or an invoice from your own Files to
  the vehicle, or to one of its entries or bookings. Everyone who may see the vehicle opens it. A
  linked paper shows as a paperclip on its row. The registration and the insurance papers are a
  manager's; a driver attaches receipts to the entries they entered, and photos to their own
  bookings.
- The receipt inbox. Choose a folder of your own Files as *Inbox folder* in the personal settings,
  and point the Nextcloud mobile app's auto-upload at it. *Inbox* then shows its images and PDFs
  that are not attached yet. Two taps attach one to an entry, or it starts a new fill-up,
  maintenance record or expense dated when the file was saved. Nothing is moved or renamed.
- *Belongs to* finds any fill-up, maintenance record, expense or booking of the vehicle by a word
  of its title or purpose, or by its date.
- Tapping a document saves its file, never opens it in the browser, whatever its type. When the
  file is gone or still being written, the screen says so.
- Papers, the inbox and the import take only files from your own home storage, never from a group
  folder or an external storage. A vehicle would otherwise serve such a file past that folder's
  own sharing rules. A paper whose file is later moved into a share, a group folder or an external
  storage stops opening, and says so, until the file is back in the Files of whoever attached it.
  The list may show a renamed or removed file's old name for five minutes; opening it is checked
  at once.
- A QR sticker per vehicle, for the glovebox, opens the entry sheet on that car.
- Nextcloud's unified search finds a vehicle by its plate, with or without spaces and hyphens, its
  manufacturer or its model.

### Importing your history

- *Import from a file…* in the vehicle sheet reads a CSV (LubeLogger format) or a CSV (Spritmonitor
  format) file from your own Files: fill-ups, maintenance, expenses and odometer readings, never
  trips. Pick the file, its format and its units. The preview shows each row as new, duplicate or
  unreadable with the reason, and asks what the file leaves open. The import then writes all of it
  or nothing, and the undo toast takes the whole import back. The same import sent again within
  the hour is answered, not imported twice, unless an entry it wrote was deleted since.
- LubeLogger files are read as an English or a German LubeLogger server writes them, with their
  dates, currency signs and thousands marks. A negative cost, or a currency sign that is not the
  vehicle's, makes the row unreadable.
- A fill-up of a fuel the vehicle does not take is imported and flagged, as one entered by hand. A
  date before 1970 makes the row unreadable. While the vehicle's currency is no three-letter code,
  only the rows with money are left out, and the preview says to correct the vehicle.
- Spritmonitor files are read with English or German headers, and the fuel and the cost type as
  codes. Each cost type has a default meaning, which the preview shows and you may change. A
  purchase price, a refund, AdBlue and hydrogen are never imported.
- A file over 5 000 000 bytes, 20 000 rows or 500 000 cells, with a line over 64 KiB or 256
  cells, or in an encoding other than UTF-8 and Windows-1252 is refused with the reason. A row with
  more cells than the header, or a value past the bounds a typed entry has, is unreadable.

### Your data

- Nextcloud's *user_migration* app exports your NextFleet data with your account: every row
  that names you (vehicles you own, entries you made, your grants, the reminder lists you are
  on and the reminders sent to you, bookings and audit rows) as a JSON file per table, deleted
  rows included. A vehicle, grant, reminder or audit row on a vehicle you can no longer see is
  listed only by its id, since its content is now somebody else's. Importing the archive restores
  nothing. Tried with `occ user:export` on NC 31
  and NC 34 (user_migration 10.5.0) and checked weekly.
- Deleting a Nextcloud account pseudonymises every row that names it, bookings included, and deletes
  only its places on reminder lists
  ([ADR 0008](https://github.com/typical42/next-fleet/blob/main/docs/adr/0008-erasing-a-driver-pseudonymises.md));
  the handover notes stay, as trips do. The pseudonym is `erased:` and 20 letters and digits, which
  no account name can be, so a new account under the same name inherits nothing. An account deleted
  while the app is disabled keeps its name on the rows; `occ nextfleet:check` reports the vehicles
  it owned and the access it held.
- Deleting an owner's account closes their vehicles: every grant on them is revoked and they move
  to the trash, their rows kept. An admin hands a vehicle on first with `occ nextfleet:transfer`.
- An erasure or a deleted group's revokes that a database error cuts short are finished by a
  background job within the hour. There, everyone on the group's vehicles' reminder lists who no
  longer sees the vehicle comes off, a bookkeeper included. A group made again under that name
  meanwhile keeps the access it was given since.
- Deleting a vehicle is a soft delete, and takes back the notifications its reminders sent. Nothing
  purges a vehicle's rows yet.
- An admin can read the database; nothing is encrypted on the client. Reminder mails go out
  through the server's mail account. What stays after an erasure, such as old trip text in the
  audit and the server's logs, is listed in [docs/legal.md](https://github.com/typical42/next-fleet/blob/main/docs/legal.md).
- The logbooks, the Reports screen and Logbook mode say that no lawyer reviewed them.

### For apps, scripts and admins

- An OCS API under `/ocs/v2.php/apps/nextfleet/api/v1` for apps such as a phone client. It signs in
  with a Nextcloud app password, which a client gets through Login Flow v2. It does what the web UI
  does, and a sync endpoint hands a client what changed since its last call. v1 only grows: a
  change that would break a client fails the tests. `openapi.json` ships with the app, where
  Nextcloud's OCS API viewer finds it. All of it is in [docs/api.md](https://github.com/typical42/next-fleet/blob/main/docs/api.md).
- Every create takes an optional `client_uuid`. Sent again under it, a create writes nothing and
  answers the row it wrote, with 200 ([retried creates](https://github.com/typical42/next-fleet/blob/main/docs/api.md#retried-creates)).
- Sync sends what changed and no more: a Reading whose flag another entry changed comes, the rest
  of its chain does not. Deleting an account that never used the app starts no client over.
- `occ nextfleet:import <uid> <vehicle-uuid> <path>` runs the import for scripts, as that user and
  through the same checks. `--dry-run` prints the preview. A `--map` text the file does not hold
  fails the command.
- `occ nextfleet:transfer <vehicle-uuid> <new-owner-uid>` hands a vehicle to another account. Its
  grants stay, the former owner keeps viewing it, and the vehicle's audit trail records the move.
- Admin commands that find, check, repair and undo without SQL: `occ nextfleet:vehicles` lists
  every vehicle's uuid, `access` and `audit` show who may do what and what changed, `check` finds
  rows that break the data model and `recompute` settles a drifted odometer again, `pending`
  finishes an erasure or a deleted group's revokes that a failure cut short, `reminders` and
  `mail-test` show what a user is reminded of and whether mail reaches them, and `restore` takes
  a vehicle out of the trash. The
  [administration guide](https://github.com/typical42/next-fleet/blob/main/docs/admin/README.md) says where the
  data lives, that only the database backup protects it, and how an upgrade migrates it.
- `occ nextfleet:seed <user>` writes a demo fleet to try it on. `--grant-to <uid>` gives that
  account access to the demo Passat as a driver, with a trip of theirs and a booking tomorrow.
  Where the sharing settings refuse that account, the fleet is still seeded and the command says
  why and fails.
- The CSV export, each sync page and each import write one `info` line to the Nextcloud log: who,
  which vehicle, what and how many rows. Set `loglevel` to 1 to see them
  ([what is logged](https://github.com/typical42/next-fleet/blob/main/docs/security.md#what-is-logged)).
- Running 0.2.0 or 0.3.0 from the repository's source? `occ upgrade` takes it to 0.3.1. It keeps
  every row but one kind: it takes off the reminder lists anyone whom neither the owner nor
  whoever listed them may share with and who does not see the vehicle, as adding them is refused
  now. An account named like an erased driver
  keeps its rows, and is named in the upgrade's output, the log and a notification to every admin.
  The upgrade adds indexes to most tables, so on a large fleet it takes a while.
  `tools/upgrade-check.sh` checks that it keeps the rows on NC 31 and NC 34, on MariaDB and
  PostgreSQL, before a release and weekly, and on NC 34 with Oracle before a release.
- Runs on Oracle as well as MariaDB/MySQL, PostgreSQL and SQLite. Oracle was tried on NC 34 with
  Oracle Free 23 and is checked weekly ([Oracle](https://github.com/typical42/next-fleet/blob/main/docs/development.md#oracle)).
- Every field a request sets has a bound: notes 10 000 characters, money 10^12 cents, counters
  10^9, and text must be UTF-8. A value past it is refused with a 400 that names the field
  ([security](https://github.com/typical42/next-fleet/blob/main/docs/security.md)).
- Report a vulnerability privately through GitHub's private vulnerability reporting, as
  `SECURITY.md` says. The first reply comes within 14 days.

### Documentation

- A [user manual](https://github.com/typical42/next-fleet/blob/main/docs/user/README.md), an
  [administration guide](https://github.com/typical42/next-fleet/blob/main/docs/admin/README.md) and a
  [developer guide](https://github.com/typical42/next-fleet/blob/main/docs/developer/README.md), written in Simplified
  Technical English. `appinfo/info.xml` links all three for the app store.
- The README says how the app was made: an AI tool, Claude Code, wrote most of it, and the
  maintainer reviewed and tested it and answers for every line. A change to the app follows
  Nextcloud's AI policy: each commit an AI tool helped with names it in an `Assisted-by` trailer,
  and the pull request says so
  ([contributing](https://github.com/typical42/next-fleet/blob/main/docs/contributing.md#ai-assistance)).
- `npm run release -- prepare <date>` and `npm run release -- build --key <path> --cert <path>` do
  the release steps up to the upload. Each stops where the maintainer commits or uploads
  ([release](https://github.com/typical42/next-fleet/blob/main/docs/development.md#release)).
