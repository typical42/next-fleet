# Features

Part of the [NextFleet plan](../plan.md).

## What existing tools teach us

- [LubeLogger](https://lubelogger.com/) — the closest reference. Record types: service, repair,
  upgrade, fuel, odometer, tax, supplies, inspection, notes, plans. Reminders trigger on **date, or
  odometer, or whichever comes first**, and can auto-recur. Copy this model.
- [Drivvo](https://www.drivvo.com/en-US/) — strong on cost reporting: cost per km, per category,
  monthly trend. Copy the reporting angle, not the ad-driven cloud.
- Nextcloud already has [Car Fuel & Maintenance](https://apps.nextcloud.com/apps/carfuelmaintance)
  and [Vehicle Manager](https://github.com/jonathan-berthet/Nextcloud-VehicleManager). Both are
  single-user logbooks. Neither does calendar-backed reminders, multi-user fleets, or a
  tax-grade Fahrtenbuch.

**Our differentiator:** deep Nextcloud integration (calendar, notifications, files, sharing,
search, dashboard) plus German fleet duties (HU/AU, UVV, Führerscheinkontrolle, Fahrtenbuch).

## Feature backlog

Ranked by value per effort.

**High**
- Fuel and charging log with l/100 km, kWh/100 km, € per km, cost trend.
- Cost dashboard and CSV/JSON export per vehicle and per year.
- Recurring service intervals (see [reminder engine](architecture.md#reminder-engine)) with a
  template set: oil, brake fluid, HU/AU, tyre swap.
- Documents: Fahrzeugschein, insurance policy, manual, receipts — linked Nextcloud files.
- Fahrtenbuch export (PDF/CSV) with business/private/commute split.
- Tyre set management: summer/winter, storage place, DOT, tread depth.
- **QR sticker per vehicle.** A printed code for the glovebox; scanning opens the quick-add form for
  *that* car. It removes the one step that makes people skip logging — picking the vehicle. Works in
  the mobile browser today and in the Android app later. Best adoption-per-line-of-code in the list.
- **Receipt inbox.** The Nextcloud mobile app already auto-uploads photos. Watch `/Fleet/Inbox`,
  show unassigned images, attach in two taps. Reuses Files instead of building an uploader, and
  needs no OCR.
- **Mileage expense report.** Business trips × the statutory rate (0,30 €/km today, configurable) →
  a Reisekosten claim as PDF/CSV. For freelancers this is the whole reason to keep a logbook.
- **Gap detection.** A Fahrtenbuch must be gapless, and the app knows the kilometres between one
  trip's end and the next one's start. Show unaccounted km per month, close them with one click as a
  private trip. Nothing in [the prior art](#what-existing-tools-teach-us) does this, and it is
  exactly what an audit looks for.

**Medium**
- Fleet mode: share a vehicle with a group, roles manager/driver/viewer.
- Pool booking with conflict check, mirrored into a shared calendar.
- Check-out / check-in with odometer, fuel level, damage photos (handover protocol).
- Damage and incident log with claim number.
- Leasing/warranty contract: end date, mileage cap, projected overrun warning.
- Führerscheinkontrolle: recurring 6-month check per driver (a legal duty for company fleets).
- UVV / DGUV V70 annual safety inspection as a built-in reminder template.
- Import from Drivvo, Spritmonitor, LubeLogger CSV.
- Consumption anomaly alert: efficiency drop over 3 fill-ups → hint at a service need.
- Cost centre / project tag on trips, so mileage can be billed on.
- Budget per vehicle per year, with variance against actual.
- Talk integration: due items and handovers posted into a fleet room.
- Idle detection: not moved in N days → battery warning, or deregistration for a seasonal vehicle.
- Saisonkennzeichen: seasonal plates, with reminders when the season opens and closes.
- "O bis O" (Oktober bis Ostern) as a built-in tyre-swap template.
- Fuel price memory per station, to prefill the next fill-up.
- A booking becomes a trip: check-in prefills date, driver and starting odometer.

**Low / later**
- Units and currency switch (km/mi, l/gal).
- Trip favourites (home, office) to speed up entry; optional Nominatim distance lookup, opt-in only.
- Public read-only share link for a vehicle's service history (resale value).

## Logbook mode

Switched on per vehicle, built in M2.

The strict-logbook feature is a *ruleset*, and Germany's is the first one we implement.

German tax authorities accept an electronic logbook only if entries are timely, complete and
[protected against unnoticed later changes](https://www.haufe.de/personal/entgelt/nachbesserungen-im-fahrtenbuch-sind-unzulaessig_78_170740.html);
mandatory fields are plate, date, start and end odometer, full destination, purpose and the business
partner visited. Private trips need only the kilometres.

So: with the mode on, trips become append-only. Edits write a new revision plus a `fleet_audit`
row; nothing is deleted, records lock after a grace period. Off by default — private users do not
need the friction.

**What is German here, and what is not:** append-only storage, revisions and the audit trail are
generic and stay in the core. The *field requirements*, the *lock delay*, the *retention period* and
the *export layout* are the German ruleset, one directory under `lib/Jurisdiction/`
([contributing](contributing.md)). Austria, the UK or the US bring their own — with different
required fields, different retention, a different document at the end.

A compliance *aid*, not a certification, and reviewed by no lawyer — see the warning in
[licensing and legal](legal.md). Say so in the README and in the app, in every language.
