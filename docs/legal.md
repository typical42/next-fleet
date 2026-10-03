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

The frontend bundle is a combined work with its MIT dependencies, and `@nextcloud/vite-config`
emits a `.license` file beside each output listing each one. The app's code and its dependencies
sit in `js/boot-*.chunk.mjs.license`, since the entry is only a loader
([development](development.md)). So `LICENSE.third-party` is only needed for code we vendor by hand.

**Can another vehicle-logbook project sue us?** Not for the feature set. Copyright protects code,
not ideas or functionality — the EU Software Directive excludes the ideas and principles underlying
a program from protection, and *SAS Institute v World Programming* confirmed that functionality and
data formats are not protected. What creates actual exposure:

| Risk | Rule we follow |
|---|---|
| Copied source | Write our own. Never paste from LubeLogger (MIT), Vehicle Manager, or any GPL project. If we ever do vendor MIT code, keep its notice and record it in `LICENSE.third-party`. |
| Copied schema/strings/icons | Same rule. Icons from Nextcloud's own icon set or a permissive set, tracked in a `NOTICE` file. |
| Other projects' names | "Drivvo", "LubeLogger", "Spritmonitor" never stand alone in the UI, as a feature or a logo. An import format is "CSV (LubeLogger format)" — nominative use of a name to say what a file is, nothing more. |
| **"TÜV"** | A registered trademark since 1979, actively enforced, and *not* usable as a synonym for inspection. The UI says **HU/AU** or "Hauptuntersuchung". Never "TÜV-Termin", never a TÜV-like seal. |
| "Nextcloud" | App store rule: not in the app name. `NextFleet` is clear of it, though the `Next` prefix invites confusion — `FleetLog` is the safer fallback if anyone objects. Never restyle it "NextCloud"; the brand is one word, one capital. |
| Dependencies | CI check that every npm/composer dependency is AGPL-compatible. One GPL-incompatible transitive package can block a release. v1 avoids the hardest case by shipping no PDF library at all ([ADR 0005](adr/0005-no-pdf-library.md)). |

**Contributions carry the licensing risk now.** Country directories arrive by merge request
([contributing](contributing.md)) and ship in a release signed with our certificate, so a
contributor who pastes a rate table out of a commercial handbook makes it our problem. Therefore:
every commit needs a `Signed-off-by` (DCO), rates and deadlines are cited by URL rather than
quoted, and a merge request that cannot say where a number came from does not get merged.

**A rate no source states stays "not stated".** A wrong rate on a tax document is worse than none
(decided 2026-09-30). The known gap: §9 EStG pays 0,30 €/km for a *Kraftwagen* and 0,20 €/km for
*andere motorbetriebene Fahrzeuge*, and no source we found places a tractor (*Zugmaschine*) under
either. So `lib/Jurisdiction/De/RateProvider.php` gives it no mileage rate, and its claim lines say
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
- **Bookings and handover notes are the driver's personal data** as well: they say who had the car
  when, and a note may say how they left it. Erasure treats them as it treats trips — the uid goes,
  the span, counters and notes stay as the owner's record of the car. One gap: an unread
  cancel notice in a booker's inbox keeps its canceller's uid once that account is gone, because
  Nextcloud's notifications are not rows the app can rename. The uid is no longer shown: the notice
  names "a former user".
- **An employer pooling cars has duties the app does not discharge.** Bookings and handovers show
  when an employee drives which car. In Germany a system able to monitor employees needs the works
  council's consent (§ 87 (1) no. 6 BetrVG), and the employer needs its own legal basis and
  information duties for employee data under the GDPR. The app records; it consults no one.
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
- Full per-user data export and per-vehicle export, wired into Nextcloud's own user-deletion hooks.
  The per-vehicle export is also what a buyer gets when a vehicle is sold — v1 transfers no
  ownership between users ([data model](architecture.md#data-model)).
