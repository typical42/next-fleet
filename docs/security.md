# Security

Part of the [NextFleet plan](../plan.md). Terms are defined in [CONTEXT.md](../CONTEXT.md).

The app holds a movement profile: where someone was, when, and why. Treat it accordingly.

### Who actually attacks this

| Attacker | What they want | Where they get in |
|---|---|---|
| Another user on the same instance | Read a colleague's trips | Guessable ids, missing access checks |
| A shared driver | More than their role allows | Role checks done in the UI only |
| The open internet | Any unauthenticated endpoint | Share links, QR routes, any route missing an auth annotation |
| Someone uploading a file | Code execution in a viewer's browser | Receipt photos, PDFs, **SVG** |
| Someone importing a file | Server resources, or a poisoned export | CSV import, CSV export opened in Excel |
| A dependency | Everything, quietly | npm, composer, GitHub Actions |
| A contributor ([contributing](contributing.md)) | Everything, through the front door | A merge request is code in our next signed release |

### Authorization

- **Every controller method is annotated deliberately** — `#[NoAdminRequired]` on user endpoints,
  `#[NoCSRFRequired]` only where an OCS route genuinely needs it, `#[BruteForceProtection]` on
  anything token-addressed, `#[UserRateLimit]` on writes. An unannotated method fails review.
- **One service decides access.** `VehicleAccess::may($uid, $op, $vehicleId)`, backed by
  `fleet_access` ([ADR 0001](adr/0001-own-access-table.md)). Controllers never reach a mapper
  directly, and no mapper trusts an id from a request body. **IDOR is the realistic bug here** — the
  API is id-addressed and sharing makes guessing worth the effort — so the integration suite asserts
  the stranger case for *every* endpoint, not one.
- **File downloads are proxied** ([Nextcloud integration](architecture.md#nextcloud-integration)).
  The app's ACL decides, not the file's.

### Hostile content

- **Uploads are Files, and that is the point.** They land in the user's storage, so the admin's
  antivirus app (ClamAV) scans them if one is installed. We never build our own scanner and never
  bypass Files to write somewhere unscanned.
- **Nothing uploaded is ever rendered inline.** Downloads go out as an attachment
  (`Content-Disposition`) with `X-Content-Type-Options: nosniff`, and the client-supplied MIME type
  is never trusted. **An SVG served inline from our origin is script execution** — a receipt is a
  plausible SVG, so this is not theoretical.
- **CSV export is escaped against formula injection.** A field starting with `=`, `+`, `-`, `@`, tab
  or CR gets a leading apostrophe. Otherwise a trip named `=cmd|…` runs when a colleague opens the
  export in Excel — the classic bug in an app whose main output is CSV.
- **CSV import is bounded**: size cap, row cap, streaming parser, no archives, no `unserialize`,
  `json_decode` with a depth limit.
- **Reports load no remote resources.** v1 renders printable HTML and ships no PDF library
  ([ADR 0005](adr/0005-no-pdf-library.md)), which removes this surface rather than defending it. The
  rule stands for the day a server-side renderer arrives: a renderer that fetches URLs is an SSRF
  hole with a friendly name. The printable page inlines its own CSS and references nothing external.
- **Never `v-html`**, anywhere, for anything. Every value on screen came from a person.

### The client keeps nothing

The entry sheet writes no `localStorage` ([entry sheet](ui.md#the-entry-sheet-in-detail)). A durable
offline queue would hold destinations, purposes and partner names in a browser, on a phone that may
be shared, lost or logged out of — the app's most sensitive data, in its least controlled place, for
a problem an open form already solves. When the Android client introduces a real queue it introduces
this threat with it, and it gets its own review.

The server-time header ([time](architecture.md#time)) is a diagnostic. It never rewrites a
user-entered date, so a wrong or hostile clock cannot silently move a logbook entry.

### Things that are visible in the real world

- **The QR sticker ([backlog](features.md#feature-backlog)) is not a credential.** It sits behind a
  windscreen where anyone can photograph it, so it carries a vehicle identifier and nothing else —
  opening it still requires a Nextcloud session. A signed token in that sticker would hand write
  access to the car park.
- **Public service-history links** ([backlog](features.md#feature-backlog), low priority) get a
  128-bit random token, are revocable, carry no trips, drivers or destinations, are rate-limited and
  are marked `noindex`.
- **Optional geocoding stays off by default.** Any lookup ships a destination address to a third
  party — an SSRF surface and a GDPR disclosure in one request.
- **Outgoing webhooks** (Talk, [Nextcloud integration](architecture.md#nextcloud-integration))
  validate the URL, refuse private address ranges and follow no redirects.

### Supply chain

- Lockfiles committed, `npm ci` in CI, `npm audit` and `composer audit` as gates, Renovate for
  updates. Every dependency added is a decision, not a convenience.
- **GitHub Actions pinned to commit SHAs**, least-privilege `GITHUB_TOKEN`, no `pull_request_target`
  that checks out PR code. A compromised action owns the release.
- **Releases are signed.** Apps on apps.nextcloud.com are code-signed: request a certificate by CSR
  in `nextcloud/app-certificate-requests`, sign with `occ integrity:sign-app`, keep the private key
  off CI and offline. 2FA on the GitHub and app store accounts, protected `main`.

### Review is the only gate

There is no plugin system, so nobody can run code inside our app without a merge request
([contributing](contributing.md)). That moves the entire trust boundary onto review, and country
directories are the easiest place for something to slip through: they are long, they are full of
numbers nobody on the team can check from memory, and they are boring to read.

So a jurisdiction merge request is reviewed as code, not as data: no network calls, no file access,
no `exec`, no dependencies of its own, rates carrying sources. The country test kit runs on it. A
release goes out signed with our certificate, which means our name is on whatever we merged.

### Discipline elsewhere

- No raw SQL; QueryBuilder with bound parameters only. No `exec`, no `eval`, no writes outside the
  app's own paths.
- Bounded input: integers clamped to sane ranges, string lengths capped, enums whitelisted.
- **Logs never contain destinations, purposes or tokens.** Errors carry ids, not content.
- **Export endpoints are rate-limited and logged.** A full trip CSV is the most sensitive artefact
  this app can produce, and it is one request away. The same applies to the delta endpoint when the
  OCS API arrives ([ADR 0006](adr/0006-one-api-surface-in-v1.md)).
- `occ` commands never take secrets as arguments — shell history keeps them.

### Accepted risks, stated openly

A Nextcloud admin can read the database, and we do not encrypt trip data client-side: it would break
search, sorting and every report in [the maths](architecture.md#numbers-consumption-cost-emissions).
That is a deliberate trade, and it belongs in the README rather than in a footnote after an
incident.

### Process

`SECURITY.md` with a contact address and a response expectation — a third-party app is not covered
by Nextcloud's own bug bounty, so the reporting path has to be ours. `/security-review` on the diff
before every release, and a dependency audit on every merge to `main`.
