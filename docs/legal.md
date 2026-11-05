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

## A German tax audit can reject the logbook

This applies to a vehicle in Germany whose logbook sets the tax on its private use.

**The law wants each later change to show.**
An electronic logbook must make a later change impossible, or it must record the change so that the change shows.
The direct basis is R 8.1 (9) no. 2 sentence 6 LStR ([LStH 2026](https://lsth.bundesfinanzministerium.de/lsth/2026/A-Einkommensteuergesetz/II-Einkommen-2-24b/4-Ueberschuss-d-Einnahmen-ueber-die-Werbungsk-8-9a/Paragraf-8/r-8-1-9.html)).
The BFH states the same rule in its decision of 12 January 2024 ([VI B 37/23](https://www.bundesfinanzhof.de/de/entscheidung/entscheidungen-online/detail/STRE202450011/)).
That decision confirms its judgment of 16 November 2005 (VI R 64/04, BStBl II 2006, 410).
[§ 146 (4) AO](https://www.gesetze-im-internet.de/ao_1977/__146.html) and the GoBD state the general rule for electronic tax records.
The GoBD is the BMF letter of 28 November 2019, BStBl I 2019, 1269, Rz. 108 and 111 ([text](https://www.lfst.bayern.de/fileadmin/RESSOURCEN/INFORMATIONEN/Steuerinfos/Weitere_Themen/Aussenpruefung/2019-11-28-GoBD-1.pdf)).
Its amendments of 11 March 2024 and 14 July 2025 do not change these Rz. ([latest amendment](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.html)).

**The change must show in the file itself.**
The BFH accepts a file that a program makes only if a later change is technically impossible, or is documented and shows in the file itself.
A change that only an admin can find with a separate query is not sufficient.

**The database admin can change trips without a trace.**
NextFleet keeps its data in the database of your own Nextcloud.
The admin of that Nextcloud can read and write its tables directly.
The [audit trail](architecture.md#the-audit-trail) sees only the changes that go through NextFleet.
A change made directly in the database leaves no record.

**An auditor can reject the logbook.**
Then the tax office applies the 1 % rule to the private use of the vehicle, also for the past years that the audit covers.
It does not estimate the private share from other records ([H 8.1 (9-10) LStH 2026](https://lsth.bundesfinanzministerium.de/lsth/2026/A-Einkommensteuergesetz/II-Einkommen-2-24b/4-Ueberschuss-d-Einnahmen-ueber-die-Werbungsk-8-9a/Paragraf-8/h-8-1-9-10.html)).

**German law has no certificate for logbook apps.**
A test seal that a vendor shows is not a legal requirement.
NextFleet has no such seal.

**Some steps make the risk smaller.**
They do not remove it.

- The Nextcloud admin is a person other than the taxpayer.
- Only that admin can get access to the database.
- The database has backups.
- At the end of each period, you export or print the logbook and keep it.

## Data protection

For the project, the GDPR is a bigger exposure than copyright.
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
- **A booking made for a driver names both.**
  It records who made it and for whom, and erasure pseudonymises both.
  It also records when the driver confirmed it, and nothing else about the driver.
- **A licence check keeps no copy of the licence.**
  No licence check exists yet.
  When it comes, it keeps the date, who checked, the classes and the expiry ([ADR 0012](adr/0012-nextfleet-also-serves-a-small-fleet.md)).
- **Logs outlive an erasure.**
  Nextcloud's log can hold uids and call arguments.
  We recommend `zend.exception_ignore_args=On`.
- **An employer who pools cars has duties the app does not carry out.**
  In Germany, a system that can monitor employees needs the works council (§ 87 (1) no. 6 BetrVG).
  The app records; it asks nobody.
- **Statistics sum each driver's trips, distance and business share** ([API](api.md#statistics)).
  That is a performance figure about a person, so it falls under the works council's say above.
  NextFleet ranks and scores no driver against another.
  The sums are the owner's and managers'; a driver or viewer gets only their own.
  This hides no trip: the next point lets every role read the logbook the sums come from.
  An erased driver stays in them under their pseudonym.
  They are counted on each read and stored nowhere.
- **Access shows the whole vehicle.**
  Every role, viewer included, reads every trip and document.
  One exception: where the owner keeps the costs from drivers and viewers, they read no document on another user's fill-up, maintenance or expense, no receipt on another user's booking, and no receipt on the vehicle itself ([costs](api.md#costs)).
  The owner decides whom to grant.
- **Imported entries name the person who imported them.**
  Neither format says who drove.
- **The personal data export answers Art. 15 and 20 GDPR** through Nextcloud's *user_migration* app ([personal data export](architecture.md#personal-data-export)).
  It is export only.

Next: [Contributing](contributing.md)
