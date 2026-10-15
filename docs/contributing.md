# Contributing a country, an importer, a report

New country rules, import formats and report layouts arrive like everything else: fork, add a directory, open a merge request, get it reviewed.
There is **no plugin system, no registry and no extra app to install**.

That is on purpose:

- No plugin API to keep stable forever.
  The one versioned contract is the [OCS API](api.md), for clients outside the app.
- Third-party code inside the app would run under our name and our release signature.
  A merge request is reviewed first.
- **The price: we maintain every country we merge** ([what a country owes us](#what-a-country-owes-us)).

## Still one directory per country

No `if ($country === 'de')` across the code.
The seams stay, as internal interfaces:

| Seam | Interface | First implementation |
|---|---|---|
| Jurisdiction profile | `IJurisdiction`: key, name, units, currency, defaults for a new vehicle | `de`, `generic` |
| Logbook ruleset | `ILogbookRules`: required fields per category, lock delay, retention, source URL | German Fahrtenbuch ([Logbook mode](architecture.md#logbook-mode)) |
| Inspection | `IInspectionScheme`: the inspection as a reminder template, by vehicle type | HU/AU |
| Rates | `IRateProvider`: mileage rate, VAT, emission factors, **each valid from a date** | German rates |
| Reports | `IReportRenderer`, `IClaimRenderer`: printable HTML ([ADR 0005](adr/0005-no-pdf-library.md)) | Fahrtenbuch, mileage claim, generic logbook |
| Importer | `IImporter` in `lib/Import/` | LubeLogger, Spritmonitor |
| Service templates | `IServiceTemplates`: intervals by market. No law sets them, so no country owns them. | Oil, brake fluid, tyres |

A country is `lib/Jurisdiction/<Cc>/` plus one line in `Jurisdictions::PROFILES`.
Reviewing it means reading one directory.
Removing it means deleting that directory and its line.
A key nobody wrote resolves to `generic`, so a vehicle of a country a later release dropped still opens.

Germany fills every seam.
A new country fills only what its rules need.
A missing seam answers null, and the core asks the vehicle's jurisdiction, never the class.

**`generic` is the other profile.**
Metric, no currency, no ruleset, no inspection, no rates, but a plain logbook in the reader's language.
Every seam has to cope with a country that answers "I don't know", and `generic` is the test that it does.
That is why a report that needs a rate is *unavailable* rather than zero.

## Rules that keep the seam honest

- **The core knows no country.**
  If the core needs a German fact, an interface is missing it.
  That is a review comment, not a workaround.
- **Rates are versioned by date, always.**
  A 2024 claim uses the 2024 rate: `IRateProvider::vatRateAt($when)` and its siblings, never a constant.
  The one exception is the grid average, which is published years late: it is the newest figure with its year shown.
- **Store canonical, display local.**
  Kilometres, millilitres, cents and UTC in the database, a UK vehicle included.
  Conversion happens at the edges.
- **The country is per vehicle**, not per instance.
  A fleet crosses borders.
- **Rules return findings, not exceptions.**
  "This trip has no purpose and your country needs one": the record is still saved, flagged and fixable.
- **A country that needs a column adds a migration**, reviewed like any schema change.
  No JSON blob.

## What a country owes us

- A `CODEOWNERS` entry with a reachable maintainer, who answers when the law changes.
- A green run of the shared kit in `tests/Country/`, the same for every country.
- A source URL for every rate and deadline, linked, not quoted ([legal](legal.md)).
  "45p per mile" without a source is a number nobody can check in three years.
- A `Signed-off-by` on every commit (DCO).
- A country nobody maintains is marked experimental and later removed.
  A silent, wrong tax report is worse.

## The second country is a test

**The UK is the honest test:** miles, mpg, an MOT after three years, pence per mile.
It breaks every unit assumption hiding in the code, before a second country ships.
It lives in `tests/Country/`, not in `lib/` ([ADR 0002](adr/0002-uk-is-a-test-jurisdiction.md)).
A real `uk` ships when a maintainer signs up.

Display units belong to the country profile, not to a user setting.
They join `IJurisdiction` with the first screen that converts.

## AI assistance

You can use an AI tool.
[Nextcloud's AI policy](https://github.com/nextcloud/.github/blob/master/AI_POLICY.md) applies here:

- **Name the tool in each commit** with a trailer per tool and model: `Assisted-by: AGENT_NAME:MODEL_VERSION`, for example `Assisted-by: ClaudeCode:claude-sonnet-4-6`.
  Git, editors and static analysis need none.
- **Name it in the pull request.**
  The [template](../.github/pull_request_template.md) asks.
- **You are the author.**
  You explain, defend and change each line.
  "The AI wrote it" is no answer.
- **Write issues, descriptions and review comments yourself.**
  A tool may translate or fix spelling.
- **Check security yourself:** each dependency, access check and login path.
- **Only a person sends.**
  An AI tool opens no issue or pull request, writes no review and sends no security report.
  It never adds `Signed-off-by`.
- **Copy nothing the AGPL does not admit** ([legal](legal.md)).
- **Comments describe the code**, not how it was made.
  That goes in the commit message.
- **Test on a live Nextcloud**, at each place a user can reach the change.

Next: [Development](development.md)
