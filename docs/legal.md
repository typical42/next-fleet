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
`package.json` and the SPDX headers must all say the same thing. Use REUSE (`reuse lint` in CI) to
keep them honest.

**Can another vehicle-logbook project sue us?** Not for the feature set. Copyright protects code,
not ideas or functionality — the EU Software Directive excludes the ideas and principles underlying
a program from protection, and *SAS Institute v World Programming* confirmed that functionality and
data formats are not protected. What creates actual exposure:

| Risk | Rule we follow |
|---|---|
| Copied source | Write our own. Never paste from LubeLogger (MIT), Vehicle Manager, or any GPL project. If we ever do vendor MIT code, keep its notice and record it in `LICENSE.third-party`. |
| Copied schema/strings/icons | Same rule. Icons from Nextcloud's own icon set or a permissive set, tracked in a `NOTICE` file. |
| Other projects' names | No "Drivvo", "LubeLogger", "Spritmonitor" in the UI. Import features are described as "CSV import (Drivvo format)" — nominative use of a name to say what a file is, nothing more. |
| **"TÜV"** | A registered trademark since 1979, actively enforced, and *not* usable as a synonym for inspection. The UI says **HU/AU** or "Hauptuntersuchung". Never "TÜV-Termin", never a TÜV-like seal. |
| "Nextcloud" | App store rule: not in the app name. `NextFleet` is clear of it, though the `Next` prefix invites confusion — `FleetLog` is the safer fallback if anyone objects. Never restyle it "NextCloud"; the brand is one word, one capital. |
| Dependencies | CI check that every npm/composer dependency is AGPL-compatible. One GPL-incompatible transitive package can block a release. v1 avoids the hardest case by shipping no PDF library at all ([ADR 0005](adr/0005-no-pdf-library.md)). |

**Contributions carry the licensing risk now.** Country directories arrive by merge request
([contributing](contributing.md)) and ship in a release signed with our certificate, so a
contributor who pastes a rate table out of a commercial handbook makes it our problem. Therefore:
every commit needs a `Signed-off-by` (DCO), rates and deadlines are cited by URL rather than
quoted, and a merge request that cannot say where a number came from does not get merged.

**The bigger legal exposure is data protection, not copyright.** Trips carry destinations, purposes
and driver identities — personal data under GDPR, and in fleet mode an employer processing employee
data, which in Germany brings the works council into it. Therefore:

- Trip location fields stay free text. No GPS, no automatic tracking (already out of scope in
  [scope](../plan.md#scope)).
- **Retention is opt-in and off by default.** No automatic purge unless a vehicle is given a
  retention period. A logbook that quietly deletes its owner's history is a worse failure than one
  that keeps too much, and the real GDPR exposure here is driver data on shared vehicles, handled
  below. Soft-deleted rows clear from the trash after 30 days; under Logbook Mode voided rows stay
  for the retention period ([logbook mode](features.md#logbook-mode)).
- **Under Logbook Mode retention cannot go below the jurisdiction's required period**, and the field
  says why it is clamped.
- **Erasing a driver pseudonymises, it does not delete**
  ([ADR 0008](adr/0008-erasing-a-driver-pseudonymises.md)). `driver_uid` is replaced and the records
  stay: they are the vehicle owner's logbook, and under Logbook Mode they carry a retention duty. A
  co-driver leaving must not shred someone else's tax evidence.
- Full per-user data export and per-vehicle export, wired into Nextcloud's own user-deletion hooks.
  The per-vehicle export is also what a buyer gets when a vehicle is sold — v1 transfers no
  ownership between users ([data model](architecture.md#data-model)).
