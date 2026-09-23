# Changelog

## Unreleased

- The Costs screen, opened by *Costs* on a vehicle's screen. One year at a time: the year's figures
  including TCO, a bar per month stacked in energy, maintenance and expenses, and a table beneath
  with the expenses by category. A month with no rows reads "No entries", not zero. Depreciation
  stays in the TCO and out of the bars. The export button is not there yet.

- A year of costs for the Costs screen. `GET /api/vehicles/{uuid}/costs/{year}?tz=&net=` answers
  the header's figures for the year and each month's cost, the months cut at midnight in `tz`. A
  cost now also states its maintenance total and its expenses by category, the header's included.
  A month with no rows has no cost rather than zero.

- Deleting a Nextcloud account pseudonymises its rows and deletes none (ADR 0008). Every row
  that names it, as author, owner, grantee or notified user, carries one random `erased-…`
  pseudonym instead, so a new account under the same uid inherits nothing. Its reminder-list
  entries go. The vehicle sheet no longer asks for a retention period, since nothing purges yet;
  `docs/legal.md` says so.

- `fleet_documents`, M5's one migration: a vehicle's papers by Nextcloud file id, with a kind and
  an optional link to one fill-up, maintenance record or expense. No route reads it yet. The app is
  now 0.0.6, so `occ upgrade` creates the table.

- Deleting a vehicle takes back the notifications its reminders sent. Before, one stayed in the
  store for good, since the job no longer reads a deleted vehicle. An undo sends nothing back.

- The M4 slice end to end. `tests/e2e/m4-slice.spec.js` enters a HU/AU from the sticker, adds a
  German recipient and a daily mail in the vehicle sheet, and runs the job twice at a moved clock.
  The recipient then has one notification and one digest. Next, an oil change is closed from the
  banner, the next one shows, and the overview reorders. `tests/e2e/job.php` runs the job at a given
  instant, inside the container, through the Docker socket, which `test:e2e:docker` now mounts. CI
  starts Mailpit for it. `plan.md` gains decision 18 (recurrence from the work done) and the lossy
  undo as a risk; `CONTEXT.md` gains the reminder terms.

- Trip autocomplete. The entry sheet completes a trip's starting point, destination, purpose and
  partner from this vehicle's own trips, latest first; both ends of the route share one list.
  `GET /api/vehicles/{uuid}/trips/prefill` answers them. Voided trips are not offered.

- Deleting a vehicle is a soft delete and nothing more; no code path purges a vehicle's rows yet.
  `docs/architecture.md` says so.

- The demo fleet has reminders. `occ nextfleet:seed` adds an HU/AU three weeks out on the hybrid,
  an oil change by kilometres on the Passat — inside its lead, with a pace behind it, so the banner
  shows an estimated date — and an HU/AU on the truck, which the German scheme recurs every 12
  months rather than 24. Each states only when it is due; the interval is its template's. The demo
  vehicles now state Germany as their jurisdiction instead of taking the seeding account's setting,
  since a profile without an inspection scheme has no HU/AU to write.

- The overview by urgency. Each vehicle shows a traffic light with its word, its km and its most
  urgent open reminder; the most urgent vehicle comes first, then by plate, laid-up ones last.
  `GET /api/reminders` lists the reminders of every vehicle the user may see in one read.

- The mail digest. After the notifications, `ReminderJob` mails each recipient at most once a
  day, from 07:00 in their time zone: every vehicle whose cadence falls that day (daily, weekly on
  Monday, monthly on the 1st) and has a point not yet mailed to them, grouped by vehicle, in their
  language. No news, no mail. A mail server that refuses it silences neither the notification nor
  the next day's mail.

- Reminders reach people. The hourly `ReminderJob` evaluates every reminder of every vehicle in
  service, persists its state, and sends each recipient a Nextcloud notification once per warning
  point, due and overdue, in their language; it opens the vehicle for whoever may see it. A newer
  point replaces the older one; snoozing, dismissing, deleting or closing a reminder takes it back. A laid-up
  vehicle sends nothing until it is back in service. The app is now 0.0.5, so `occ upgrade`
  registers the job.

- Who reminders go to. The edit sheet shows owner and managers a recipients picker and the mail
  cadence (`reminder_mail`: no mail, daily, weekly on Monday, monthly on the 1st); drivers and
  viewers see neither. `GET/POST …/recipients` and `DELETE …/recipients/{recipient}` read and
  edit the list, and need edit rights to read too. A new vehicle starts with its owner as
  recipient, as the migration gave every existing one.

- A maintenance record closes a reminder. The entry sheet offers the vehicle's open reminders as
  chips, and picks the most urgent one when the work is its kind; _Done_ in the due banner opens
  the sheet with that reminder picked. `…/maintenance` takes and answers `closes` (a reminder
  uuid), and the timeline states it. A recurring reminder moves on from the record's own day and
  counter; a one-off is done. Editing or deleting the record takes that back and redoes it; the
  reopened occurrence is due at the withdrawn work's day and km, since the planned due is not kept.

- HU/AU from the sticker. Where the jurisdiction requires an inspection and the vehicle has no
  open HU/AU reminder, the due banner asks for the month and year on the sticker; the reminder is
  due on that month's last day. A vehicle before its first inspection is prefilled from its first
  registration. The edit sheet sets the inspection interval (12 or 24 months) on that reminder,
  or offers to add one. `…/reminder-templates` states `first_due_months` on the inspection.

- The due banner on the vehicle screen lists its open reminders, most urgent first, each with its
  state as a word, when it is due, and an estimate or "not enough data yet". _+ Reminder_ and a
  tap on a row open the reminder sheet: a template or an own title, due by date, km or either,
  warning points, recurrence; on an existing one also snooze, skip and delete with undo. Reminders
  are not timeline rows. `…/reminders` now lists each state as it stands today rather than as
  stored, and `GET …/reminder-templates` states what each template fills in.
- A reminder by km, or by either, is listed with an `estimate`: the day the main chain's pace of
  the last 90 days reaches its due km, or null with under 30 days or two Readings of pace. Flagged
  Readings do not count, and an answered reset starts the pace again. It is display only.
- Snooze and dismiss: `POST …/reminders/{reminder}/snooze` (with `until`, a day still to come) and
  `…/dismiss`, owner and managers only. Dismissing moves a recurring reminder one recurrence on
  from its planned due; one that does not recur stays dismissed. The state of a reminder at any day
  and counter reading is now computed in one place (docs/architecture.md, "The state at an
  instant"). By km a reminder is due and stays due; overdue is the date's.
- Reminders on the server: `GET`/`POST /api/vehicles/{uuid}/reminders`, and `PUT`, `DELETE` and
  `POST …/restore` on `…/reminders/{reminder}`. A reminder is made from a template the vehicle
  offers, HU/AU only where its jurisdiction requires one, or under the user's own title. It is due
  by date, by km or by either, with warning points and a recurrence. The owner and managers write;
  drivers and viewers read. Nothing evaluates or sends them yet.
- Reminder templates. `IServiceTemplates` offers oil change (12 months or 15 000 km, warned
  1 000 km ahead), brake fluid (24 months) and tyre swap (6 months), the same everywhere. A
  jurisdiction states its inspection (`IJurisdiction::inspectionScheme()`): Germany's is HU/AU,
  every 12 months for a truck or tractor and 24 for the rest, first due after 36 months for a
  car. The generic profile has none.
- Reminders write no calendar event: public `OCP` on NC 31–34 cannot update or delete one, so a
  changed or completed reminder would leave an event that still rings. The empty `CalendarService`
  is gone; the in-app notification and the mail digest carry reminders.
- The M4 schema: `fleet_reminders`, `fleet_reminder_receipts` (one row per point, occurrence,
  channel and recipient, unique) and `fleet_reminder_recipients`, `reminder_id` on a maintenance
  record and `reminder_mail` on the vehicle (default `weekly`). Every vehicle already there gets
  its owner as its one recipient. The receipts table is not `fleet_reminder_notifications`, which
  is one character over the 27 Nextcloud allows. No service writes them yet; a vehicle carries
  `reminder_mail` on the wire.
- The M3 schema: `fleet_energy`, `fleet_maintenance` and `fleet_expenses`, a `counter` on each
  Reading (`main` or `second`; every existing Reading reads as `main`), and `second_unit` and
  `second_value` on the vehicle for engine hours beside the kilometres. Nothing writes them yet.
  A vehicle and a Reading now carry the new fields on the wire.
- A vehicle can be a truck. The create sheet asks for the vehicle type and the counter unit beside
  the counter; choosing a tractor or a generator switches the unit to engine hours, still
  changeable, and never on a vehicle that already has Readings. The edit sheet's "Also counts engine
  hours" switch, offered on a km vehicle, sets `second_unit`; switching it off keeps the hour
  Readings. A vehicle counted in hours carries no second counter.
- Engine hours are a chain of their own. An Odometer Entry on a vehicle with a second counter asks
  which counter it read (`counter` on `POST …/readings`; `second` is refused on a vehicle without
  one). Each chain is flagged and cached on its own, `second_value` for the hours; trips, Gaps and
  the Fahrtenbuch read kilometres only. The timeline states an hour Reading in hours.
- A jurisdiction states its VAT rate by date (`IJurisdiction::rates()`). Germany answers 19 %, and
  16 % from 2020-07-01 to 2020-12-31, citing §12 UStG; nothing before 2007. The generic profile
  states no rates. Nothing reads it yet.
- A fill-up on the server: `POST /api/vehicles/{uuid}/energy`. Each counter given (`odo`,
  `second_odo`) writes an Observed Reading at `filled_at` that accounts for no kilometre; none
  given, none written. `second_odo` is refused on a vehicle without engine hours. `unit_price` is
  derived from `total` and `amount` when not given. The answer carries `flags`: `foreign_energy`
  for an energy outside `energy_types`, `no_price` for a missing total.
- Energy in the entry sheet, offered on a vehicle with `energy_types` and only with those. Amount
  (required) and total are typed as the pump shows them, with a comma or a point. VAT comes
  prefilled with the jurisdiction's rate on the fill-up's day and clears to "not stated". The
  station completes from this vehicle's history and prefills the price it last charged; that
  price is sent only when there is no total or the driver changed it. A charge asks home or
  public, and DC only in public. An empty counter says consumption needs it. The prefill is
  `GET /api/vehicles/{uuid}/energy/prefill?at=&off=`.
- Maintenance: `POST /api/vehicles/{uuid}/maintenance`, with a fill-up's counter rules. The entry
  sheet offers it on every vehicle: title (required), cost, type (service, repair, inspection,
  tyres or upgrade; none by default), date, vendor, VAT, counters and notes. The vendor completes
  from this vehicle's history; VAT is prefilled as on a fill-up. The prefill is
  `GET /api/vehicles/{uuid}/maintenance/prefill?at=&off=`. Date, VAT and counters are kept when
  the sheet switches between Energy and Maintenance.
- Expense: `POST /api/vehicles/{uuid}/expenses`. It writes no Reading. The entry sheet offers it
  last on every vehicle: amount (required), category (insurance, vehicle tax, toll, parking, fine,
  lease or other; none by default), date, VAT and notes. VAT is prefilled as on a fill-up; the
  prefill is `GET /api/vehicles/{uuid}/expenses/prefill?at=&off=`.
- Fill-ups, maintenance and expenses are in the timeline, with chips
  `All · Trips · Odometer · Energy · Maintenance · Costs` (Costs is the expenses; `?type=energy`,
  `maintenance`, `expense`). A fill-up row states its amount, a maintenance row its cost, an expense
  row its amount. A row says in words what it is flagged for, whether a fill-up was partial or
  missed the one before, and whether a counter it wrote is in question. A fill-up is now also
  flagged `overfilled` when it exceeds `tank_ml` (or `battery_wh` for electricity).
- Fill-ups, maintenance and expenses can be edited, deleted and restored on the server:
  `PUT` and `DELETE /api/vehicles/{uuid}/{energy|maintenance|expenses}/{id}` and `POST …/restore`,
  each checked against `updated_at` like a trip. A delete is a soft delete with undo and writes no
  audit row, under Logbook Mode too. The Readings a fill-up or record wrote follow it in the same
  transaction: an edit moves them, adds one for a counter now given and removes one for a counter
  emptied; a delete takes them along and the undo brings them back.
- Every timeline row opens its Entry in the entry sheet: trip, fill-up, maintenance, expense and
  Odometer Entry. The sheet keeps the Entry's kind, saves the whole Entry back under the token it
  was read with, and deletes it with the undo toast - a trip under Logbook Mode is voided, and the
  button and the toast say so. An edit that lost a race says so and offers _Save anyway_, which
  reads the Entry back and writes what is on screen. A rate the Entry was saved with, stated or
  not, is never replaced by the jurisdiction's.
- An Odometer Entry can be edited, deleted and restored: `PUT` and `DELETE
  /api/vehicles/{uuid}/readings/{id}` and `POST …/restore`, on the token. An edit restates the
  number, the moment and the counter, and settles both chains when the counter changed. A Reading
  a trip, fill-up or maintenance record wrote is not found here: it follows its Entry.
- `GET /api/vehicles/{uuid}/timeline/{type}/{id}` reads one Entry back as its timeline row.
- Consumption, full tank to full tank, per energy: a fill-up closing a segment states it on its
  timeline row (`6,1 l/100 km`, or `l/h` on a vehicle counted in hours) and carries it as
  `consumption`. Partials count into the segment; a segment with a missed previous fill-up, a
  flagged or derived Reading at either end, or an end without a counter states none. A plug-in
  hybrid's petrol and electricity are measured apart, and engine hours never enter the figure.
- A rolling wall-side kWh/100 km over every charge in a period, labelled approximate because
  charging losses are in it (`ConsumptionService::wallSide()`).
- Cost per 100 km (or per hour) for a period, and energy-only cost beside it
  (`CostService::of()`). Net of each row's own rate for a person who reclaims VAT; a row without a
  stated rate counts gross and the figure says so. Incomplete when a fill-up has no price, a
  period total when no distance was driven, no figures without a currency.
- TCO beside it: cost per 100 km plus purchase minus residual over every kilometre the vehicle has
  run since its first Reading. Unset when either price is empty, as entered under "I reclaim VAT".
- The vehicle header states them: odometer, consumption per energy (two for a plug-in hybrid, plus
  the wall-side figure), cost, energy-only cost and TCO, and engine hours in the period on a vehicle
  that counts them. A period picker offers the last 12 months, this year, last year or one month,
  and every figure is compared with the period before. `GET /api/vehicles/{uuid}/kpis?from=&to=&net=`
  answers one period.
- "I reclaim VAT" on the personal settings page (off) makes the header's cost figures net. It and
  the header's period are preferences: `reclaim_vat` and `kpi_period` on `GET|PUT /api/preferences`.
- The "complete this vehicle" hint also asks for a currency when a vehicle has none, on every
  vehicle, since a trailer has costs too.
- `occ nextfleet:seed` writes a year of costs: fill-ups with a partial and a missed previous, the
  hybrid's petrol and its charges at home and in public, maintenance, and expenses with and without
  a stated VAT rate. It adds a truck with an hour chain (`NF-LK 700`) and a residual on the Passat.
  The hybrid's cluster swap moved back to more than a year ago; its counter now ends at 23,740 km.
- An insurance, vehicle tax or fine expense opens with the VAT field empty ("not stated") in
  Germany, since none of them carries VAT; the other categories keep the day's rate. The
  jurisdiction names the categories (`IRateProvider::vatFreeCategories()`), and the prefill takes
  `category`. Such a row counts gross under "I reclaim VAT" and shows in the header's note on rows
  without a rate. The seed's insurance, tax and fine rows state no rate either, instead of 0.
- A fill-up edited from its row saves. `PUT …/energy/{energy}` looked the fill-up up by the body's
  `energy` field, the fuel, and answered 404 on every edit; the placeholder is now `{fillUp}`, and a
  unit test keeps placeholders apart from body fields. Delete and undo were not affected.
- `tests/e2e/m3-slice.spec.js` drives the M3 slice on both majors: a fill-up moving the header, a
  hybrid's two figures, an edit, a delete and its undo, the period picker, and an axe audit at
  320 × 640 in the dark. The M1 and M2 slices find the odometer in the new header.
- A period with no fill-up, maintenance record or expense has no cost: every sum in the KPIs' `cost`
  is `null` rather than 0, TCO included, so its tile hides. The header still shows such a period as
  zero but compares nothing with it, so a vehicle bought four months ago shows no swing against the
  months before it existed.

- Overview, at the top of the navigation. It leads back from a vehicle or from Reports without a
  reload, and is marked whenever the overview is what shows.
- Two writes on one vehicle at once no longer leave its kilometres on the older Reading. Every
  Odometer Entry and trip write now holds the vehicle first, so the second waits for the first.
  An Odometer Entry and its restating are now one transaction.
- Reports, below the vehicles in the navigation. It opens the Fahrtenbuch for a vehicle and a year
  in a tab of its own, prefilled with the first vehicle and this year. It offers only vehicles whose
  country prints a logbook, sold ones included. `GET /api/preferences` now says which countries do
  (`logbook_export`).
- The Fahrtenbuch export: `GET /apps/nextfleet/vehicles/{uuid}/logbook/{year}` opens one vehicle's
  logbook for one year as a page the browser prints, in German. Every trip that set off in the year
  is a line, voided trips listed as voided. A trip that set off while Logbook Mode was on and lacks
  a field is marked incomplete and names it; outside those periods nothing is marked. A trip edited,
  voided or restored after the lock delay says when on its line: an edit with what each changed
  field said before, a restore with since when the trip had been voided. The page
  states the periods the mode was on, or that it was not, and cites the requirement in its footer.
  A country without an export answers 404. The page loads nothing, and the export is rate-limited
  and logged.
- A Gap is closed one at a time. Under Logbook Mode the trip that opened it offers to close it, and
  a confirmation naming the kilometres and the two moments writes one private distance trip marked
  `reconciled`, whose Reading lands on the claim. `POST /api/vehicles/{uuid}/gaps/{trip}/close`
  takes the Gap as the driver confirmed it and answers 412 `conflict` when it has moved since. Under
  the mode its audit row is `created` with `derived: true`. It edits like any other trip, and
  `reconciled` is never set or cleared by a request.
- Gap detection: a trip that sets off above where the last trip left the counter leaves the
  kilometres in between unaccounted. An Odometer Entry between the two accounts for none of them,
  and one that is flagged or above the claim opens no Gap until it is answered. With no trip before,
  the claim is measured against the newest Reading. `GET /api/vehicles/{uuid}/gaps` lists them for every vehicle, each with the two
  moments that bracket it. Under Logbook Mode the timeline's month header states the month's total.
- A trip can be edited: `PUT /api/vehicles/{uuid}/trips/{trip}` takes the whole trip and the token
  it was read with, and rewrites the row in place. A field the request leaves out is emptied, so a
  distance trip can become a counter trip. When the journey moves, its Reading moves with it in the
  same transaction. Under Logbook Mode the edit leaves an `edited` audit row with what changed, and
  an edit after the ruleset's lock delay is still allowed and marked `late`.
- A trip missing a field its jurisdiction requires is saved and flagged, never refused. Under
  Logbook Mode its timeline row says it is incomplete and names what is still missing, in the words
  the sheets ask by. The timeline's trip rows carry `missing` for every vehicle.
- Germany states what it requires of a logbook: a business trip names the plate, the date, both
  counters, where it went, why and whom it visited; a commute states the journey and not its reason;
  a private trip needs only the kilometres it already carries. An entry stays timely for seven days,
  a record is kept for ten years, and the requirement is cited by URL. A country that requires no
  logbook says so, and under it Logbook Mode still keeps trips append-only and audited.
- A trip is deleted by voiding it: `DELETE /api/vehicles/{uuid}/trips/{trip}` stamps `deleted_at`,
  the row survives, and the Reading the trip left on the counter goes with it, so the vehicle stands
  where it did before the journey. `POST …/trips/{trip}/restore` brings both back on the token the
  delete answered with. Under Logbook Mode each of the two leaves a `fleet_audit` row, in the same
  transaction as the write.
- Logbook Mode is switched on and off per vehicle, in the vehicle's edit sheet beside its lifecycle
  and its country. Every flip leaves a `fleet_audit` row on the vehicle, in the same transaction as
  the write, which is how an export later states the periods the mode was on. Switching off asks
  first; switching on does not.
- The vehicle sheet's two date pickers stop taking input while a save is in flight, like every field
  beside them.
- A vehicle under Logbook Mode keeps an audit trail: every trip written on it leaves a `fleet_audit`
  row naming the trip and listing the fields it was written with, in the same transaction as the
  trip itself. Off the mode nothing is recorded.
- The vehicle screen shows its timeline: trips and counter readings as rows, newest first, the month
  a sticky header above them and the next fifty loaded as the bottom comes into view. The chips
  `All · Trips · Odometer` narrow it, and an entry saved in the sheet appears without a reload.
- `GET /api/vehicles/{uuid}/timeline` answers with one vehicle's trips and counter readings in one
  order, newest first, fifty rows at a time. `?type=` narrows it to one kind, `?cursor=` continues
  where the last page stopped, and a trip carries the Reading it left on the counter so the two are
  one row.
- The entry sheet asks what is being entered — a trip or a counter reading — and opens on the trip.
  A trip is entered with the counter it ended on or with the kilometres it covered, whichever the
  driver knows. Neither of a trip's counters is prefilled: the one it set off on is a claim about
  what the dashboard read, and the vehicle's own counter is not that claim.
- A counter a trip actually ended on discredits the Readings the distance-only trips before it
  counted: those rows are flagged and none of them is rewritten. A counter still below the last row
  standing is flagged too, so both questions get asked.
- A trip may carry the kilometres it covered instead of the counter it ended on. Its Reading is
  counted from the newest one at or before the moment it set off, and marked derived. A trip that
  carries both, or neither, is refused.
- `POST /api/vehicles/{uuid}/trips` records a trip. The counter it ended on becomes one Reading at
  `ended_at`, and the vehicle's kilometres move with it. `start_odo` stays a claim and writes no
  Reading.
- A second migration adds `fleet_trips` and `fleet_audit`, the two tables the logbook is made of.
  It is the only schema change this milestone gets.
- `fleet_trips` carries no `cost_center` and no `driver_uid`, and an audit row's author and instant
  are the `created_by` and `created_at` every row has. `docs/architecture.md` said otherwise, and
  `docs/legal.md` and ADR 0008 named `driver_uid` as what a driver's erasure pseudonymises.
- Dismissing the *First registration* or *Disposed on* picker with `Esc` no longer closes the
  vehicle sheet and loses what was typed into it.
