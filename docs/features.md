# Features

Part of the [NextFleet plan](../plan.md). Terms are defined in [CONTEXT.md](../CONTEXT.md).

## What existing tools teach us

- [LubeLogger](https://lubelogger.com/) — the closest reference. Record types: service, repair,
  upgrade, fuel, odometer, tax, supplies, inspection, notes, plans. Reminders trigger on **date, or
  odometer, or whichever comes first**, and can auto-recur. Copy this model.
- [Drivvo](https://www.drivvo.com/en-US/) — strong on cost reporting: cost per km, per category,
  monthly trend. Copy the reporting angle, not the ad-driven cloud.
- Nextcloud already has [Car Fuel & Maintenance](https://apps.nextcloud.com/apps/carfuelmaintance)
  and [Vehicle Manager](https://github.com/jonathan-berthet/Nextcloud-VehicleManager). Both are
  single-user logbooks. Neither does calendar-backed reminders nor a tax-grade Fahrtenbuch.

**Our differentiator** is the freelancer's paperwork ([who it is for](../plan.md#who-it-is-for)):
a defensible logbook, gap detection, a mileage claim — on top of deep Nextcloud integration
(calendar, notifications, files, search, dashboard). The two existing apps cover the fuel log; that
is not the reason anyone would switch.

## Feature backlog

Ranked by value per effort. **v1** marks what M0–M5 ships ([milestones](../plan.md#milestones));
everything else waits.

**High**

- **v1** — Energy log with l/100 km, kWh/100 km, cost per km, cost trend.
- **v1** — Cost dashboard and CSV export per vehicle and per year.
- **v1** — Recurring intervals (see [reminder engine](architecture.md#reminder-engine)) with a
  template set: oil, brake fluid, HU/AU, tyre swap.
- **v1** — Documents: Fahrzeugschein, insurance policy, manual, receipts — linked Nextcloud files.
- **v1** — Fahrtenbuch export with the business/private/commute split, as HTML the browser prints
  ([ADR 0005](adr/0005-no-pdf-library.md)).
- **v1** — **Gap detection.** A Fahrtenbuch must be gapless, and the app knows the kilometres
  between the counter before a trip and the counter the trip claims to start on. Show unaccounted km per month and close them one
  at a time ([logbook mode](#logbook-mode)). Nothing in [the prior art](#what-existing-tools-teach-us)
  does this, and it is exactly what an audit looks for.
- **v1** — **QR sticker per vehicle.** A printed code for the glovebox; scanning opens the quick-add
  form for *that* car. It removes the one step that makes people skip logging — picking the vehicle.
  Best adoption-per-line-of-code in the list.
- **Mileage expense report.** Business trips × the statutory rate → a Reisekosten claim. For
  freelancers this is the whole reason to keep a logbook, so it ships in v1 wherever the
  jurisdiction supplies a rate; under the generic profile it is unavailable rather than zero.
- **Receipt inbox.** The Nextcloud mobile app already auto-uploads photos. Watch `/Fleet/Inbox`,
  show unassigned images, attach in two taps. Reuses Files instead of building an uploader, and
  needs no OCR.
- Tyre set management: summer/winter, storage place, DOT, tread depth.

**Medium**

- Fleet mode: share a vehicle with a group, roles manager/driver/viewer. The access check exists
  from M1; only the UI waits ([ADR 0001](adr/0001-own-access-table.md)).
- Pool booking with conflict check, mirrored into a shared calendar.
- Check-out / check-in with odometer, fuel level, damage photos (handover protocol).
- Damage and incident log with claim number.
- Leasing/warranty contract: end date, mileage cap, projected overrun warning.
- Führerscheinkontrolle: recurring 6-month check per driver (a legal duty for company fleets).
- UVV / DGUV V70 annual safety inspection as a built-in reminder template.
- Import from Drivvo, Spritmonitor, LubeLogger CSV.
- Consumption anomaly alert: efficiency drop over 3 fill-ups → hint at a service need.
- Cost centre and partner promoted from free text to entities — but only when mileage is actually
  billed on ([entry sheet](ui.md#the-entry-sheet-in-detail)).
- Budget per vehicle per year, with variance against actual.
- Talk integration: due items and handovers posted into a fleet room.
- Idle detection: not moved in N days → battery warning, or deregistration for a seasonal vehicle.
- Saisonkennzeichen: reminders when the season opens and closes, driven by the `laid_up` lifecycle
  state ([data model](architecture.md#data-model)).
- "O bis O" (Oktober bis Ostern) as a built-in tyre-swap template.
- Fuel price memory per station, to prefill the next fill-up.
- A booking becomes a trip: check-in prefills date, driver and starting odometer.

**Low / later**

- Trip favourites (home, office) to speed up entry; optional Nominatim distance lookup, opt-in only.
- Public read-only share link for a vehicle's maintenance history (resale value).
- A server-side report renderer, if a scheduled report ever needs a file nobody is present to print
  ([ADR 0005](adr/0005-no-pdf-library.md)).

## Logbook mode

Switched on per vehicle, built in M2.

The strict-logbook feature is a *ruleset*, and Germany's is the first one we implement.

German tax authorities accept an electronic logbook only if entries are timely, complete and
[protected against unnoticed later changes](https://www.haufe.de/personal/entgelt/nachbesserungen-im-fahrtenbuch-sind-unzulaessig_78_170740.html);
mandatory fields are plate, date, start and end odometer, full destination, purpose and the business
partner visited. Private trips need only the kilometres. A commute states the journey and not its
reason — its category is the whole reason — so it is asked for the plate, the date and both
counters and for nothing else.

So: with the mode on, trips become append-only. An edit rewrites the trip in place and writes a
`fleet_audit` row holding the diff; that row is the revision, not a second trip row. Off by default
— private users do not need the friction.

**What the logbook asks is computed for every vehicle and said only under the mode.** One rule, for
completeness and for gap detection alike, so the app has one rule and not two: a vehicle whose mode
goes on shows at once what its existing trips lack, and nobody keeping no logbook is asked.

**Incomplete is a flag.** A trip missing a field its ruleset requires is saved, never refused, and
the timeline lists it as incomplete with the fields it still lacks. The answer is computed on read,
never stored, so a ruleset that changes changes it for every trip. A distance trip has no counters,
so as a business trip it is asked for both, and the way to answer is to edit it into a trip with
its counters.

**A Gap is a claim above where the last trip left the counter.** A trip's `start_odo` is what the
driver says the counter read. It is measured against the newest Reading a trip wrote at or before
its start, never the trip's own; with none, against the newest Reading
([rule 5](architecture.md#odometer-rules)). A claim above it leaves the difference unaccounted; a
claim at or below it opens no Gap. A distance trip claims nothing and is never measured.

A Reading with no journey around it — an Odometer Entry, and from M3 every fill-up — proves the
counter moved, not who drove it. So it accounts for no kilometre, and a refuel cannot swallow the
Gap before it. It can still ask a question: one that is flagged (a cluster swap looks like a typo
from here), one the counter stood above the claim at, or one at the last trip's own moment that
reads another number. Any of these between the two opens no Gap until it is answered, because a Gap
counted past a question could be kilometres nobody drove. A Gap belongs to the month the trip set
off in, and that month's header states the total.

**A Gap is closed by a confirmation, one Gap at a time.** It names the kilometres and the two moments
that bracket them, and creates one private trip marked `reconciled`. Its audit row says the
kilometres were derived, not observed. Never a batch, and never a business trip: the app knows how
far the vehicle went, not why. Afterwards it edits like any other trip, audited and late past the
lock delay. No request sets or clears `reconciled`. The mode records writes; it refuses none.

**A delete voids, it does not remove.** `deleted_at` is set, the row survives, an audit row records
who and when, and the export lists the trip as voided. The Reading the trip left on the counter goes
with it ([rule 5](architecture.md#odometer-rules)). Undo stays the default gesture everywhere in
the app ([entry sheet](ui.md#the-entry-sheet-in-detail)) and is recorded in its turn; under this mode
it simply cannot destroy evidence.

**Switching the mode on locks nothing retroactively**
([ADR 0003](adr/0003-logbook-mode-does-not-lock-the-past.md)). The export states the date the mode
began. Claiming integrity for records that never had it is worse than admitting the gap.

**Every flip is a `fleet_audit` row on the vehicle**, on the way off as well as on, and that trail
is what the export reads the periods off. A vehicle created with the mode already on has been under
it since it was created, so the first period needs no row of its own to begin. Switching off is
allowed — a mode that could only ever go on would be a trap, not a setting — and it asks first
([UI](ui.md#details-that-decide-whether-it-feels-easy)).

**The lock delay belongs to the ruleset**, not to the core: `ILogbookRules` supplies it, and the
German value carries its source URL ([contributing](contributing.md)). Days, not weeks — timeliness
is the entire point. Germany's is seven, and the same ruleset states the ten years a record is kept
for, a floor under the vehicle's own retention period ([legal](legal.md)). An edit, a void or a
restore after the delay is allowed, its audit row says `late`, and the export shows the change on
the trip's line. The delay runs from the end of the journey, and from the earlier end when the edit re-dates it, so moving an
old trip to yesterday does not restart the clock. A jurisdiction with no ruleset has no delay, so nothing under it is late.

**Closing a gap creates one trip, with a confirmation, and only a private one.** Never a batch. A
private trip legally needs only the kilometres; a business trip needs a purpose and a partner that
the app would be inventing. The created trip is marked `reconciled` and its audit row says derived
rather than observed. An auditor finding honest reconciliation entries is a far better outcome than
one finding fabricated business trips.

**What is German here, and what is not:** append-only storage, revisions, voiding and the audit
trail are generic and stay in the core. The *field requirements*, the *lock delay*, the *retention
period* and the *export layout* are the German ruleset, one directory under `lib/Jurisdiction/`
([contributing](contributing.md)). Austria, the UK or the US bring their own. Under the generic
jurisdiction the mode still works — append-only and audit are core — it simply requires no fields.

A compliance *aid*, not a certification, and reviewed by no lawyer — see the warning in
[licensing and legal](legal.md). Say so in the README and in the app, in every language.
