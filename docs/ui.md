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
`NcAppNavigation` on the left, `NcAppContent` in the middle, `NcAppSidebar` on the right — and the
standard components (`NcButton`, `NcListItem`, `NcEmptyContent`, `NcActions`, `NcDialog`). No custom
chrome, no bespoke tables, no second design language inside the page.

### Do not let the schema dictate the navigation

The obvious layout gives each table a tab: Trips, Energy, Maintenance, Expenses. That mirrors the
[data model](architecture.md#data-model) and answers no question a person actually asks. People ask
"what happened with this car?" and "what did March cost?".

So the vehicle has **one timeline** of everything — trips, fill-ups, maintenance, expenses,
reminders — newest first, with filter chips above it:
`All · Trips · Odometer · Energy · Maintenance · Costs`. One place to look, one place to search, and
the tabs collapse from six to three. A chip per kind of Entry, so the odometer has one too: a
counter somebody read is a thing that happened to the vehicle, even though the counter's own chain
is a different question and keeps its own route
([architecture](architecture.md#the-timeline)).

The timeline is also where flagged records get resolved. An odometer that went backwards shows the
follow-up question there — cluster swap, or a mistake? — instead of interrupting the person who
entered it ([odometer rules](architecture.md#odometer-rules)).

```
┌─ NextFleet ──────────────────────────────────────────────────────────┐
│ Overview        │  M-AB 1234 · VW Passat Variant          [+ Entry]  │
│                 │  148 320 km · 6,4 l/100 km · 42,10 €/100 km        │
│ ● M-AB 1234  ⚠  │  ┌────────────────────────────────────────────┐   │
│ ● HH-CD 42      │  │ ⚠ HU/AU due in 3 weeks — 24.09.2026        │   │
│ ● M-EV 7   ⛔   │  │ ● Oil change in ~2 400 km (est. Nov)        │   │
│                 │  └────────────────────────────────────────────┘   │
│ Reports         │                                                    │
│ ─────────────── │  Timeline [All][Trips][Odo.][Energy][Maint.][Cost] │
│ Settings        │  ───────────────────────────────────────────────   │
│                 │  03.09.  ⛽ Energy    48,2 l   82,10 €   6,1 l/100 │
│                 │  02.09.  🚗 Munich → Augsburg   82 km   business   │
│                 │  28.08.  🔧 Brake pads   Werkstatt Huber  312,00 € │
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

The timeline pages: 50 rows, then more on scroll, with the month a sticky header. Five years of a
company car is thousands of rows, and the screen people open most often is not the place to
discover that. The scroll is the bottom of the list coming into view; the button sitting there is
what a browser without an observer, and a page the server refused, still have.

Under Logbook Mode the month header also states the month's unaccounted kilometres
([logbook mode](features.md#logbook-mode)). It is the whole month's figure from the first page on,
never the sum of the rows scrolled in so far. The trip that opened a Gap says so on its row and
offers *Close gap*; the question names the kilometres and the two moments, and a confirmation writes
one private trip whose row reads *Reconciled*. The offer sits on the row, not on the header, because
a Gap is closed one at a time.

A row states one figure, and it is the one the driver gave — the kilometres a trip covered, or the
counter it ended on, never both and never one worked out from the other
([odometer rules](architecture.md#odometer-rules)). A fill-up states its amount, a maintenance
record its cost, an expense its amount. A flagged Reading is carried on the row it belongs to, as a
word rather than a colour, and so is what a fill-up is flagged for — no price, an energy the
vehicle does not take, more than it holds — and whether it was partial or missed the one before.
A fill-up that closes a full-to-full segment also states that segment's consumption, the one figure
on a row that is worked out rather than given.

The right sidebar holds the vehicle's own data and documents — the things you set once and rarely
touch. That keeps the middle free for the things you touch weekly.

### Screens

| Screen | Purpose | Primary action |
|---|---|---|
| **Overview** | All vehicles, sorted by urgency, not alphabetically. Traffic light, plate, km, next due. | Open a vehicle |
| **Vehicle** | Header KPIs + due banner + timeline (above) | **+ Entry** |
| **Entry sheet** | Trip / Energy / Maintenance / Odometer / Expense — see below | Save |
| **Vehicle sheet** | Create with four fields; edit every writable one, plus lifecycle, jurisdiction and [logbook mode](features.md#logbook-mode); delete, undoably | Save |
| **Costs** | One year, one vehicle: stacked bars per month, table below, export button | Export |
| **Reports** | Fahrtenbuch, mileage claim, cost, CO₂ — pick a range, get a printable page ([ADR 0005](adr/0005-no-pdf-library.md)) | Print / export |
| **Vehicle sidebar** | Master data, jurisdiction, documents, reminders (sharing from M6) | Edit inline |
| **Personal settings** | The defaults a person keeps: jurisdiction first, then "I reclaim VAT". Not a screen in the app: it is the app's block on Nextcloud's own settings page, its own bundle, and it talks to the same API as everything else | Pick and it saves |

**Reports** has one report so far, the Fahrtenbuch: one vehicle and one year, opened as a page in
a tab of its own ([export](architecture.md#the-fahrtenbuch-export)). It offers only vehicles whose
country prints a logbook, because any other opens a 404. Sold vehicles are offered too: their
logbook is still kept after they leave the fleet.

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
  to ask. Both moments default to now; route, purpose and
  partner autocomplete from this vehicle's own history, which is what keeps six spellings of one
  client out of the reports.
- **Energy** — amount and total price. Offered only on a vehicle with `energy_types`, and only
  with those, so a plug-in hybrid can log either and a diesel is never asked
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
  over when the driver switches between the two. If a reminder is open for this vehicle, offer it
  as one tap: "Closes: Oil change" — that is how recurrence stays correct without anyone thinking
  about it (M4).
- **Odometer** — one number. The escape hatch for everything not otherwise recorded. On a vehicle
  that also counts engine hours, "Which counter" asks Kilometres or Engine hours first, and the
  number is prefilled from that counter. The timeline row states it in that counter's unit.
- **Expense** — the amount; category, VAT, notes and date beside it. Last in the chooser. No category until one is picked, as with a maintenance type. VAT works as on a
  fill-up, except that a category the jurisdiction charges no VAT on (in Germany insurance, vehicle
  tax and a fine) empties the prefilled rate to "not stated" and another one puts it back. A rate the driver typed stays.
  Date, VAT and notes carry over from the kind the driver switched from. It asks for no counter: insurance or a toll says nothing about the dashboard.

Rules for all four:

- Everything that can be prefilled **is** prefilled, and every prefilled value is visibly editable.
- Numeric fields use `inputmode="decimal"` and accept both `7,2` and `7.2`
  ([languages](#languages)).
- The sheet never blocks on validation. An implausible odometer is saved and flagged
  ([data model](architecture.md#data-model)), never rejected — a driver at a petrol station will not
  debug a form.
- **A failed save is never lost.** The sheet stays open with every value intact and offers retry.
  Nothing is written to `localStorage`: a durable queue would put destinations and purposes on a
  phone that may be shared or lost, to solve a problem the open sheet already solves. The driver
  loses a tap, and only if they close the tab. (The Android client will need a real offline queue.
  It will also need the clock handling in [time](architecture.md#time); neither is a v1 concern.)
- **A refused write is the one failure that is not about the values.** A save the server refused
  because the row moved on ([concurrency](architecture.md#concurrency)) leaves the sheet saying so,
  and the retry becomes _Save anyway_: it reads the vehicle or the Entry back and writes what is on
  screen under the token that came with it. What is on screen wins — the person looking at it is the one who
  knows whether the other change matters, and the message says that is what the button does.
- Saving returns to where you were, with an undo toast. Nothing asks "are you sure?"; `deleted_at`
  ([data model](architecture.md#data-model)) makes undo the cheaper pattern.
- **Tapping a timeline row opens the sheet on that Entry**, any kind. It keeps its kind — the
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
  There is no inspection date to ask for — the next inspection is a reminder
  ([architecture](architecture.md#data-model)).
- **The jurisdiction is not a fifth field.** It defaults from the user's personal setting and is
  changed in the vehicle's edit sheet, moving to the sidebar when that exists
  ([contributing](contributing.md)). It decides units, currency and rules — and a freelancer's
  vehicles are all in one country, so asking would cost more than it is worth.
- **Switching [logbook mode](features.md#logbook-mode) off is the one thing that asks.** It is the
  one thing undo does not reach: switching it back on is a second flip in the audit trail, not a way
  back from the first. The switch itself is never blocked — it says what the driver last asked for —
  and the question stands between it and the save, which is when anything happens. Switching on asks
  nothing: it takes something on rather than away.
- **Empty states do the teaching.** Not "no entries" but the two buttons that create the first one,
  plus the QR offer. `NcEmptyContent` with a real call to action.
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
  the picker the browser opened. `/` focuses search once search exists (M5).
- **Dark mode and 320 px width are acceptance criteria**, not afterthoughts; the timeline is rows,
  not cards, so it survives both.

### Fleet view (M6+)

One table, all vehicles, columns for status, km, cost/km, next due, current driver. Sortable,
filterable by group. This is the manager's screen and it is the one place density beats simplicity.

## Languages

Both first-class from M1. Retrofitting i18n means touching every string in the app.

**Four German variants exist in Nextcloud, and we need two of them.** `de` is informal ("du"),
`de_DE` is formal ("Sie"). A fleet app is used in companies, so `de_DE` is the one that matters —
but shipping only `de` would give company users "du". Ship both, worded differently.

- **Marking strings:** PHP `IL10N->t()` / `->n()`, Vue `t('nextfleet', …)` / `n()` from
  `@nextcloud/l10n`. Never assemble a sentence from fragments — German word order is not English
  word order. Use placeholders (`%1$s` in PHP, `{plate}` in JS) and the plural form for every count.
  Add `// TRANSLATORS:` notes where a string is ambiguous.
- **Files:** `l10n/en.json|js`, `l10n/de.json|js`, `l10n/de_DE.json|js`, extracted with the
  Nextcloud translation tool. Maintained in-repo by hand; other languages by pull request.
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
  Use `IL10N::l()` and `Intl.NumberFormat` — no hand-rolled formatting anywhere.
- **A document for an authority is in that authority's language, whoever prints it.** The Fahrtenbuch
  is German, dates, numbers and words alike, because the language of proceedings at a German tax
  office is German (§ 87 AO). Its words and formats are the country's layout
  (`lib/Jurisdiction/De/`), not strings for the catalogues.
- **Units stay metric in both languages.** l/100 km and kWh/100 km; English does not silently become
  mpg. Units follow the vehicle's jurisdiction ([contributing](contributing.md)), never a global
  switch.
- **App store metadata** is translatable too: `<name lang="de">`, `<description lang="de">` in
  `info.xml`.
- **CI check:** every extracted string must have an entry in all three catalogues; a missing one
  fails the build. One E2E run with the user language set to `de_DE`.
