# Interface and languages

How the screens work, and why.
The user's view of the same screens is the [user manual](user/README.md).
The mockups are in [`design/`](../design/).

## Interface

### Two moments, two interfaces

**Entering** happens at the pump, one-handed, on a phone, in half a minute, often with bad reception.
It wants a few large fields and no navigation.

**Reviewing** happens at a desk: what did this car cost, what is due, where did March go.
It wants density, tables and filters.

One screen for both is how logbooks die.
So entry is mobile-first, in a sheet you reach from anywhere.
Review is desktop-first, in the app shell.

### Look like Nextcloud, not like fleet software

"Intuitive" means the app looks like Files, Deck and Calendar, because the user already knows them.
Use the standard shell (`NcAppNavigation`, `NcAppContent`) and the standard components (`NcButton`, `NcListItem`, `NcEmptyContent`, `NcActions`, `NcDialog`).
No custom chrome, no second design language.

### One timeline, not a tab per table

A tab per table (Trips, Energy, Maintenance, Expenses) mirrors the [data model](architecture.md#data-model) and answers no question people ask.
They ask "what happened with this car?" and "what did March cost?".

So a vehicle has **one timeline** of everything, newest first, with filter chips: `All · Trips · Odometer · Energy · Maintenance · Expenses` ([the timeline](architecture.md#the-timeline)).
Flagged records are resolved on their own row: a counter that went backwards asks "Was the counter replaced, or is this a typo?" there, and does not interrupt the person who entered it.

```
┌─ NextFleet ──────────────────────────────────────────────────────────┐
│ Overview        │  M-AB 1234   [New entry][Costs][Edit][QR sticker]  │
│ Inbox       (3) │  VW Passat Variant                                 │
│                 │  148 320 km · 6,4 l/100 km · 42,10 €/100 km        │
│ M-AB 1234       │  ┌────────────────────────────────────────────┐   │
│ VW Passat Vari… │  │ ● Coming up · HU/AU · due 24.09.2026 [Done]│   │
│ HH-CD 42        │  │ ● Planned · Oil change · ~02.11.2026 [Done]│   │
│ Blue van        │  └────────────────────────────────────────────┘   │
│                 │  Bookings [Book][Take it now]                      │
│ Reports         │  Fr., 02.10., 14:00–18:00  Anna  Booked  [Change]  │
│ ─────────────── │                                                    │
│ Settings        │  Documents [Add document]                          │
│                 │  Fahrzeugschein.pdf                        Remove  │
│                 │                                                    │
│                 │  Timeline [All][Trips][Odo.][Energy][Maint.][Exp.] │
│                 │  03.09.  ⛽ Energy    48,2 l   82,10 €   6,1 l/100 │
│                 │  02.09.  🚗 Munich → Augsburg   82 km   business   │
│                 │  28.08.  🔧 Brake pads 📎 Werkstatt Huber 312,00 € │
└─────────────────┴────────────────────────────────────────────────────┘
```

**The navigation** names each vehicle in two lines: the plate in bold, then the display name, or else make, model and body type.
Without a plate, the display name moves up.

**The header** states the odometer and the period's figures: consumption per energy, cost, energy-only cost and TCO ([the maths](architecture.md#numbers-consumption-cost-emissions)).
The period is the last 12 months by default.
Each figure is compared with the period before as a signed difference, not a colour.
The client names the period, because a year starts at midnight where the person is.
The period and "I reclaim VAT" are preferences, so the next machine opens on the same figures.
A tile that the data can never fill says what it needs instead.

**The due banner** sits between the header and the timeline.
A reminder has not happened yet, so it is never a timeline row.
It lists the open reminders, most urgent first, each with its state in a word.
*Done* opens the entry sheet on a maintenance record for that reminder.
Where the country requires an inspection and none is set, the banner asks "When is the next HU/AU?": the month and year on the sticker.

**The timeline** pages 50 rows at a time, with the month as a sticky header.
Five years of a company car is thousands of rows.
At most four pages stay on screen, and *Show newer entries* brings older pages back from memory.
Under [Logbook mode](architecture.md#logbook-mode), each month header also states its unaccounted kilometres, for the whole month from the first page on.

**A row states the figure the driver gave**: a trip's distance or its end counter, never both and never one worked out from the other ([odometer rules](architecture.md#odometer-rules)).
Flags are words, not colours.
The one figure worked out is a full fill-up's consumption.

**The vehicle's settings live in the edit sheet**, not on the screen.
That keeps the screen free for what you touch weekly.
Documents are a section of their own above the timeline ([documents](architecture.md#documents)).

### Screens

Drawn in [design/components.md#frontend](design/components.md#frontend).

| Screen | Purpose | Primary action |
|---|---|---|
| **Overview** | All vehicles, sorted by urgency: laid-up ones last, then by the most urgent reminder. A traffic light with its word. A shared vehicle says "Owned by". A car that is out says who has it. Disposed vehicles in a list below. | Open a vehicle |
| **Vehicle** | Header, due banner, bookings, documents, timeline | **New entry** |
| **Entry sheet** | Trip, Energy, Maintenance, Odometer or Expense ([below](#the-entry-sheet-in-detail)) | Save |
| **Vehicle sheet** | Create with a few fields; edit everything, the [access](#the-access-section) included | Save |
| **Costs** | One vehicle, one year: bars per month, a table, the CO₂ estimate, CSV export | Export |
| **Inbox** | The receipts waiting in the inbox folder ([below](#the-inbox-screen)) | Attach |
| **Reports** | The logbook and the mileage claim, as a page to print ([ADR 0005](adr/0005-no-pdf-library.md)) | Open |
| **Personal settings** | Country, "I reclaim VAT", grid factor, inbox folder. A block on Nextcloud's settings page, with its own bundle. | Pick, it saves |

**Reports** offers each button only where the country prints it, because any other opens a 404.
Sold vehicles are offered too.
The year is last year's until the end of February, when people print for the tax return.

**Costs** draws three bands in hand-made SVG: six are unreadable at 320 px.
The table carries every figure, so the drawing is hidden from screen readers.
Depreciation stays out of the bars: it is an estimate over the whole holding, not a month's spending.

### The entry sheet, in detail

The app lives or dies by this screen.
One `+` opens one sheet.
A chooser at the top picks one of five kinds, each with **one required field**.
It opens on the trip, because a logbook is for trips.

- **Trip**: end counter *or* distance, whichever the driver knows.
  **Neither counter is prefilled.**
  The start counter is a claim about what the dashboard read, and filling it in would answer the question gap detection asks.
  The sheet *offers* where the last trip ended, and *Use it* makes it the driver's word.
  The category is the one this person last used here, else none: calling a trip business is the claim, and the app does not make it.
  Places, purposes and partners complete from this vehicle's history, which keeps six spellings of one client out of the reports.
- **Energy**: amount and total price, then the counter, in the order you read them at the pump.
  Only the vehicle's `energy_types` are offered.
  "Full tank" is on by default, because it usually is.
  VAT is the country's rate on that day.
- **Maintenance**: the title is required.
  *Closes reminder* offers the open reminders as chips and picks the most urgent one that matches the type.
  That keeps recurrence right without anyone thinking about it ([rule 4](architecture.md#reminder-engine)).
- **Odometer**: one number.
  The escape hatch for everything else.
- **Expense**: the amount.
  A category the country charges no VAT on sets the rate to 0.

Rules for all five:

- Everything that can be prefilled **is**, and every prefilled value is visibly editable.
- Decimal fields take `7,2`, `7.2` and a receipt's `1.234,56` ([languages](#languages)).
- **The sheet never blocks on validation.**
  An odd counter is saved and flagged.
  A driver at a pump will not debug a form.
  Only a trip that cannot have been driven is refused.
- **A failed save is never lost.**
  The sheet stays open with every value and offers a retry.
  Nothing goes to `localStorage`: a queue would put destinations and purposes on a phone that may be shared or lost.
  The retry sends the same row again, not a second one ([retried creates](api.md#retried-creates)).
- **A refused write offers _Save anyway_.**
  When the row moved on ([concurrency](architecture.md#concurrency)), the sheet says so.
  *Save anyway* reads the row back and writes what is on screen under the new token.
  The person looking at it knows whether the other change matters.
- **Undo, not "are you sure?".**
  Saving returns to where you were with a toast.
  An edit's toast offers *Undo*.
  `deleted_at` makes undo the cheaper pattern.
- **Tapping a row opens it** in the same sheet, for whoever may change it.
  Under Logbook mode a trip offers *Void trip* instead of *Delete*.
- **The undo outlives what it undoes.**
  The toast hangs in the app shell and carries no timer: a way back that disappears on a timer is a time limit on the only way back.

### The QR shortcut

A printed code in the glove box opens the entry sheet for that vehicle.
It removes the one step that has nothing to do with the data: picking the car.

*QR sticker* shows it, black on white, about 5 cm wide in print.
The code is `…/apps/nextfleet/?vehicle=<uuid>&entry=new`.
The app drops `entry` after it opens the sheet, so a reload does not open a second one.
`uqr` (MIT) draws it in the browser.
The code is no credential ([security](security.md#things-that-are-visible-in-the-real-world)).

### The Access section

The last section of the edit sheet, for the owner alone.
It lists each grant with a role picker and *Remove*.
A search finds accounts and groups through core's autocomplete, which applies the instance's rules on who may find whom.
The role picker offers driver first: a car is lent to be driven.
Each change saves at once.
*Remove* asks once, because only the owner can grant again.
It is never called "Share" ([CONTEXT.md](../CONTEXT.md)).

A grantee sees *Who can see this vehicle* on the vehicle screen, read-only, without uids.
A trip's purpose reaches all of them, so a driver should know who they are.

### The Bookings section

Between the due banner and the documents, because who has the car when is what a shared car is checked for.
It shows only once anybody else has or had access (`ever_granted`): alone with a car, there is nobody to book against.

- *Book* asks start, end and purpose.
  A clash keeps the sheet open and names the booking in the way: "Booked by Anna, Fri 02/10, 14:00–18:00", or "With Anna until …" when it is out.
- *Cancel booking* asks once, because a cancel has no undo.
- *Take the car* and *Return the car* ask the counter, the tank or battery level, and a note.
  On return the counter starts empty, because a prefilled one would be saved unread.
- *Take it now* books from now and checks out in one step.
- Returning opens the entry sheet on a trip filled in from the handover.
  The category stays empty: only the driver knows whether the drive was business.

**Who has the car** shows twice, so nobody has to open the section: on the overview row and under the name in the header.
Overdue shows in the warning colour.

### The Inbox screen

The person picks an inbox folder in the settings and points the phone's auto-upload at it ([the inbox](architecture.md#the-inbox)).
The navigation then shows *Inbox* with a count.

The screen is a grid of thumbnails, newest first.
A tap opens a small sheet: vehicle, what it is, *Belongs to*, *Attach*.
The common case is two taps.
*New fill-up*, *New maintenance* or *New expense* opens the entry sheet and attaches the file to the new entry.
Nothing here moves, renames or deletes a file.

### Importing

From the vehicle sheet, *Import from a file…* ([import](architecture.md#import)).
The import sheet takes the vehicle sheet's place, so one dialog is open at a time.

1. **The file**, from Nextcloud's picker.
   Nothing is uploaded.
2. **The format**: one choice names the file and its record type, such as "CSV (Spritmonitor format) — costs".
3. **The preview**: counts in words ("New: 12. Already there: 3. Not readable: 1."), then only the questions the file leaves open.
   Every answer previews again.
4. **The import**: the result is the undo toast.
   *Undo* takes back the whole import or nothing.

### Screens follow the role

A grantee sees the whole vehicle and only the buttons their role allows.
The screen hides by `may` and nothing else; the server still refuses on its own.
Drawn in [design/context.md#by-role](design/context.md#by-role).

| Offered | Takes |
|---|---|
| *New entry*, `n`, the QR sticker, *Done* on a reminder, *Close gap*, *Add document* | `log` |
| *Book*, *Take it now* | `book`: `log` on a car in service |
| *Change*, *Cancel booking*, *Take the car*, *Return the car*, *Log the trip* | the booking's own `may` |
| *Edit vehicle*, the reminder sheet, the recipients, the HU/AU question | `edit` |
| *Import from a file…* | `edit`, on a vehicle not disposed of |
| A timeline row that opens | the row's `edit` |
| *Delete* or *Void trip* | the row's `delete` |
| *Delete vehicle*, the Access section | `own` |

A row the reader may not change still says everything, and opens nothing.

### Who entered it

Once anybody else was given access to a vehicle, every row ends with "Entered by", and the logbook gains an *Eingetragen von* column.
Both read `created_by`; there is no driver field.
A revoked grant counts, because that driver's trips stay.
A vehicle nobody else was given looks as before.

### Leaving a vehicle

A grantee with a grant of their own sees *Leave vehicle* under the name.
It asks once, because only the owner grants again.
Access through a group cannot be left: the screen names the group instead.

### Details that decide whether it feels easy

- **Ask for five fields, not twelve.**
  Creating a vehicle asks plate, display name, make, model, engine and counter.
  The rest arrives through a dismissible "complete this vehicle" hint.
  A twelve-field wall on first run loses people before their first record.
  The energies are not asked either: the server takes them from the engine.
- **The country is not asked.**
  It comes from the personal setting and changes in the edit sheet.
  A freelancer's vehicles are all in one country.
- **Switching Logbook mode off is the one thing that asks.**
  Undo does not reach it: switching back on is a second flip, not a way back.
  Switching on asks nothing.
- **Empty states teach.**
  The empty timeline offers *New entry* and *Import from a file…*.
  A list still loading shows a spinner, never "No vehicles yet".
- **Numbers get context.**
  `6,4 l/100 km` alone means nothing.
  Every figure shows its comparison.
- **Status is never colour alone.**
  A traffic light has an icon and a word.
- **Sort by urgency.**
  The overview is a to-do list, not an inventory.
- **Never sum across currencies or units.**
  Labels come from the vehicle: `€/100 km`, `€/h`.
- **One primary button per screen.**
  On the vehicle screen it is **New entry**, not *Edit vehicle*, which people need twice a year.
- **Keyboard:** `n` starts the screen's primary action.
  `Esc` closes the sheet, except in a date field, where it belongs to the browser's picker.
  A vehicle is found through Nextcloud's unified search ([integration](architecture.md#nextcloud-integration)).
- **Dark mode and 320 px width are acceptance criteria.**
  The timeline is rows, not cards, so it survives both.

## Languages

English and German from the start.
Retrofitting i18n means touching every string.

**Two German variants.**
`de` is informal ("du"), `de_DE` formal ("Sie").
Companies use the app, so `de_DE` matters most, but shipping only `de` would say "du" to company users.
Ship both, worded differently.

- **Mark every string:** PHP `IL10N->t()` and `->n()`, Vue `t('nextfleet', …)` from `src/utils/l10n.js`, which shows a placeholder's value as typed ([security](security.md)).
  Never build a sentence from fragments: German word order is not English word order.
  Use placeholders and a plural form for every count.
  Add `// TRANSLATORS:` notes where a string is ambiguous.
- **Files:** `l10n/en`, `l10n/de` and `l10n/de_DE`, `.json` and `.js` each, edited by hand.
- **Background jobs have no request locale.**
  A mail or notification from cron uses the *recipient's* language (`IFactory::getUserLanguage($uid)`).
  Getting this wrong sends German mail to English users, and single-user testing never shows it.
- **Notifications translate late,** in `INotifier::prepare()`, which receives the language.
- **Enums are codes, never words:** `petrol`, `diesel`, `electric` in the database, labels from l10n.
- **HU/AU has no English word.**
  Say "Technical inspection (HU/AU)".
  Never "TÜV" ([legal](legal.md)).
- **Formats follow the locale, not the language:** `1.234,5 km` and `03.09.2026` in German.
  Use `IL10N::l()` and `Intl.NumberFormat`, never hand-made formatting.
- **A document for an authority is in that authority's language.**
  The Fahrtenbuch is German whoever prints it, because a German tax office works in German (§ 87 AO).
  Its words live in `lib/Jurisdiction/De/`, not in the catalogues.
- **Units stay metric in both languages.**
  English does not quietly become mpg.
  Units follow the vehicle's country ([contributing](contributing.md)).
- **CI checks it.**
  `TranslationsTest` fails when a marked string is missing from a file, when a file holds a string nothing marks, or when `de_DE` says "du" or `de` says "Sie".

Next: [The OCS API](api.md)
