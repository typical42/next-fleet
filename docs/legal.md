# Licensing and legal

> **No legal review. No guarantee.**
>
> No lawyer checked anything here.
> The logbook rules, the tax and data-protection notes and every rate come from public sources.
> They can be wrong or old.
> NextFleet does not promise that any tax office, auditor or court accepts what it produces.
> Ask your own adviser.
> The AGPL says the same in sections 15 and 16: no warranty.

## Licence

**AGPL-3.0-or-later.**
The app store needs AGPL or a compatible licence, and `@nextcloud/vue` is AGPL itself, so the bundle is AGPL either way.

- `LICENSE`, the `<licence>` tag in `appinfo/info.xml`, `composer.json`, `package.json` and the SPDX headers say the same.
  `info.xml` uses the SPDX id, because the store's `agpl` is deprecated.
- Every file with a comment syntax carries a two-line SPDX header.
  `REUSE.toml` covers the rest.
  `reuse lint` checks both in CI, and `LicensingTest` checks the headers in `composer test`.
- REUSE reads test files too.
  Fence a line that only quotes an SPDX id between `REUSE-IgnoreStart` and `REUSE-IgnoreEnd`.
- The store screenshots show Nextcloud's logo and background, so `REUSE.toml` names Nextcloud GmbH for them.
- `tools/package.sh` writes every bundled package's licence into the tarball's `THIRD-PARTY-NOTICES.txt`.
  `LicensingTest` checks that every runtime package is under a licence the AGPL admits.

## What can get us in trouble

Copyright protects code, not ideas or features.
The EU Software Directive and *SAS Institute v World Programming* say so.
So another logbook app cannot sue us for the feature set.
The real risks:

| Risk | Rule |
|---|---|
| Copied code | Write our own. Never paste from LubeLogger, Vehicle Manager or a GPL project. Vendored MIT code keeps its notice in `LICENSE.third-party`. |
| Copied strings, icons | The same. Icons from Nextcloud's set or a permissive set, through npm. |
| Other projects' names | Never alone in the UI. A format is "CSV (LubeLogger format)": a name used only to say what a file is. |
| **"TÜV"** | A registered trademark, enforced. Say **HU/AU**. Never "TÜV-Termin". |
| "Nextcloud" | Not in the app name. Never "NextCloud". |

**Contributions carry the risk now.**
A contributor who pastes a rate table from a commercial handbook makes it our problem.
So every commit needs a `Signed-off-by` (DCO), and rates are cited by URL, not copied ([contributing](contributing.md)).

**A rate no source states stays "not stated".**
A wrong rate on a tax document is worse than none.
For example, no source we found places a tractor under § 9 EStG, so it has no mileage rate.

## Data protection

The bigger exposure is the GDPR, not copyright.
Trips hold destinations, purposes and who drove.
With a pooled car, an employer processes employee data.

- **No tracking.**
  Places are free text.
  No GPS.
- **No purge exists yet** ([Nothing purges yet](architecture.md#deleting-and-erasing)).
  When it comes, retention is opt-in and off by default: a logbook that quietly deletes its owner's history is the worse failure.
  Under Logbook mode, retention cannot go below the country's period.
- **Erasing a driver pseudonymises** ([ADR 0008](adr/0008-erasing-a-driver-pseudonymises.md)).
  The records are the owner's logbook and can carry a duty to keep them.
  A co-driver who leaves must not shred someone else's tax evidence.
  The basis is Art. 17(3)(b) GDPR, and beyond it Art. 6(1)(f).
- **An erased owner's vehicles close** and stay in the trash under the same rules.
- **The audit keeps old trip text.** § 146 (4) AO and the GoBD forbid a change that hides what stood before.
  An erasure renames the uid on the row and keeps the text.
- **Free text that names a person stays.**
  An erasure replaces uids.
  It does not read prose.
- **Bookings and handover notes are personal data.**
  Erasure treats them like trips.
  A cancel notice names nobody, because a Nextcloud notification is not a row the app can rename.
- **Logs outlive an erasure.**
  Nextcloud's log can hold uids and call arguments.
  We recommend `zend.exception_ignore_args=On`.
- **An employer who pools cars has duties the app does not carry out.**
  In Germany, a system that can monitor employees needs the works council (§ 87 (1) no. 6 BetrVG).
  The app records; it asks nobody.
- **Access shows the whole vehicle.**
  Every role, viewer included, reads every trip and document.
  The owner decides whom to grant.
- **Imported entries name the person who imported them.**
  Neither format says who drove.
- **The personal data export answers Art. 15 and 20 GDPR** through Nextcloud's *user_migration* app ([personal data export](architecture.md#personal-data-export)).
  It is export only.

Next: [Contributing](contributing.md)
