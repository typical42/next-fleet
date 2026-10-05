# Interface and languages

Part of the [NextFleet plan](../plan.md). Terms are defined in [CONTEXT.md](../CONTEXT.md).

## Interface

Mockups of the three screens below, source in [`design/`](../design/), published as a canvas at
<https://claude.ai/code/artifact/854cd073-fba1-4cda-b2b3-62314da24f39>.

### The one insight that shapes everything

There are two moments, and they want opposite interfaces.

**Entering** happens at the pump, in a car park, one-handed, on a phone, in half a minute, often
with bad reception. It wants three huge fields and no navigation.

**Reviewing** happens at a desk on a wide screen: what did this car cost, what is due, where did
March go. It wants density, tables and filters.

Designing one screen for both is how logbooks die. So: entry is mobile-first and lives in a sheet
that can be reached from anywhere; review is desktop-first and lives in the app shell.

### Look like Nextcloud, not like fleet software

"Intuitive" here does not mean inventing something clever. It means the app looks like Files, Deck
and Calendar, because the user has already learned those. Use the standard shell —
`NcAppNavigation` on the left, `NcAppContent` beside it — and the
standard components (`NcButton`, `NcListItem`, `NcEmptyContent`, `NcActions`, `NcDialog`). No custom
chrome, no bespoke tables, no second design language inside the page.

### Do not let the schema dictate the navigation

The obvious layout gives each table a tab: Trips, Energy, Maintenance, Expenses. That mirrors the
[data model](architecture.md#data-model) and answers no question a person actually asks. People ask
"what happened with this car?" and "what did March cost?".

So the vehicle has **one timeline** of everything that happened — trips, fill-ups, maintenance,
expenses — newest first, with filter chips above it:
`All · Trips · Odometer · Energy · Maintenance · Expenses`. One place to look, one place to search, and
the tabs collapse from six to three. A chip per kind of Entry, so the odometer has one too: a
counter somebody read is a thing that happened to the vehicle, even though the counter's own chain
is a different question and keeps its own route
([architecture](architecture.md#the-timeline)).

The timeline is also where flagged records get resolved. An odometer that went backwards shows the
follow-up question on its row — "Was the counter replaced, or is this a typo?" — instead of
interrupting the person who entered it. *Counter replaced* answers it there; *Typo* opens the
entry to fix the number ([odometer rules](architecture.md#odometer-rules)).

```
┌─ NextFleet ──────────────────────────────────────────────────────────┐
│ Overview        │  M-AB 1234     [+ Entry][Costs][Edit][QR sticker]  │
│ Inbox       (3) │  VW Passat Variant                                 │
│                 │  148 320 km · 6,4 l/100 km · 42,10 €/100 km        │
│ ● M-AB 1234  ⚠  │  ┌────────────────────────────────────────────┐   │
│ ● HH-CD 42      │  │ ● Coming up · HU/AU · due 24.09.2026 [Done]│   │
│ ● M-EV 7   ⛔   │  │ ● Planned · Oil change · ~02.11.2026 [Done]│   │
│                 │  └────────────────────────────────────────────┘   │
│ Reports         │                                                    │
│ ─────────────── │  Bookings [Book][Take it now]                      │
│ Settings        │  COMING                                            │
│                 │  Fr., 02.10., 14:00–18:00  Anna  Booked  [Change]  │
│                 │                                                    │
│                 │  Documents [Add document]                          │
│                 │  REGISTRATION                                      │
│                 │  Fahrzeugschein.pdf                        Remove  │
│                 │  RECEIPT                                           │
│                 │  Huber.pdf  Belongs to a maintenance rec.  Remove  │
│                 │                                                    │
│                 │  Timeline [All][Trips][Odo.][Energy][Maint.][Exp.] │
│                 │  ───────────────────────────────────────────────   │
│                 │  03.09.  ⛽ Energy    48,2 l   82,10 €   6,1 l/100 │
│                 │  02.09.  🚗 Munich → Augsburg   82 km   business   │
│                 │  28.08.  🔧 Brake pads 📎 Werkstatt Huber 312,00 € │
└─────────────────┴────────────────────────────────────────────────────┘
```

The header states the odometer, then the period's figures: consumption per energy (a plug-in hybrid
gets two, and the wall-side kWh beside them), cost, energy-only cost and TCO, per 100 km or per hour
([the maths](architecture.md#numbers-consumption-cost-emissions)). A vehicle that counts engine
hours beside its kilometres adds the hours in the period, noting that the consumption includes fuel
used while working. The period is picked above the figures — last 12 months by default, this year,
last year, or one month — and each figure is compared with the period before, of the same length, as
a signed difference rather than a colour. A figure either period lacks gets no comparison; a period that recorded no cost shows it as zero,
uncompared. With
no distance in the period the costs are period totals; without a currency the header says so in
place of every cost. The client names the period, because a year starts at midnight where the
person is; the server answers `GET …/kpis?from=&to=&net=` once for each of the two. The chosen
period and "I reclaim VAT" (a switch on the personal settings page, off by default) are
preferences, so the next machine opens on the same figures; `net` is the second one.

The **due banner** sits between the header and the timeline and lists what is coming. A reminder
has not happened yet, so it never becomes a timeline row. The banner shows the open reminders, most
urgent first: overdue, due, coming up, planned, snoozed, and within a state the sooner day, a due
date or an estimate. Each row states the reminder's state as a word beside its colour, its title,
when it is due, and for a reminder by km the estimated day or "not enough data yet". The list states
each reminder as it stands today (see [the reminder engine](architecture.md#reminder-engine)).
_+ Reminder_ opens the reminder sheet: a template or an own title, due by date, by km or by
whichever comes first, the warning points and the recurrence. A template fills in what it knows, and
every field stays editable. A row opens the same sheet on that reminder, with snooze (a week, a
month, or until a day), _Skip this time_, and a delete that goes with the undo toast. _Done_ beside
a row opens the entry sheet on a Maintenance Record with that reminder picked, and its title and
type filled in from the reminder.

Where the vehicle's jurisdiction requires an inspection and no HU/AU reminder is open, the banner
asks "When is the next HU/AU?": the month and year on the sticker. The reminder is due on that
month's last day and recurs at the template's interval. A vehicle still waiting for its first
inspection comes prefilled from its first registration: a car registered in March 2024 is asked
about March 2027. The edit sheet shows that reminder's recurrence as _Inspection interval_, 12 or 24
months, and writes a change to the reminder when the vehicle is saved. With no HU/AU reminder it
offers _Add HU/AU reminder_ instead, which asks the same question in the sheet.

The edit sheet also says who the reminders go to: _Reminders go to_, a Nextcloud account picker,
and _Reminder mail_, the digest cadence. Owner and managers see both; anyone else sees neither,
because the list is refused to them. A pick is written at once, since the list is its own table;
the cadence is a column of the vehicle and is saved with it.

The timeline pages: 50 rows, then more on scroll, with the month a sticky header. Five years of a
company car is thousands of rows, and the screen people open most often is not the place to
discover that. The scroll is the bottom of the list coming into view; the button sitting there is
what a browser without an observer, and a page the server refused, still have. At most four pages
are on screen: the oldest goes as the next comes, and *Show newer entries* above the rows, or
scrolling back up to it, brings it back from memory without asking the server. The rows keep their
reading order, so a keyboard walks them top to bottom as before, and the row the reader is at stays
where it was on screen.

Under Logbook Mode the month header also states the month's unaccounted kilometres
([logbook mode](features.md#logbook-mode)). It is the whole month's figure from the first page on,
never the sum of the rows scrolled in so far. The trip that opened a Gap says so on its row and
offers *Close gap*; the question names the kilometres and the two moments, and a confirmation writes
one private trip whose row reads *Reconciled*. The offer sits on the row, not on the header, because
a Gap is closed one at a time. A trip entered later inside that private trip's span overtakes it:
its row reads *Reconciliation overtaken* and offers *Void trip*. Any two trips whose times overlap
both read *Overlaps another trip*.

A row states one figure, and it is the one the driver gave — the kilometres a trip covered, or the
counter it ended on, never both and never one worked out from the other
([odometer rules](architecture.md#odometer-rules)). A fill-up states its amount, a maintenance
record its cost, an expense its amount. A flagged Reading is carried on the row it belongs to, as a
word rather than a colour, and so is what a fill-up is flagged for — no price, an energy the
vehicle does not take, more than it holds — and whether it was partial or missed the one before.
A fill-up that closes a full-to-full segment also states that segment's consumption, the one figure
on a row that is worked out rather than given.

The vehicle's own data — the things you set once and rarely touch — lives in the edit sheet, not on
the screen. That keeps the screen free for the things you touch weekly. The documents sit in a section of their own
above the timeline instead: listed by kind, each one a link, with *Add
document* opening Nextcloud's file picker. A document linked to an entry shows as a paperclip on
that entry's row, one linked to a booking on the booking's. It is never a row of its own, since a
registration has no date to sort by ([documents](architecture.md#documents)). A driver adds papers
too, but only to an entry or booking of their own: *Belongs to* offers only those and must be
chosen, and *Remove* shows on the papers the server says they may take off. *Belongs to* offers the
newest rows; typing a word of the title or a date searches all of them. *Remove* goes with the undo
toast. A tap on a paper saves its file, on a booking's row too; when the server refuses, the section
or the row says why.

### Screens

| Screen | Purpose | Primary action |
|---|---|---|
| **Overview** | All vehicles, sorted by urgency, not alphabetically: laid-up ones last, then by the most urgent open reminder's state and day, then by plate. Traffic light (red due or overdue, amber coming up, green otherwise) with its word, plate, km, next due. A vehicle reached through a grant sorts among the reader's own and says "Owned by" its owner's display name, from the server's `owned_by`. A car that is out says who has it until when ([who has the car](#the-bookings-section)). | Open a vehicle |
| **Vehicle** | Header KPIs + due banner + bookings + documents + timeline (above) | **New entry** |
| **Entry sheet** | Trip / Energy / Maintenance / Odometer / Expense — see below | Save |
| **Vehicle sheet** | Create with four fields; edit every writable one, plus lifecycle, jurisdiction, [logbook mode](features.md#logbook-mode), the inspection interval, the reminder recipients and mail cadence, [access](#the-access-section); delete, undoably | Save |
| **Costs** | One year, one vehicle: stacked bars per month, table below, the CO₂ estimate, export button | Export |
| **Inbox** | The files in the person's inbox folder that belong to no vehicle yet, as thumbnails ([the Inbox screen](#the-inbox-screen)). Shown once a folder is chosen | Attach |
| **Reports** | Fahrtenbuch and mileage claim — pick a vehicle and a year, get a printable page ([ADR 0005](adr/0005-no-pdf-library.md)). Costs and CO₂ are not reports: they live on the Costs screen, and its CSV exports the rows behind the costs | Open logbook, Open mileage claim (the browser prints) |
| **Personal settings** | The defaults a person keeps: jurisdiction first, then "I reclaim VAT", then the grid factor for charging (empty for the country's average, which it names), then the inbox folder. Not a screen in the app: it is the app's block on Nextcloud's own settings page, its own bundle, and it talks to the same API as everything else | Pick and it saves |

**Reports** has two, each one vehicle and one year, opened as a page in a tab of its own: the
logbook ([export](architecture.md#the-fahrtenbuch-export)), a Fahrtenbuch in Germany and a plain one
under `generic`, and the [mileage claim](architecture.md#the-mileage-claim). Each button shows only
where its country prints it, because any other opens a 404. Sold vehicles are offered too: their
logbook is still kept after they leave the fleet. The year is last year's until the end of February,
when the reports people print are for the tax return, and this year's from March. Times the server
stamped — entered, changed, voided, the mode switched — print in the reader's time zone with the
offset written out, since the trips beside them carry their own.

**Costs** opens from *Costs* on the vehicle screen and goes back there; picking any vehicle in the
navigation leaves it. The year steps back one at a time and not past the current one. The bars are
hand-drawn SVG in three bands — energy, maintenance, expenses — because six are unreadable at
320 px; the table beneath breaks the expenses out by category and carries every figure, so the
drawing is hidden from screen readers. A month with no rows says so rather than showing zero.
Depreciation stays out of the bars: it is an estimate spread over the holding period, not a
month's spending, and it lives in the TCO tile.

The **Settings** entry in the sketch above is a link into that page, not a seventh screen. A
preference belongs to the person, so it lives where a person looks for their preferences.

### The entry sheet, in detail

This is the screen the app lives or dies by. One `+` opens one sheet, and a chooser at the top of it
picks which of five kinds is being entered — each with **one required field**. The chooser rather
than five buttons, because the kind is a decision the driver may change after seeing the fields, and
a phone has room for one sheet at a time. It opens on the trip, which is what a logbook is for:

- **Trip** — end odometer *or* distance, whichever the driver happens to know. Toggle between them.
  Giving the distance is not the same as giving the odometer, and the app does not pretend otherwise
  ([odometer rules](architecture.md#odometer-rules)). Neither counter is prefilled, and that is the
  one exception to the rule below: the counter a trip set off on is a *claim* about what the
  dashboard read, and filling it in from the vehicle would answer the question gap detection exists
  to ask. Under the start counter the sheet *offers* where the vehicle's last trip ended — "Last
  trip ended at 148 320" — and *Use it* takes it with one tap, which makes it the driver's word.
  The two counters are *Odometer at departure* and *Odometer at arrival*; a vehicle counted in
  hours has no odometer, so there they are *Start counter* and *End counter*.
  Both moments default to now, the departure to the last trip's arrival when that was today. The
  category is the one this person last used on this vehicle, else none: calling a trip business
  is the claim, and the app does not make it. Route, purpose and
  partner autocomplete from this vehicle's own history, which is what keeps six spellings of one
  client out of the reports. Starting point and destination offer the same places, because where
  one trip ended is where the next sets off. Before it sends, the sheet asks for an arrival after
  the departure, an end counter or a distance, and an end counter not below the start. The server
  refuses the backwards pair too, and an arrival more than a day ahead, each with a reason the
  sheet words.
- **Energy** — amount and total price, then the counter, read in that order at the pump. Taken only on a vehicle with `energy_types`, and only
  with those, so a plug-in hybrid can log either and a diesel is never asked. On a vehicle with
  none the kind is shown disabled and says to choose the energy under *Edit vehicle*; an import of
  fill-ups is refused in the same words
  ([data model](architecture.md#data-model)). Unit price is derived from the total. The station
  completes from this vehicle's history and prefills the price it last charged; that guess is
  sent only when there is no total, or the driver changed it. VAT is the jurisdiction's rate on
  the fill-up's day, asked again when the date moves, and clears to "not stated". "Full tank"
  defaults to on, because it usually is
  ([the maths](architecture.md#numbers-consumption-cost-emissions) needs it). The counter, as on a
  trip, is not prefilled; left empty, the field says consumption needs it. A charge asks home or
  public, and DC only for public.
- **Maintenance** — title and cost; the title is the one required field. Type is left empty until
  picked, because "service" is one kind of work, not all of it. The vendor completes from this
  vehicle's history. VAT and the counters work as on a fill-up, and date, VAT and counters carry
  over when the driver switches between the two. Under _Closes reminder_ the vehicle's open
  reminders are chips, most urgent first; one tap picks one, a second unpicks it. That is how
  recurrence stays correct without anyone thinking about it
  ([rule 4](architecture.md#reminder-engine)). Until somebody taps, the most urgent reminder is
  picked when the type is its kind of work — oil change and brake fluid are a service, a tyre swap
  is tyres, the HU/AU an inspection — and none otherwise. An edit opens on the reminder the record
  closed, listed even when that occurrence is over.
- **Odometer** — one number. The escape hatch for everything not otherwise recorded. On a vehicle
  that also counts engine hours, "Which counter" asks Kilometres or Engine hours first, and the
  number is prefilled from that counter. The timeline row states it in that counter's unit.
- **Expense** — the amount; category, VAT, notes and date beside it. Last in the chooser. No category until one is picked, as with a maintenance type. VAT works as on a
  fill-up, except that a category the jurisdiction charges no VAT on (in Germany insurance, vehicle
  tax and a fine) sets the prefilled rate to 0 and another one puts the day's rate back. A rate the driver typed stays.
  Date, VAT and notes carry over from the kind the driver switched from. It asks for no counter: insurance or a toll says nothing about the dashboard.

Rules for all five:

- Everything that can be prefilled **is** prefilled, and every prefilled value is visibly editable.
- Decimal fields use `inputmode="decimal"` and accept both `7,2` and `7.2`
  ([languages](#languages)), and a receipt's `1.234,56`: a point groups thousands where it cannot be
  the decimal mark. Whole counters use `inputmode="numeric"`, the number pad.
- The sheet never blocks on validation. An implausible odometer is saved and flagged
  ([data model](architecture.md#data-model)), never rejected — a driver at a petrol station will not
  debug a form. A trip that cannot have been driven is the exception — no arrival after the
  departure, no stated distance, or its own two counters running backwards: no sum can use it.
- **A failed save is never lost.** The sheet stays open with every value intact and offers retry.
  Nothing is written to `localStorage`: a durable queue would put destinations and purposes on a
  phone that may be shared or lost, to solve a problem the open sheet already solves. The driver
  loses a tap, and only if they close the tab. With no signal the sheet says so, and that what was
  typed is still there. A save whose answer was lost may have landed, so the
  sheet names the row before it sends it, and the retry is that row again, not a second one
  ([retried creates](api.md#retried-creates)). (The Android client will need a real offline queue.
  It will also need the clock handling in [time](architecture.md#time); neither is a v1 concern.)
- **A refused write is the one failure that is not about the values.** A save the server refused
  because the row moved on ([concurrency](architecture.md#concurrency)) leaves the sheet saying so,
  and the retry becomes _Save anyway_: it reads the vehicle or the Entry back and writes what is on
  screen under the token that came with it. What is on screen wins — the person looking at it is the one who
  knows whether the other change matters, and the message says that is what the button does.
- Saving returns to where you were, with a toast: "Saved.". An edit's toast offers *Undo*, which
  writes back what the Entry said when the sheet opened, under the token the edit answered with,
  and stays until it is answered. A new Entry's leaves after a few seconds: its row on the
  timeline is where it is taken back. Nothing asks "are you sure?"; `deleted_at`
  ([data model](architecture.md#data-model)) makes undo the cheaper pattern.
- **Tapping a timeline row opens the sheet on that Entry**, any kind, when the reader may change it
  ([screens follow the role](#screens-follow-the-role)). It keeps its kind — the
  chooser is gone — and opens with what the Entry says; a rate it was saved with, stated or not,
  stays. Save writes the whole Entry back; _Delete_ goes with the undo toast, and a trip under
  [logbook mode](features.md#logbook-mode) offers _Void trip_ instead, whose late edits the
  Fahrtenbuch lists. An Odometer Entry keeps the moment it was read at.
- **The undo outlives what it undoes.** Deleting a vehicle takes its screen with it, so the toast
  hangs in the app shell rather than under the screen that asked. It carries the token the delete
  answered with ([concurrency](architecture.md#concurrency)) and no clock: a way back that
  disappears on a timer is a time limit on the only way back there is. A refused undo says so and
  stops offering the click, because the row moved on and the next click would be refused the same
  way.

### The QR shortcut

A printed code in the glovebox opens the entry sheet with the vehicle already chosen. It removes
the only step that has nothing to do with the data — picking the car — and it is a page of code,
not a project.

*QR sticker* on the vehicle screen opens it in a dialog, black on white in both themes, with the
vehicle's name beneath. *Print* prints the sticker alone, about 5 cm wide. The code carries
`…/apps/nextfleet/?vehicle=<uuid>&entry=new`; the app drops `entry` from the address once it has
opened the sheet, so a reload does not open a second one. The code is drawn in the browser as an
SVG path by `uqr` (MIT), so no PHP dependency comes with it. What the code must not carry is in
[security](security.md#things-that-are-visible-in-the-real-world).

### The Access section

The last section of the Edit vehicle sheet, shown to the owner alone, since only the owner grants
([Vehicle Access](../CONTEXT.md)). It lists each grant with its name, whether it is an account or a
group, a role picker and *Remove*. Below, a search finds accounts and groups through core's
autocomplete, which applies the instance's rules on who may find whom. A role picker beside it
offers a driver first, because a car is lent to be driven. Like the recipients, each change is
written at once, not with *Save*. *Remove* asks once — "{name} loses access to this vehicle." —
with *Cancel* and *Remove access* in its place, because only the owner can grant again. A revoke may
take people off the recipients, so the sheet reads that list again. The first grant reads the
vehicle back, since its `ever_granted` changes (the Bookings section). It is never called "Share"
(CONTEXT.md).

A grantee gets a read-only version on the vehicle screen, under the name beside *Leave vehicle*,
since most grantees never open the sheet: *Who can see this vehicle* folds out the owner, each
account and each group, by name and role. A trip's purpose reaches all of them, so a driver should
know who they are. No uid is sent (an account without a display name still shows its uid as
one), and nothing there can be changed.

### The Bookings section

On the vehicle screen, between the due banner and the documents, because who has the car when is
what a shared car is checked for before it is driven. Only on a vehicle anybody else has or had
access to — the vehicle's `ever_granted`, the rule *Entered by* follows: alone with a car, there is
nobody to book against. It lists the coming bookings, then the earlier
ones — the last seven days' and any return still waiting for its trip, however old — each with its
span, booker, purpose and state. A car still out past its end stays among the coming ones, however
long ago: it has not been given back. A booking is no timeline row
([a booking is a plan](architecture.md#data-model)).

*Book* opens a small sheet: start, end and purpose. The start defaults to the next full hour and the
end to two hours later. A span another live booking holds is refused; the sheet stays open with
what was typed and names the booking in the way: "Booked by Anna, Fri 02/10, 14:00–18:00", or
"With Anna until Fri 02/10, 18:00" when that booking is out — or, once it is overdue, "Still with
Anna, booked Fri 02/10, 14:00–18:00". The weekday stands in for the year, since a booking is looked
at within days of it. An end before the start, or one gone by, is said in the sheet before anything
is sent. Any other refusal a person can run into is said in the reader's words; only one the bundle
does not know shows the server's.

*Change* opens the same sheet on the booking; a booking that moved on meanwhile is handled as in
every sheet ([the entry sheet](#the-entry-sheet-in-detail)). *Cancel booking* asks once, because a
cancel has no undo; focus moves to the question and back to the button, or to the row once the
booking is cancelled. Both show where the
booking's own `may` allows them. Each row button's label names the booking's span, since a screen
reader reads it out of its row.

*Take the car* on a booking the reader may check out, and *Return the car* on one that is out, open
the handover sheet: the counter, the tank or battery in percent, and a note. Taking it, the counter
is prefilled from the vehicle's, or from the one the car last came back at when that is further — no
Reading holds it until somebody logs that trip; giving it back, the field starts empty and says the counter the car
was taken at, because a prefilled one would be saved unread. A car still out with someone refuses
the check-out by name: "Still with Anna, booked Fri 02/10, 10:00–12:00". While no booking holds the
car this minute and none is out, *Take it now* opens the same sheet with one more field, *Back by*
(the next full hour plus two): it books from now to then and checks out — two requests, so a failed
check-out keeps the booking, its end fixed, and *Try again* takes the car under it. A booking whose handover is in
question says so on its row in words ("Counter below the one before", "Returned after the end");
nothing is refused for it. Handover photos are added in the documents section, *Belongs to* the
booking, and show as paperclips on its row.

Giving the car back opens the entry sheet on *Trip*, filled in from the handover: departure and
arrival, both counters, the booking's purpose. The category is left empty and the sheet asks for it,
because only the driver knows whether the drive was business; there is no kind to switch to. Saving
ties the trip to the booking. Closed unsaved, the returned booking offers *Log the trip* until it
has one; then *Show the trip* opens it in the entry sheet, for whoever its timeline row opens for,
and the row says "Trip logged" to everyone else. A voided trip stays tied, and the row says "Trip
voided" instead. Any entry-sheet save reads the bookings again, since a trip voided or restored
changes its booking's row.

**Who has the car** is said twice, so nobody has to open the section to ask. A car that is out says
"With Anna until Fri 02/10, 18:00" (or "With you") on its overview row, right after the light, and
under the name in the vehicle header; past its end, "With Anna, overdue since …" in the warning
colour. The header also names the reader's own next booking within seven days that is not taken
yet: "Your booking: Sat 03/10, 09:00–12:00". Both come from the vehicle's `out_with` and
`my_next_booking` ([architecture](architecture.md#data-model)); any write in the section reads the
vehicle again.

### The Inbox screen

A receipt photographed at the pump should reach its fill-up without the file picker. The person
chooses an *Inbox folder* on the personal settings page — Nextcloud's picker in folder mode, a
folder of their own — and points the mobile app's auto-upload at it
([the inbox](architecture.md#the-inbox)). From then on the navigation offers *Inbox* under
*Overview*, with the count of files waiting; without a folder there is no entry.

The screen is a grid of thumbnails, newest first, two across at 320 px: Nextcloud's own preview,
core's PDF icon for a PDF, the name and the day beneath. Past a hundred it says how many more wait.
A tap opens a small sheet: *Vehicle* (those the reader may `log` on, the one used last in this
session picked, else the first), *What it is* (*Receipt*), *Belongs to* and *Attach*. Someone
without `edit` must hang the paper on a row of their own, so their newest own entry is picked for
them. *Belongs to* searches as in the documents section. Either way the common case is two taps: the
file, then *Attach*. The attached file leaves the grid and the count. A paper attached, removed or
brought back in a documents section moves the count too.

A receipt for a cost not entered yet is logged from the file: *New fill-up* (on a vehicle that
names an energy), *New maintenance* or *New expense* opens the [entry sheet](#the-entry-sheet-in-detail) on that
kind for the vehicle picked, without the kind chooser, dated when the file was saved. Saving it
attaches the file to the new entry, as the kind chosen. If that attach fails, the entry stays and
the screen says so; the file stays in the grid, to be attached to the entry by hand.

Empty, the screen says where files come from. With no folder, or one deleted or shared since, it
says what to do and links to the settings page. Nothing on this screen moves, renames or deletes a
file.

### Importing

History from another tool arrives through the vehicle sheet: *Import from a file…*, for `edit` on a
vehicle that is not disposed ([import](architecture.md#import)). The import sheet takes the vehicle
sheet's place, so one dialog is open at a time; while the vehicle sheet holds unsaved changes the
button waits and says to save or cancel them first.

1. **The file.** Nextcloud's picker, CSV files only. Closing it closes everything; nothing is
   uploaded.
2. **The format.** One choice names the file and its record type: "CSV (LubeLogger format) — fuel",
   "CSV (Spritmonitor format) — costs", and so on. The units the file does not say come with it,
   preset to kilometres and litres: the distance for a file with a counter, the volume for fuel on a
   vehicle that takes more than electricity. *Preview*.
3. **The preview.** Counts in words ("New: 12. Already there: 3. Not readable: 1."), then only the
   questions the file leaves open, each a field: the order of slash dates, the energy of fill-ups
   that name none, and what each cost category text of a costs file means — an expense category, a
   maintenance type, or skip. A code the format names shows its default meaning already chosen. Rows already there are skipped unless a switch includes them. Below,
   which header became which field and which were not read, then the rows among the first fifty that
   will not become an entry, each with its reason. Every answer previews again. *Back* returns to
   the format and drops the answers.
4. **The import.** *Import* stays grey while a question is open or nothing would be created. A file
   changed since its preview is previewed again, and the sheet says so. Done, the sheet closes; the
   result is the undo toast: how many entries were imported, how many rows were skipped as already
   there, how many could not be read, and *Undo*. Undo takes back every entry of that import, or
   none; an entry edited since goes with the rest. Once any of them was deleted, the toast says so
   and drops *Undo*, and the rest are deleted one by one like any other. An import that created
   nothing offers no *Undo*.

A file the reader refuses — too large, not text, a broken quote — is named with its reason; so is a
file shared with the person rather than their own, and a picker that failed.

### Screens follow the role

A grantee sees the whole vehicle and only the buttons their role allows. The screen hides by `may`
and by nothing else: the vehicle carries the operations the session holds
([Vehicle Access](../CONTEXT.md)), and each timeline row carries `edit` and `delete` where the
reader may change that Entry. The server still refuses on its own; the screen just stops offering.

| Offered | Takes |
|---|---|
| *New entry*, `n`, the QR sticker's sheet, *QR sticker*, *Done* on a reminder, *Close gap*, *Add document* | `log` |
| *Book*, *Take it now* | `book`: `log` on a car in service |
| *Change*, *Cancel booking*, *Take the car*, *Return the car* or *Log the trip* on a booking | the booking's `edit`, `cancel`, `check_out`, `check_in` or `log_trip` |
| A vehicle in the inbox sheet, *New maintenance*, *New expense* | `log` |
| *New fill-up* in the inbox sheet | `log`, on a vehicle that names an energy |
| A booking under *Belongs to* | the booking's `attach` |
| An entry under *Belongs to* | the entry's `edit` |
| *The vehicle itself* under *Belongs to* | `edit` |
| *Remove* on a document | the document's `detach` |
| *Edit vehicle*, the reminder sheet, *+ Reminder*, the HU/AU question, the recipients, the complete-this-vehicle hint | `edit` |
| *Import from a file…* | `edit`, on a vehicle that is not disposed |
| A timeline row that opens its sheet | the row's `edit` |
| *Show the trip* on a booking | the booking's `open_trip`: the trip's `edit` |
| *Delete* or *Void trip* in that sheet | the row's `delete` |
| *Delete vehicle*, the Access section | `own` |

A row the reader may not change still says everything it says to anyone, and opens nothing; a
reminder row likewise. A viewer who scans the sticker lands on the vehicle without a sheet.

### Who entered it

Once anybody else was given access to a vehicle — a revoked grant counts, because the trips that
driver entered stay — every timeline row ends with "Entered by" and a display name, and the logbook
gains an *Eingetragen von* column ([logbook mode](features.md#logbook-mode)). Both read
`created_by`; there is no driver field. An erased account reads as its pseudonym. A vehicle nobody
else was given looks as it did before access existed.

### Leaving a vehicle

Under the vehicle's name, a grantee with a grant of their own sees *Leave vehicle*. It asks once,
because only the owner grants again, and there is no undo. One who reaches the vehicle through a
group reads which group and that only the owner can change it, with no button. The owner sees
neither. Leaving with no group left takes the vehicle out of the fleet, and the screen with it.
Leaving with a group left reads the timeline, the bookings and the documents again, so no row
offers what the group's role does not allow. The question takes focus, as *Cancel booking*'s does,
and after such a leave the words naming the group take it.

### Details that decide whether it feels easy

- **Ask for four fields, not twelve.** Creating a vehicle needs plate, make/model, engine and
  current km. The vehicle type and the counter unit sit beside the counter, prefilled with car and
  km, so they cost no answer; choosing a tractor or a generator switches the unit to hours, still
  changeable. VIN, first registration and the capacity of the energy the vehicle takes — a tank for
  a diesel, a battery for an electric one — arrive through a dismissible "complete this vehicle"
  hint on the overview. So does a currency, when the jurisdiction gave the vehicle none: without
  it the cost figures can only say "no currency". A twelve-field wall on first run loses people before they have a single
  record. The hint asks per vehicle and the vehicle's name in it opens the screen the edit sheet is
  on. Dismissing answers for that one vehicle, and it is kept with the person's preferences rather
  than in the browser: somebody who has said "not this one" is not asked again on their laptop.
  The energies are not asked either: the server takes them from the engine
  ([data model](architecture.md#data-model)), so the first fill-up needs no detour. A vehicle whose
  country has a logbook ruleset — Germany so far — and whose [logbook mode](features.md#logbook-mode)
  is off is asked once, in a card of its own, whether to keep a logbook for the tax office.
  Switching it on, dismissing, or flipping the mode in the edit sheet answers it, per vehicle and
  in the same preferences. There is no inspection date to ask for — the next inspection is a reminder
  ([architecture](architecture.md#data-model)).
- **The jurisdiction is not a fifth field.** It defaults from the user's personal setting and is
  changed in the vehicle's edit sheet ([contributing](contributing.md)). It decides units, currency and rules — and a freelancer's
  vehicles are all in one country, so asking would cost more than it is worth.
- **Switching [logbook mode](features.md#logbook-mode) off is the one thing that asks.** It is the
  one thing undo does not reach: switching it back on is a second flip in the audit trail, not a way
  back from the first. The switch itself is never blocked — it says what the driver last asked for —
  and the question stands between it and the save, which is when anything happens. Switching on asks
  nothing: it takes something on rather than away.
- **Empty states do the teaching.** Not "no entries" but the two buttons that create the first one,
  `NcEmptyContent` with a real call to action: the empty timeline offers *New entry* and *Import
  from a file…*, each to who may take it. A fleet still loading shows a spinner, never "No vehicles
  yet". The QR sticker is offered in the vehicle header,
  not yet in an empty state.
- **Numbers get context.** `6,4 l/100 km` alone means nothing; `6,4 l/100 km  +0,3 l/100 km vs. the
  period before` means something. Every KPI shows its comparison or its trend.
- **Status is never colour alone.** Traffic lights carry an icon and a word, for colour-blind users
  and for the print/export path.
- **Sort by urgency.** The overview is a to-do list, not an inventory. Alphabetical order is what a
  database returns, not what anyone wants. Vehicles that are `laid_up` sink; `disposed` ones leave
  the list entirely ([data model](architecture.md#data-model)).
- **Never sum across currencies or units.** A figure that spans vehicles is grouped and sectioned,
  never totalled, and every KPI takes its label from the vehicle — `€/100 km` for a car, `€/h` for a
  generator ([the maths](architecture.md#numbers-consumption-cost-emissions)).
- **One primary button per screen.** On the vehicle screen that is **+ Entry** — not "Edit
  vehicle", which people need twice a year.
- **Keyboard:** `n` starts the primary action of the screen in view — a new entry on a vehicle, a
  new vehicle on the overview. `Esc` closes the sheet — except in a date field, where it belongs to
  the picker the browser opened. The app has no search field of its own: a vehicle is found
  through Nextcloud's unified search and its shortcut ([integration](architecture.md#nextcloud-integration)).
- **Dark mode and 320 px width are acceptance criteria**, not afterthoughts; the timeline is rows,
  not cards, so it survives both.

## Languages

Both first-class from M1. Retrofitting i18n means touching every string in the app.

**Four German variants exist in Nextcloud, and we need two of them.** `de` is informal ("du"),
`de_DE` is formal ("Sie"). A fleet app is used in companies, so `de_DE` is the one that matters —
but shipping only `de` would give company users "du". Ship both, worded differently.

- **Marking strings:** PHP `IL10N->t()` / `->n()`, Vue `t('nextfleet', …)` from
  `src/utils/l10n.js`, which shows a placeholder's value as typed ([security](security.md)).
  Never assemble a sentence from fragments — German word order is not English word order. Use
  placeholders (`%1$s` in PHP, `{plate}` in JS) and the plural form for every count. The wrapper
  has no `n()` yet, since the screens word their counts without one: add it there, as `t()` is.
  Add `// TRANSLATORS:` notes where a string is ambiguous.
- **Files:** `l10n/en.json|js`, `l10n/de.json|js`, `l10n/de_DE.json|js`, edited by hand — no tool
  extracts them. Other languages by pull request.
- **Background jobs have no request locale.** A reminder mail or notification created by cron must
  use the *recipient's* language: `IFactory::getUserLanguage($uid)`, then
  `IFactory::get('nextfleet', $lang)`. Getting this wrong sends German mails to English users and is
  invisible in single-user testing.
- **Notifications translate late.** Store parameters in the notification, translate in
  `INotifier::prepare()`, which receives the language. Same for activity entries.
- **Enums are codes, never words.** `petrol`, `diesel`, `electric`, `hybrid` in the DB; labels come
  from l10n. ("Otto" is *petrol*/*gasoline* in English — not a word an English user recognises.)
- **HU/AU has no English equivalent.** Label it "Technical inspection (HU/AU)". Never "TÜV"
  ([licensing](legal.md)).
- **Formats follow the locale, not the language:** `1.234,5 km`, `12,34 €`, `03.09.2026` in German.
  Use `IL10N::l()` and `Intl.NumberFormat` — no hand-rolled formatting anywhere. `IL10N` formats
  no numbers, so a PHP page in the reader's locale prints them ungrouped (the generic logbook).
- **A document for an authority is in that authority's language, whoever prints it.** The Fahrtenbuch
  is German, dates, numbers and words alike, because the language of proceedings at a German tax
  office is German (§ 87 AO). Its words and formats are the country's layout
  (`lib/Jurisdiction/De/`), not strings for the catalogues.
- **Units stay metric in both languages.** l/100 km and kWh/100 km; English does not silently become
  mpg. Units follow the vehicle's jurisdiction ([contributing](contributing.md)), never a global
  switch.
- **App store metadata** is translatable too: `<name lang="de">`, `<description lang="de">` in
  `info.xml`.
- **CI check:** `tests/Unit/TranslationsTest.php` fails the build when a marked string is missing
  from one of the six files, when one holds a string nothing marks, when a `.js` lacks a
  translation its `.json` holds, or when `de_DE` says du or `de` says Sie. The reminder E2E (`m4-slice`) tells a recipient whose language is `de`, so
  the notifier's and the digest's late translation is checked in German.
