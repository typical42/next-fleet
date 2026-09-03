# Contributing a country, an importer, a report

Part of the [NextFleet plan](../plan.md).

Country rules, import formats and report layouts arrive the way everything else in open source
arrives: fork the repository, add a directory, open a merge request, get it reviewed, ship it in the
next release. There is **no plugin system, no registry, no separate app to install**.

That is a deliberate decision, and it costs us something. Writing it down so nobody re-litigates it
by accident:

- No public API to keep stable forever, no versioned contract, no event to dispatch. Fewer moving
  parts than a runtime seam that would carry two implementations at most.
- Third-party code that runs inside our app would run unsandboxed under our name and our release
  signature. A merge request is reviewed before it gets that.
- **The price: every merged country is ours to maintain.** See "What a country owes us" below.

## Still one directory per country

Dropping the plugin system does not mean scattering `if ($country === 'de')` through the codebase.
The interfaces stay — as ordinary internal seams, not as public API:

| Seam | Interface | First implementation |
|---|---|---|
| Jurisdiction profile | `IJurisdiction` — currency, units, plate format, document kinds, defaults | `de` |
| Logbook ruleset | `ILogbookRules` — required fields per trip category, lock delay, retention, validation findings | German Fahrtenbuch ([logbook mode](features.md#logbook-mode)) |
| Inspection regime | `IInspectionScheme` — names, cadence, first-due rule → reminder templates | HU/AU (24 months, 36 for new cars) |
| Rates over time | `IRateProvider` — mileage allowance, VAT, emission factors, **each valid from a date** | 0,30 €/km, German grid factor |
| Report renderer | `IReportRenderer` — range in, file out | Fahrtenbuch PDF, mileage claim |
| Importer | `IImporter` — foreign CSV in, our records out | Drivvo, Spritmonitor, LubeLogger |
| Service templates | `IServiceTemplates` — intervals by market or manufacturer | Generic (oil, brakes, tyres) |

A country is `lib/Jurisdiction/<Cc>/`, wired up in one registration list. Reviewing it means reading
one directory, and deleting it means deleting one directory.

## Rules that keep the seam honest

- **The core knows no country.** No `if ($country === 'de')` outside `lib/Jurisdiction/De/`. If the
  core needs a German fact, the fact is missing from an interface — that is a review comment, not a
  workaround.
- **Rates are time-versioned, always.** A 2024 mileage claim must use the 2024 rate, not today's.
  `IRateProvider::rateAt(DateTimeInterface $when)`, never a constant. Same for VAT and emission
  factors. This is the detail that quietly invalidates reports when it is skipped.
- **Store canonical, display local.** Kilometres, millilitres, cents, UTC in the database — always,
  including for a UK vehicle. Conversion happens at the edges.
- **Jurisdiction is per vehicle**, not per instance: a fleet crosses borders, and a leased car
  registered abroad keeps its own rules. Falls back to instance default, then to the user's locale.
- **Validation returns findings, not exceptions** ([data model](architecture.md#data-model)). A
  ruleset says "this trip has no purpose and your jurisdiction requires one" — the record is still
  saved, still flagged, still fixable.
- **A country that needs its own column adds a migration**, reviewed like any other schema change.
  There is no escape-hatch JSON blob: a field nobody can see in the schema is a field nobody
  maintains.

## What a country owes us

Because we maintain what we merge:

- A `CODEOWNERS` entry with a reachable maintainer, and an answer when their country's law changes.
- A passing run of the shared country test kit in `tests/Country/` — the same suite for every
  jurisdiction, so a new one is provably wired up rather than merely present.
- Sources for every rate and deadline, cited by URL in comments — linked, not quoted, because a
  pasted handbook table ships in our signed release ([licensing and legal](legal.md)). "45p per
  mile" without a citation is a number nobody can check in three years.
- A `Signed-off-by` line on every commit (DCO). We cannot relicense what we cannot trace.
- A country nobody maintains gets marked experimental and eventually removed. Saying so up front is
  kinder than a silent, wrong tax report.

## Ship the second country early

**The UK is the honest test**: miles, litres, mpg, MOT annually after three years, 45p then 25p per
mile. It breaks every unit assumption hiding in the code, which is exactly what we want it to do —
at M5, not at M9. Germany alone proves nothing, whatever the interfaces look like.

## Consequence for the interface

Units stop being a "low priority setting". They are a property of the jurisdiction, resolved on
display, and the l/100 km ↔ mpg conversion belongs in the profile — not in a checkbox.
