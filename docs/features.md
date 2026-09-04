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
  between one trip's end and the next one's start. Show unaccounted km per month and close them one
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
partner visited. Private trips need only the kilometres.

So: with the mode on, trips become append-only. Edits write a new revision plus a `fleet_audit`
row. Off by default — private users do not need the friction.

**A delete voids, it does not remove.** `deleted_at` is set, the row survives, an audit row records
who and when, and the export lists the trip as voided. Undo stays the default gesture everywhere in
the app ([entry sheet](ui.md#the-entry-sheet-in-detail)); under this mode it simply cannot destroy
evidence.

**Switching the mode on locks nothing retroactively**
([ADR 0003](adr/0003-logbook-mode-does-not-lock-the-past.md)). The export states the date the mode
began. Claiming integrity for records that never had it is worse than admitting the gap.

**The lock delay belongs to the ruleset**, not to the core: `ILogbookRules` supplies it, and the
German value carries its source URL ([contributing](contributing.md)). Days, not weeks — timeliness
is the entire point.

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
