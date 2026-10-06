# Security

Part of the [NextFleet plan](../plan.md). Terms are defined in [CONTEXT.md](../CONTEXT.md).

The app holds a movement profile: where someone was, when, and why. Treat it accordingly.

### Who actually attacks this

| Attacker | What they want | Where they get in |
|---|---|---|
| Another user on the same instance | Read a colleague's trips | Guessable ids, missing access checks |
| A grantee | More than their role allows | Role checks done in the UI only |
| The open internet | Any unauthenticated endpoint | Share links, QR routes, any route missing an auth annotation |
| Someone uploading a file | Code execution in a viewer's browser | Receipt photos, PDFs, **SVG** |
| Someone importing a file | Server resources, or a poisoned export | CSV import, CSV export opened in Excel |
| A dependency | Everything, quietly | npm, composer, GitHub Actions |
| A contributor ([contributing](contributing.md)) | Everything, through the front door | A merge request is code in our next signed release |

### Authorization

- **Every controller method is annotated deliberately** — `#[NoAdminRequired]` on user endpoints,
  `#[NoCSRFRequired]` only where a read-only page or download is opened by navigating to it (the
  [Fahrtenbuch export](architecture.md#the-fahrtenbuch-export): a navigation carries no token, and
  the same-site cookie is still required) — an OCS route needs none, `OCS-APIRequest` exempts it — `#[BruteForceProtection]` on
  anything token-addressed, `#[UserRateLimit]` on writes. An unannotated method fails review.
- **One service decides access.** `VehicleAccess::may($uid, $op, $vehicle)`, backed by
  `fleet_access` ([ADR 0001](adr/0001-own-access-table.md)), and for an Entry or a booking found
  on that vehicle `mayChange` or `mayBooking` in the same class: who entered or booked it counts
  ([Nextcloud integration](architecture.md#nextcloud-integration)). Controllers never reach a mapper
  directly, and no mapper trusts an id from a request body. **IDOR is the realistic bug here** — the
  API is id-addressed and grants make guessing worth the effort — so the integration suite asserts
  the stranger case for *every* endpoint, not one
  (`tests/Integration/VehicleIdorTest.php`, which reads its route list from `appinfo/routes.php`).
  `tests/Unit/RouteSweepTest.php` fails without a server when a route has no arm there, or no OCS
  twin unless it is a download.
- **A grant reaches only whom a share could.** An owner who may not share grants nobody. Under the
  admin's "members only" setting a grantee shares a group with the owner, and with group sharing
  off no group is granted, as core's share checks read them ([Nextcloud integration](architecture.md#nextcloud-integration)). A grant hands over a
  movement profile, more than most shares do; the picker offering no one else is not the check.
  A reminder recipient follows the same rule (`Sharable`), since a reminder names the plate, and is
  refused in the words of a missing account, so the list neither enumerates accounts nor mails past
  "members only". Whoever already sees the vehicle passes: they know the plate, and the probe tells
  an editor only who else uses a car they edit.
- **A grant does not outlive its grantee.** Nextcloud lets a deleted uid or group id be taken
  again, so a deleted account's grants are renamed to its pseudonym and a deleted group's are
  revoked ([data model](architecture.md#data-model)). Otherwise whoever creates a group under an
  old id inherits every car it reached.
- **A refusal is a 403 and an unknown uuid a 404**, which does tell a caller that a uuid exists.
  That is a trade we accept, and it costs nothing: a v4 uuid is not guessed, it is leaked —
  and whoever leaked it also leaked the answer.
- **File downloads are proxied** ([Nextcloud integration](architecture.md#nextcloud-integration)).
  The app's ACL decides, not the file's. That makes attaching the gate on the file side: a
  `file_id` is taken only if it is a file in the attacher's own Files — owned by them and in their
  home storage, since a group folder or an admin's external storage names whoever asks as the
  owner ([documents](architecture.md#documents)). Otherwise any id on the instance would open somebody
  else's Files to everyone who may view one vehicle. The access rule is asked before the file, so
  a refused attacher learns nothing about an id. The download asks the same again: a file moved
  out of its attacher's own Files — into a share, a group folder or an external storage — is a 404,
  because somebody else can change it there. The [inbox](architecture.md#the-inbox) folder
  follows the same rule: a folder of the user's own Files, never a share or a group folder.

### The API

The OCS API ([api](api.md)) is a second door to the same services. It decides nothing itself, so
every rule above holds through it, and the IDOR matrix walks both doors. What it adds:

- **A stolen app password is the account.** It acts as the user across Nextcloud, not only in this
  app, and the app cannot narrow it. Nextcloud is the defence: Login Flow v2 hands one out without
  the client seeing the login password and behind the second factor, a wrong one counts towards
  brute-force protection, and the user revokes it under Devices & sessions, which ends the client's
  access at its next call.
- **Sync hands over everything at once.** A first sync, or one after a `reset`, is every row of
  every vehicle the caller reaches: the movement profile in a few pages, the CSV export by another
  door. It reaches what `VehicleAccess` lets the caller reach and no more; the cursor is opaque but
  no credential, since every id in it is checked against that again. It is limited to 60 calls a
  minute and 2000 rows a page. Each page is logged as an export is ([what is logged](#what-is-logged)).
- **Each door has its own rate limit.** Nextcloud counts per controller method, so a user can make
  60 writes a minute through each door. Accepted: both doors stand behind the same login.
- **A client's cache is the client's to protect.** Sync exists so that a client can keep a copy;
  once it is on a phone, nothing here protects it ([the client keeps nothing](#the-client-keeps-nothing)).

### Hostile content

- **Uploads are Files, and that is the point.** They land in the user's storage, so the admin's
  antivirus app (ClamAV) scans them if one is installed. We never build our own scanner and never
  bypass Files to write somewhere unscanned.
- **Nothing uploaded is ever rendered inline.** Downloads go out as an attachment
  (`Content-Disposition`) with `X-Content-Type-Options: nosniff`, and the client-supplied MIME type
  is never trusted. **An SVG served inline from our origin is script execution** — a receipt is a
  plausible SVG, so this is not theoretical. The screen saves a paper through a blob URL, which is
  of our origin too, so the blob is typed `application/octet-stream` (`src/utils/papers.js`).
- **CSV export is escaped against formula injection.** A field starting with `=`, `+`, `-`, `@`, tab
  or CR gets a leading apostrophe. Otherwise a trip named `=cmd|…` runs when a colleague opens the
  export in Excel — the classic bug in an app whose main output is CSV.
- **CSV import is bounded** (`lib/Import/CsvReader.php`): at most 5 000 000 bytes, 20 000 data
  rows and 500 000 cells (header included), read streaming, each byte scanned once. A row over
  64 KiB or 256 cells, a NUL byte, an unclosed quote, CR-only line breaks, or text that is not all
  UTF-8 (BOM or not) or all Windows-1252 refuses the file; nothing else is guessed. No archives, no `unserialize`. A cell is
  text until a field parses it: nothing is evaluated. The file is one the caller owns in their own
  Files, checked as a paper's is (`OwnFiles`); a refused file is a 422 naming the reason, never a
  500.
- **Reports load no remote resources.** v1 renders printable HTML and ships no PDF library
  ([ADR 0005](adr/0005-no-pdf-library.md)), which removes this surface rather than defending it. The
  rule stands for the day a server-side renderer arrives: a renderer that fetches URLs is an SSRF
  hole with a friendly name. The printable page inlines its own CSS and references nothing external.
- **Never `v-html`**, anywhere, for anything. Every value on screen came from a person. Vue escapes
  what it shows, so `src/utils/l10n.js` translates without escaping or sanitizing: a name reads as
  typed, not as `O&#39;Brien`. `l10n.spec.js` fails on a raw HTML sink or on the library's own `t`.

### The client keeps nothing

The entry sheet writes no `localStorage` ([entry sheet](ui.md#the-entry-sheet-in-detail)). A durable
offline queue would hold destinations, purposes and partner names in a browser, on a phone that may
be shared, lost or logged out of — the app's most sensitive data, in its least controlled place, for
a problem an open form already solves. The web UI is the client meant here. The Android client is
where a real queue arrives, and a synced copy of the fleet with it: it brings this threat along and
gets its own review ([the API](#the-api)).

The server-time header ([time](architecture.md#time)) is a diagnostic. It never rewrites a
user-entered date, so a wrong or hostile clock cannot silently move a logbook entry.

### Things that are visible in the real world

- **The QR sticker ([ui](ui.md#the-qr-shortcut)) is not a credential.** It sits behind a
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

- Lockfiles committed, `npm ci` in CI, `npm audit` (on what ships) and `composer audit` as gates,
  Renovate for updates. Every dependency added is a decision, not a convenience.
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
- **Every input has a bound** (`lib/Service/Field.php`), refused with a 400 naming the field, never
  truncated or clamped. Free text: 10 000 characters (notes, handover notes); names and labels
  their column's width. Text that is not UTF-8 is refused too: a form-encoded body can carry such
  bytes, and a stored row with them would break every JSON answer that carries it. Money: 10^12 cents. Counters and distances: 10^9. Fill-up amounts:
  10^12 ml or Wh; tank and battery 10^9. VAT rates up to 100 %, months up to 1 200, instants
  up to the year 9999. Words come from a fixed list; dismissed hints hold 1 000 vehicles. The
  import reads a cell past these bounds as unreadable, so its preview refuses the row the write
  would. `Field::read` throws a `LogicException` (a 500) for a text or a count read without its
  bound, and `BoundsTest` fails for a writable column that has none.
- **A booking spans at most 90 days, ends at most a year ahead, and starts no earlier than 5
  minutes ago.** A change may keep a start that has gone by, so someone running late can still
  edit their booking, and a booking made before these bounds keeps its whole span.
- **Logs never contain destinations, purposes or tokens.** Errors carry ids, not content.
- **Export endpoints are rate-limited and logged.** A full trip CSV is the most sensitive artefact
  this app can produce, and it is one request away. Sync is rate-limited and logged too
  ([what is logged](#what-is-logged)).
- `occ` commands never take secrets as arguments — shell history keeps them.

### What is logged

Data that leaves or enters in bulk leaves one line at `info` per request. The CSV export, each sync
page and each import name who, which vehicle — "all reachable" for sync — what (table and year, or
importer and record type) and how many rows. The logbook and the mileage claim name who, which
vehicle and which year. The personal data export names whose account and how many rows. Nothing
else: no payload, and no file name, which can say whose car it is.

`info` shows from `loglevel` 1; Nextcloud's default, 2, hides it, so an admin who wants the trail
turns it on. Not `warning`: these are normal actions, and a sync client alone writes a line every
few minutes. At warning level they would bury the warnings that need someone.

### Accepted risks, stated openly

A Nextcloud admin can read the database, and we do not encrypt trip data client-side: it would break
search, sorting and every report in [the maths](architecture.md#numbers-consumption-cost-emissions).
That is a deliberate trade, and it belongs in the README rather than in a footnote after an
incident.

The review of M6–M9 (2026-10-03) left these open on purpose:

- **A driver's maintenance record closes a reminder**, as anybody's does (M6). A driver can silence
  one by recording work that was not done. The record names who entered it, and deleting it opens
  the reminder again.
- **An Entry's author can undo a manager's delete or void of it.** Undo takes the delete's rule,
  and the author holds it for their own Entry. They could enter it again with `log` anyway; the
  audit keeps both moves.
- **Access is read before the vehicle is held.** A write that passed just before a revoke commits
  just after it: the same outcome as arriving a moment earlier.
- **Undoing an import writes no log line.** It soft-deletes only what the caller imported, so no
  data leaves or enters ([what is logged](#what-is-logged)).

The release review of 0.3.1 (2026-10-05) left these open on purpose:

- **A grantee reads the uids of the people on the vehicle.** The vehicle, its trips and its
  bookings carry `user_id` and `created_by`, which the screens need to say whose an entry is and
  the clients need to sync. A uid is a login name, but Nextcloud's own sharing autocomplete shows it
  to the same people. Only the list of who reads the vehicle (`GrantService::holders()`) sends
  display names alone, as it is the one list of people a grantee did not meet through a row.
- **Granting a group notifies every member.** Revoking and granting it again in a loop notifies
  them again, as a group share in Files does. The job tells only grants still live when it runs,
  and a revoke takes its notices back, so a loop yields at most one notice per cron run and
  vehicle.

### Process

A third-party app is not covered by Nextcloud's own bug bounty, so the reporting path has to be
ours. [`SECURITY.md`](../SECURITY.md) sends reports to GitHub's private vulnerability reporting,
never a public issue, and promises a first reply within 14 days; a confirmed issue is fixed in the
next release, with credit if the reporter wants it. No personal address is published (Johannes's
choice, 2026-10-03). `/security-review` on the diff before every release, and a dependency audit on
every merge to `main`.
