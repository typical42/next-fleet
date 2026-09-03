# Interface and languages

Part of the [NextFleet plan](../plan.md).

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

The obvious layout gives each table a tab: Trips, Fuel, Service, Expenses. That mirrors the
[data model](architecture.md#data-model) and answers no question a person actually asks. People ask
"what happened with this car?" and "what did March cost?".

So the vehicle has **one timeline** of everything — trips, fill-ups, services, expenses, reminders
— newest first, with filter chips above it: `All · Trips · Fuel · Service · Costs`. One place to
look, one place to search, and the tabs collapse from six to three.

```
┌─ NextFleet ──────────────────────────────────────────────────────────┐
│ Overview        │  M-AB 1234 · VW Passat Variant          [+ Entry]  │
│                 │  148 320 km · 6,4 l/100 km · 0,42 €/km             │
│ ● M-AB 1234  ⚠  │  ┌────────────────────────────────────────────┐   │
│ ● HH-CD 42      │  │ ⚠ HU/AU due in 3 weeks — 24.09.2026        │   │
│ ● M-EV 7   ⛔   │  │ ● Oil change in ~2 400 km (est. Nov)        │   │
│                 │  └────────────────────────────────────────────┘   │
│ Reports         │                                                    │
│ ─────────────── │  Timeline    [All][Trips][Fuel][Service][Costs]    │
│ Settings        │  ───────────────────────────────────────────────   │
│                 │  03.09.  ⛽ Fill-up   48,2 l   82,10 €   6,1 l/100 │
│                 │  02.09.  🚗 Munich → Augsburg   82 km   business   │
│                 │  28.08.  🔧 Brake pads   Werkstatt Huber  312,00 € │
└─────────────────┴────────────────────────────────────────────────────┘
```

The timeline pages: 50 rows, then more on scroll, with the month a sticky header. Five years of a
company car is thousands of rows, and the screen people open most often is not the place to
discover that.

The right sidebar holds the vehicle's own data and documents — the things you set once and rarely
touch. That keeps the middle free for the things you touch weekly.

### Screens

| Screen | Purpose | Primary action |
|---|---|---|
| **Overview** | All vehicles, sorted by urgency, not alphabetically. Traffic light, plate, km, next due. | Open a vehicle |
| **Vehicle** | Header KPIs + due banner + timeline (above) | **+ Entry** |
| **Entry sheet** | Trip / Fuel / Service / Odometer — see below | Save |
| **Costs** | One year, one vehicle: stacked bars per month, table below, export button | Export |
| **Reports** | Fahrtenbuch, mileage claim, fleet cost, CO₂ — pick range, get a file | Generate |
| **Vehicle sidebar** | Master data, documents, reminders, sharing | Edit inline |

### The entry sheet, in detail

This is the screen the app lives or dies by. One `+` opens four choices, each a sheet, each with
**one required field**:

- **Trip** — end odometer *or* distance, whichever the driver happens to know. Toggle between them;
  the app computes the other. Start odometer is prefilled from the last reading, date from now,
  route from the last trips as suggestions.
- **Fill-up** — litres and total price. Unit price is derived, and the station remembers its last
  price. "Full tank" defaults to on, because it usually is
  ([the maths](architecture.md#numbers-consumption-cost-emissions) needs it).
- **Service** — title and cost. If a reminder is open for this vehicle, offer it as one tap:
  "Closes: Oil change" — that is how recurrence stays correct without anyone thinking about it.
- **Odometer** — one number. The escape hatch for everything not otherwise recorded.

Rules for all four:

- Everything that can be prefilled **is** prefilled, and every prefilled value is visibly editable.
- Numeric fields use `inputmode="decimal"` and accept both `7,2` and `7.2`
  ([languages](#languages)).
- The sheet never blocks on validation. An implausible odometer is saved and flagged
  ([data model](architecture.md#data-model)), never rejected — a driver at a petrol station will not
  debug a form.
- **A failed save is queued, not lost.** The sheet writes to `localStorage` and retries; the
  client-generated `uuid` ([stack and layers](architecture.md#stack-and-layers)) makes the replay
  idempotent. Otherwise "works with bad reception" above is a promise the architecture does not
  keep — and an underground car park is exactly where fill-ups happen.
- Saving returns to where you were, with an undo toast. Nothing asks "are you sure?"; `deleted_at`
  ([data model](architecture.md#data-model)) makes undo the cheaper pattern.

### The QR shortcut

A printed code in the glovebox opens the entry sheet with the vehicle already chosen. It removes
the only step that has nothing to do with the data — picking the car — and it is a page of code,
not a project.

### Details that decide whether it feels easy

- **Ask for four fields, not twelve.** Creating a vehicle needs plate, make/model, engine and
  current km. Tank size, VIN, first registration and inspection date arrive through a dismissible
  "complete this vehicle" hint on the overview. A twelve-field wall on first run loses people
  before they have a single record.
- **Empty states do the teaching.** Not "no entries" but the two buttons that create the first one,
  plus the QR offer. `NcEmptyContent` with a real call to action.
- **Numbers get context.** `6,4 l/100 km` alone means nothing; `6,4 l/100 km  ▲ 0,3 vs. average`
  means something. Every KPI shows its comparison or its trend.
- **Status is never colour alone.** Traffic lights carry an icon and a word, for colour-blind users
  and for the print/export path.
- **Sort by urgency.** The overview is a to-do list, not an inventory. Alphabetical order is what a
  database returns, not what a fleet manager wants.
- **One primary button per screen.** On the vehicle screen that is **+ Entry** — not "Edit
  vehicle", which people need twice a year.
- **Keyboard:** `n` new entry, `/` search, `Esc` closes the sheet.
- **Dark mode and 320 px width are acceptance criteria**, not afterthoughts; the timeline is rows,
  not cards, so it survives both.

### Fleet view (phase 2)

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
  `INotifier::prepare()`, which receives the language. Same for activity entries and calendar event
  summaries.
- **Enums are codes, never words.** `petrol`, `diesel`, `electric`, `hybrid` in the DB; labels come
  from l10n. ("Otto" is *petrol*/*gasoline* in English — not a word an English user recognises.)
- **HU/AU has no English equivalent.** Label it "Technical inspection (HU/AU)". Never "TÜV"
  ([licensing](legal.md)).
- **Formats follow the locale, not the language:** `1.234,5 km`, `12,34 €`, `03.09.2026` in German.
  Use `IL10N::l()` and `Intl.NumberFormat` — no hand-rolled formatting anywhere.
- **Units stay metric in both languages.** l/100 km and kWh/100 km; English does not silently become
  mpg. Units follow the vehicle's jurisdiction ([contributing](contributing.md)), never a global
  switch.
- **App store metadata** is translatable too: `<name lang="de">`, `<description lang="de">` in
  `info.xml`.
- **CI check:** every extracted string must have an entry in all three catalogues; a missing one
  fails the build. One E2E run with the user language set to `de_DE`.
