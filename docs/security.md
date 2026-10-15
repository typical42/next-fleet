# Security

The app holds a movement profile: where someone was, when, and why.
Treat it that way.

## Who attacks this

| Attacker | Wants | Gets in through |
|---|---|---|
| Another user on the instance | A colleague's trips | Guessable ids, missing access checks |
| A grantee | More than their role allows | Role checks done only in the UI |
| The open internet | Any endpoint without a login | Share links, the QR route, a route without an annotation |
| Someone who attaches a file | Code in a viewer's browser | Receipt photos, PDFs, **SVG** |
| Someone who imports a file | Server resources | The CSV import, the CSV export opened in Excel |
| A dependency | Everything, quietly | npm, Composer, GitHub Actions |
| A contributor | Everything, through the front door | A merge request is code in our next signed release |

## Authorization

Drawn in [design/flows.md#the-access-check](design/flows.md#the-access-check).

- **Every controller method is annotated on purpose.**
  `#[NoAdminRequired]` on user routes.
  `#[NoCSRFRequired]` only on a read-only page or download that the browser navigates to: a navigation carries no token, and the same-site cookie is still required.
  OCS routes need none.
  `#[UserRateLimit]` on every write.
  A method without annotations fails review.
- **One class decides access:** `VehicleAccess` ([access](architecture.md#access)).
  Controllers never reach a mapper, and no mapper trusts an id from a request body.
- **IDOR is the realistic bug.**
  The API is addressed by id, and grants make guessing worth it.
  So `VehicleIdorTest` asserts the stranger case on *every* route, read from `appinfo/routes.php`.
  `RouteSweepTest` fails when a route has no case there.
- **A grant reaches only whom a share could.**
  A grant hands over a movement profile, more than most shares do.
  The picker offering no one else is not the check: the server applies the sharing settings.
  A reminder recipient follows the same rule, because a reminder names the plate.
- **A grant does not outlive its grantee.**
  Nextcloud lets a deleted uid or group id be taken again.
  So a deleted account's grants are pseudonymised and a deleted group's are revoked ([deleting and erasing](architecture.md#deleting-and-erasing)).
- **A refusal is a 403, an unknown uuid a 404.**
  That tells a caller that a uuid exists.
  We accept it: a v4 uuid is not guessed but leaked, and whoever leaked it leaked the answer too.
- **Downloads are proxied, so attaching is the gate.**
  The app's access decides, not the file's.
  So only a file in the attacher's own home storage can be attached, and the download checks that again ([documents](architecture.md#documents)).
  Otherwise any file id would open somebody else's Files to everyone who sees one vehicle.

## The API

The OCS API ([api.md](api.md)) is a second door to the same services.
Every rule above holds through it.
What it adds:

- **A stolen app password is the account**, in all of Nextcloud, and the app cannot narrow it.
  Nextcloud is the defence: Login Flow v2 behind the second factor, brute-force protection, and revoking under Devices & sessions.
- **Sync hands over everything at once.**
  A first sync is the movement profile in a few pages.
  It reaches only what `VehicleAccess` allows.
  The cursor is no credential: every id in it is checked again.
  Each page is rate-limited and logged.
- **Each door has its own rate limit.**
  Accepted: both stand behind the same login.
- **A client's cache is the client's to protect** ([the client keeps nothing](#the-client-keeps-nothing)).

## Hostile content

- **Attachments are Files, and that is the point.**
  They sit in the user's storage, where the admin's antivirus scans them.
  We build no scanner and write nothing outside Files.
- **Nothing attached is ever shown inline.**
  Downloads go out as an attachment with `X-Content-Type-Options: nosniff`, and the client's MIME type is never trusted.
  **An SVG served inline from our origin is script execution.**
  The screen saves a paper through a blob typed `application/octet-stream` for the same reason.
- **CSV export defuses formulas.**
  Text that starts with `=`, `+`, `-`, `@`, tab or CR gets a leading apostrophe, also after a `;`, a tab or a line break inside a cell.
  Excel in a German locale splits on `;`, so quoting alone does not help.
  Otherwise a trip named `=cmd|…` runs when a colleague opens the file.
- **CSV import is bounded** (`CsvReader`): 5 000 000 bytes, 20 000 rows, 500 000 cells, read as a stream.
  A row over 64 KiB or 256 cells, a NUL byte, an open quote, or text that is neither UTF-8 nor Windows-1252 refuses the file.
  No archives, no `unserialize`.
  A cell is text until a field parses it.
  A refusal is a 422 with its reason, never a 500.
- **Reports load nothing remote.** v1 ships no PDF library ([ADR 0005](adr/0005-no-pdf-library.md)).
  A renderer that fetches URLs is an SSRF hole with a friendly name.
- **Never `v-html`.**
  Every value on screen came from a person.
  Vue escapes it, so `l10n.js` translates without escaping: a name reads as typed.
  `l10n.spec.js` fails on a raw HTML sink.

## The client keeps nothing

The entry sheet writes nothing to `localStorage`.
An offline queue would hold destinations, purposes and partners on a phone that may be shared or lost: the most sensitive data in the least controlled place.
The Android client will bring a real queue, and with it its own review.

The server time header is a diagnostic.
It never moves a date the user entered, so a wrong clock cannot move a logbook entry.

## Things that are visible in the real world

- **The QR sticker is not a credential.**
  It sits behind a windscreen.
  It carries a vehicle id and nothing else, and opening it needs a Nextcloud login.
  A token in it would hand the car park write access.
- **A public service-history link**, if it ever comes, gets a 128-bit random token, can be revoked, carries no trips or drivers, and is `noindex`.
- **Geocoding stays off by default.**
  Each lookup sends an address to a third party.
- **Outgoing webhooks** check the URL, refuse private address ranges and follow no redirects.

## Supply chain

- Lock files committed, `npm ci` in CI, `npm audit` (on what ships) and `composer audit` as gates.
  Each new dependency is a decision.
- **GitHub Actions pinned to commit SHAs**, a least-privilege `GITHUB_TOKEN`, no `pull_request_target` that checks out PR code.
  A compromised action owns the release.
- **Releases are signed** with the app certificate.
  The private key stays offline, never on CI. 2FA on the GitHub and app store accounts, `main` protected.

## Review is the only gate

There is no plugin system, so code reaches the app only by merge request ([contributing](contributing.md)).
Country directories are the easiest place to slip something in: long, full of numbers, boring to read.
So a country is reviewed as code: no network, no file access, no `exec`, no own dependencies, a source for every rate.
Our signature goes on whatever we merge.

## Discipline

- No raw SQL: QueryBuilder with bound parameters.
  No `exec`, no `eval`.
- **Every input has a bound** (`lib/Service/Field.php`), refused with a 400 that names the field, never cut or clamped.
  Free text 10 000 characters, money 10^12 cents, counters 10^9, years up to
  9999. Text that is not UTF-8 is refused: it would break every JSON answer that carries it.
        `BoundsTest` fails for a writable column without a bound.
- **A booking** spans 90 days at most, ends within a year, and starts no earlier than 5 minutes ago.
- **Logs never hold destinations, purposes or tokens.**
  Errors carry ids.
- `occ` commands never take secrets as arguments: the shell history keeps them.

## What is logged

Data that leaves or enters in bulk writes one `info` line per request: the CSV export, each sync page, each import, the logbook, the mileage claim and the personal data export.
The line names who, which vehicle, what and how many rows.
Never a payload or a file name.

`info` shows from `loglevel` 1.
Nextcloud's default 2 hides it, so an admin who wants the trail turns it on.
Not `warning`: these are normal actions, and a sync client writes one every few minutes.

## Accepted risks

- **An admin can read the database.**
  Trip data is not encrypted client-side: that would break search, sorting and every report.
  The README says so.
- **A driver's maintenance record closes a reminder.**
  A driver could silence one with work not done.
  The record names who entered it, and deleting it reopens the reminder.
- **An author can undo a manager's delete of their own entry.**
  They could enter it again anyway.
  The audit keeps both.
- **Access is read before the vehicle is held.**
  A write just before a revoke commits just after it, as if it came a moment earlier.
- **Undoing an import writes no log line.**
  No data leaves or enters.
- **A grantee reads the uids of the people on the vehicle.**
  Rows carry `created_by`, which screens and sync need.
  Nextcloud's sharing autocomplete shows uids to the same people.
- **Granting a group notifies every member.**
  A grant-revoke loop yields at most one notice per cron run, because a revoke withdraws its notice.

## Process

A third-party app is not in Nextcloud's bug bounty, so the reporting path is ours.
[`SECURITY.md`](../SECURITY.md) sends reports to GitHub's private vulnerability reporting, with a first reply within 14 days.
Every release runs `/security-review` on its diff, and every merge to `main` runs a dependency audit.

Next: [Licensing and legal](legal.md)
