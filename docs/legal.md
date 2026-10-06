# Licensing and legal

Part of the [NextFleet plan](../plan.md).

> **No legal review. No guarantee.**
>
> Nothing in this repository has been checked by a lawyer. The logbook rules, the tax and
> data-protection notes and every rate in a country directory are written by developers from public
> sources. They may be wrong, and they will go out of date.
>
> Using NextFleet gives you no assurance that a logbook, a report or an export it produces will be
> accepted by any tax office, auditor or court. Ask your own tax adviser or lawyer before you rely
> on it. The AGPL says the same thing in sections 15 and 16: this software comes with no warranty.

The app is **AGPL-3.0-or-later**: `LICENSE` carries the verbatim text, `README.md` the copyright
notice. The app store requires AGPL or a compatible licence, and the frontend bundles
`@nextcloud/vue`, which is AGPL itself — so the combined work is AGPL either way.

Consistency matters: `LICENSE`, the `<licence>` tag in `appinfo/info.xml`, `composer.json`,
`package.json` and the SPDX headers must all say the same thing. The store's own `agpl` shorthand is
deprecated, so the tag carries the SPDX identifier — which the schema only accepts from Nextcloud 31
up, and 31 is our floor anyway.

**Where the licence is declared.** Every file that has a comment syntax carries a two-line SPDX
header. The rest — JSON, lock files, Markdown, the design exports — is annotated in `REUSE.toml`,
and the licence text sits in `LICENSES/AGPL-3.0-or-later.txt`, where REUSE looks for it; the root
`LICENSE` is a copy for GitHub. `reuse lint` is the check that covers both halves and runs on every
push; `tests/Unit/LicensingTest.php` enforces the header half without it, so a missing header fails
`composer test` rather than waiting for CI.

REUSE reads any file, tests included, so a string literal that merely *mentions* an SPDX identifier
is picked up as a declaration and rejected. Fence such a line between `REUSE-IgnoreStart` and
`REUSE-IgnoreEnd` comments.

The store screenshots in `design/screenshots/` show Nextcloud's interface, logo and default
background, so `REUSE.toml` names Nextcloud GmbH beside us: the background is CC-BY-SA-4.0, the
logo falls under Nextcloud's trademark guidelines. Both texts are in `LICENSES/`.

The frontend bundle is a combined work with its dependencies, and `@nextcloud/vite-config` emits a
`.license` file beside each output listing each one. The app's code and its dependencies sit in
`js/boot-*.chunk.mjs.license`, since the entry is only a loader ([development](development.md)).
Those files name the licences; MIT, ISC and BSD also want the notice itself to travel with the
code, Apache-2.0 and the GPL the licence text. So `tools/package.sh` writes every bundled package's
own licence file into the tarball's `THIRD-PARTY-NOTICES.txt` (`tools/third-party-notices.mjs`),
and `LICENSE.third-party` is only needed for code we vendor by hand.

**Can another vehicle-logbook project sue us?** Not for the feature set. Copyright protects code,
not ideas or functionality — the EU Software Directive excludes the ideas and principles underlying
a program from protection, and *SAS Institute v World Programming* confirmed that functionality and
data formats are not protected. What creates actual exposure:

| Risk | Rule we follow |
|---|---|
| Copied source | Write our own. Never paste from LubeLogger (MIT), Vehicle Manager, or any GPL project. If we ever do vendor MIT code, keep its notice and record it in `LICENSE.third-party`. |
| Copied schema/strings/icons | Same rule. Icons from Nextcloud's own icon set or a permissive set, installed through npm, so the bundle's `.license` files name them. |
| Other projects' names | "Drivvo", "LubeLogger", "Spritmonitor" never stand alone in the UI, as a feature or a logo. An import format is "CSV (LubeLogger format)" — nominative use of a name to say what a file is, nothing more. |
| **"TÜV"** | A registered trademark since 1979, actively enforced, and *not* usable as a synonym for inspection. The UI says **HU/AU** or "Hauptuntersuchung". Never "TÜV-Termin", never a TÜV-like seal. |
| "Nextcloud" | App store rule: not in the app name. `NextFleet` is clear of it, though the `Next` prefix invites confusion — `FleetLog` is the safer fallback if anyone objects. Never restyle it "NextCloud"; the brand is one word, one capital. |
| Dependencies | `tests/Unit/LicensingTest.php` checks that every runtime npm and Composer package in the lock files, and every package the bundle's `.license` files name, is under a licence the AGPL admits. One GPL-incompatible transitive package can block a release. The bundle half needs a build in `js/`, so CI's frontend job runs it after the build. v1 avoids the hardest case by shipping no PDF library at all ([ADR 0005](adr/0005-no-pdf-library.md)). |

**Contributions carry the licensing risk now.** Country directories arrive by merge request
([contributing](contributing.md)) and ship in a release signed with our certificate, so a
contributor who pastes a rate table out of a commercial handbook makes it our problem. Therefore:
every commit needs a `Signed-off-by` (DCO), rates and deadlines are cited by URL rather than
quoted, and a merge request that cannot say where a number came from does not get merged.

**A rate no source states stays "not stated".** A wrong rate on a tax document is worse than none
(decided 2026-09-30). The known gap: §9 EStG pays 0,30 €/km for a *Kraftwagen* and 0,20 €/km for
*andere motorbetriebene Fahrzeuge*, which a motorcycle is, and no source we found places a tractor
(*Zugmaschine*) under either. So `lib/Jurisdiction/De/RateProvider.php` gives it no mileage rate, and its claim lines say
"not stated" until a source does.

**The bigger legal exposure is data protection, not copyright.** Trips carry destinations, purposes
and driver identities — personal data under GDPR, and in fleet mode an employer processing employee
data, which in Germany brings the works council into it. Therefore:

- Trip location fields stay free text. No GPS, no automatic tracking (already out of scope in
  [scope](../plan.md#scope)).
- **No purge exists yet.** Nothing deletes a vehicle's rows: not a retention period, not the trash,
  not an erasure ([data model](architecture.md#data-model)). The vehicle sheet does not ask for a
  retention period, so it promises nothing. What follows is the design for when one is built.
- **Retention is opt-in and off by default.** No automatic purge unless a vehicle is given a
  retention period. A logbook that quietly deletes its owner's history is a worse failure than one
  that keeps too much, and the real GDPR exposure here is driver data on granted vehicles, handled
  below. Soft-deleted rows clear from the trash after 30 days; under Logbook Mode voided rows stay
  for the retention period ([logbook mode](features.md#logbook-mode)).
- **Under Logbook Mode retention cannot go below the jurisdiction's required period**, and the field
  says why it is clamped.
- **Erasing a driver pseudonymises, it does not delete**
  ([ADR 0008](adr/0008-erasing-a-driver-pseudonymises.md)). The user id on their records is replaced
  and the records stay: they are the vehicle owner's logbook, and under Logbook Mode they carry a
  retention duty. A co-driver leaving must not shred someone else's tax evidence. This is built:
  deleting a Nextcloud account replaces its uid with one random pseudonym on every row, the
  ownership of its vehicles and its grants included, and takes it off every reminder list.
- **An erased owner's vehicles close** (decided 2026-10-03). Nobody is left who may decide over
  them, so the erasure revokes every grant on them and moves them to the trash. Their rows stay
  under the same rules as any deleted vehicle's. **Open gap:** no purge exists yet, so they stay
  until the opt-in retention above is built, which may be longer than a law requires. The legal
  basis for keeping them is the owner's and the drivers'
  bookkeeping duty, for which the GDPR keeps an erasure from reaching records a law requires
  (Art. 17(3)(b)), and beyond it the legitimate interest of whoever relied on the records
  (Art. 6(1)(f)). An admin who wants a pool to outlive its owner hands each vehicle over first
  with `occ nextfleet:transfer <vehicle-uuid> <new-owner-uid>`: the grants stay, and the former
  owner keeps viewing it while their account exists ([data model](architecture.md#data-model)).
- **Bookings and handover notes are the driver's personal data** as well: they say who had the car
  when, and a note may say how they left it. Erasure treats them as it treats trips — the uid goes,
  the span, counters and notes stay as the owner's record of the car. A cancel notice names no one
  (decided 2026-10-03): Nextcloud's notifications are not rows the app can rename, so a name there
  is one an erasure could not take back. No record of who cancelled is kept: the booking says it
  was cancelled, not by whom.
- **The audit keeps old trip text.** A trip change made under Logbook Mode, or to a trip that set
  off under it, writes the values it replaced to the audit's `diff_json`: destinations, purposes,
  business partners ([data model](architecture.md#data-model)). That is the point of an audit: § 146 (4) AO and the
  GoBD forbid a change that hides what stood before. So the text stays for the retention period,
  and an erasure renames the uid on the row but keeps the text (Art. 17(3)(b) GDPR).
- **Free text naming a person stays.** A purpose "with Anna to the client" or a handover note is
  text. An erasure replaces uids; it does not read prose.
- **Papers go with their Files.** A document row points at a file in its attacher's Files. When an
  erased driver's account goes, its files go too, and the row stays without a file
  ([documents](architecture.md#documents)).
- **Logs outlive an erasure.** Nextcloud's log can hold uids, and an exception trace can hold the
  arguments of a call, trip text included. NextFleet cannot rename either. How long logs are kept
  is the admin's decision. We recommend `zend.exception_ignore_args=On` in `php.ini`, so a trace
  carries no arguments.
- **Off Logbook Mode the basis for keeping rows is Art. 6(1)(f) GDPR.** No law requires a logbook
  nobody keeps for the tax office. The rows stay because the owner and the drivers rely on their
  record of the car, and they stay only until the opt-in retention above is built.
- **An employer pooling cars has duties the app does not discharge.** Trips show who entered them
  (*Eingetragen von*) and when; bookings and handovers show when an employee drives which car. In
  Germany a system able to monitor employees needs the works council's consent (§ 87 (1) no. 6
  BetrVG), and that covers trips as well as bookings. The employer also needs its own legal basis
  and information duties for employee data under the GDPR. The app records; it consults no one.
- **Bookings get no retention rule of their own.** They are kept like every other row, until the
  opt-in retention above is built.
- **Granting access shows the whole vehicle** (decided 2026-09-30). Every role, viewer included,
  reads its costs, every trip with its purpose and business partner, and its documents — whoever
  entered them. There is no narrower view; the owner decides whom to grant, and the Access section
  says who holds what ([Vehicle Access](../CONTEXT.md)).
- **Imported history is entered by whoever imports it** ([import](architecture.md#import)). Every
  entry an import creates carries the importing user's `created_by`, so where *Entered by* shows
  ([who entered it](ui.md#who-entered-it)), it names them, not whoever drove or paid in the other
  tool: neither format says who that was, and the app invents no author.
- **The personal data export answers access and portability (Art. 15, 20 GDPR).** Nextcloud's
  *user_migration* app exports an account with every NextFleet row that names it, a JSON file per
  table ([personal data export](architecture.md#personal-data-export)). A row that only mirrors a
  vehicle the account can no longer see is reduced to its id, so the copy does not reach into the
  new owner's or the other drivers' data (Art. 15(4)). The admin installs that
  app; NextFleet ships none of its own. It is export only: importing the archive restores nothing.
  Per vehicle there is the [CSV export](architecture.md#csv-export) of a year's trips, energy,
  maintenance and expenses, and the Fahrtenbuch. That is also all a buyer gets when a vehicle is
  sold. Only an admin moves a vehicle to another account, with `occ nextfleet:transfer`; no user
  can ([data model](architecture.md#data-model)).
