# Features

What other tools taught us, and what is not built yet.
What is built is in the [CHANGELOG](../CHANGELOG.md).

## What existing tools teach us

- [LubeLogger](https://lubelogger.com/) is the closest reference.
  Its reminders trigger on a date, a counter, or whichever comes first, and can recur.
  NextFleet copies that model.
- [Drivvo](https://www.drivvo.com/en-US/) is strong on cost reports: cost per km, per category, per month.
  NextFleet copies the reports, not the ad-driven cloud.
- Nextcloud already has [Car Fuel & Maintenance](https://apps.nextcloud.com/apps/carfuelmaintance) and [Vehicle Manager](https://github.com/jonathan-berthet/Nextcloud-VehicleManager).
  Both are single-user fuel logs.
  Neither keeps a logbook a tax office accepts.

**What sets NextFleet apart** is the freelancer's paperwork ([who it is for](../plan.md#who-it-is-for)): a logbook that holds up, gap detection and a mileage claim, on top of Nextcloud's notifications, Files, search and dashboard.
A fuel log alone is no reason to switch.

## Feature backlog

Not built.
Ranked by value for the effort.

**High**

- Tyre sets: summer and winter, storage place, DOT, tread depth.

**Medium**

- A fleet table: every vehicle a manager reaches, across owners, sortable and filterable by group.
  The one screen where density beats simplicity.
- A damage and incident log with a claim number.
- Leasing and warranty: end date, mileage cap, a warning before an overrun.
  The reminder engine's prediction can do it once the two columns exist.
- Driving licence checks every six months per driver, a legal duty for company fleets.
- The UVV / DGUV V70 yearly safety inspection as a reminder template.
- A hint when consumption rises over three fill-ups.
- Cost centre and partner as entities, once mileage is billed on.
- A budget per vehicle and year, against the actual cost.
- Talk: due items and handovers posted into a fleet room.
- Idle detection: not moved for N days.
- Seasonal plates: reminders when the season opens and closes, from the `laid_up` state.
- "O bis O" (October to Easter) as a tyre-swap template.
- The Android client.
  The [OCS API](api.md) is ready for it.
- An Activity stream provider.

**Low**

- Trip favourites (home, office).
  Distance lookup only on request.
- A public read-only link to a vehicle's maintenance history, for a sale ([security](security.md#things-that-are-visible-in-the-real-world)).
- A server-side report renderer, if a scheduled report ever needs a file nobody is there to print ([ADR 0005](adr/0005-no-pdf-library.md)).

Back to the [developer guide](developer/README.md).
