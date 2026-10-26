# Development

How the code is tested, how the dev stack works, and how a release goes out.
The quick start is in the [developer guide](developer/README.md).

## Testing

**The rule that makes testing possible:** never call `time()` or `new DateTime()` in app code.
Inject `ITimeFactory`.
A reminder engine you cannot move through time is one you cannot test.

| Layer | Tool | Covers |
|---|---|---|
| Static | Psalm with the `nextcloud/ocp` stubs, php-cs-fixer, ESLint, Stylelint, TypeScript | Types, private API, style |
| Unit | PHPUnit with mocked mappers, Vitest | The rules: odometer, reminders, consumption and cost, formatting |
| Integration | PHPUnit in a running Nextcloud with a real database | Migrations, queries, the 412, access by role |
| API | PHPUnit over HTTP with an app password (`tests/Api/`) | What a client gets: sign-in, writes, sync, import. A break of the contract fails CI. |
| E2E | Playwright against the dev stack | One slice per milestone, each role's screens, and every screen through axe |
| Mail | Mailpit | The digest renders and sends |
| Upgrade | `tools/upgrade-check.sh` | The data survives an upgrade ([release](#release)) |
| Country kit | `tests/Country/`, for every country, the UK fixture and `generic` | Each seam copes with "I don't know" ([ADR 0002](adr/0002-uk-is-a-test-jurisdiction.md)) |
| Security | The IDOR sweep, CSV escaping, `npm audit`, `composer audit` | The bugs that become a CVE ([security](security.md)) |

### The suites that need a server

The integration and API suites run inside the dev container.
It has no Composer, so call PHPUnit:

```bash
docker compose -f .docker/compose.yml exec -u www-data -w /var/www/html/custom_apps/nextfleet \
  app php vendor/bin/phpunit -c phpunit.integration.xml   # or -c phpunit.api.xml
```

- **Use a dev server only.**
  The schema test drops and rebuilds the app's tables.
- **The API suite is a client.**
  It loads no server class.
  It uses `occ` only for what a client cannot do: make an account and its app password.
- **No suite writes as `admin`.**
  That account holds the demo fleet.
  Each suite makes its own accounts and deletes them after, and again up front in case a crashed run left some.
  A crash can still leave a home folder behind; then every case fails with "files already exist for this user".
  Delete the folder.
- `UserMigrationTest` needs the *user_migration* app and skips without it.
- The integration schema test and the reminder E2E change `admin`'s rows.
  Run `occ nextfleet:seed admin` again after them.

### Tooling notes

- **A deprecation is an error.**
  Nextcloud removes what it deprecated a few majors later.
  Psalm fails on every `Deprecated*` issue, and the integration suite runs with `failOnDeprecation`.
  Tests reach a service with `\OCP\Server::get()`, never through the deprecated `IAppContainer`.
- **A mapper unit test needs `doctrine/dbal`,** because `IQueryBuilder`'s constants are DBAL values.
  It is a dev dependency on the major the server ships.
  The server's own copy wins at run time.
- **`openapi.json` is generated.**
  Run `composer openapi` after a change to an OCS controller or to `lib/ResponseDefinitions.php`, and commit the result.
  The extractor needs a newer `php-parser` than Psalm 5, so it lives in `vendor-bin/` ([the document](api.md#the-document)).
- **Psalm stays at 5.**
  Version 6 refuses to start below PHP 8.3.16, and distributions ship patched builds with an older number.
- **`@nextcloud/eslint-config` stays at 8.**
  Version 9 needs a flat `eslint.config.js`.
- **The main entry is one dynamic import of `src/boot.js`.**
  NC 31 loads the entry with `?v=…`, and a lazy chunk imports it without.
  The browser runs two copies.
  So `src/main.js` holds no state and mounts once.
  Otherwise the first file picker mounts the app again.
  Only NC 31 shows this.
- **A keyboard shortcut is `useHotKey`,** never a listener of your own.
  It honours the setting that turns shortcuts off, and ignores keys typed into a field or aimed at a dialog.
  Each sheet catches `Escape` itself, and each native date picker stops it, so `Escape` does not close a full form.
- **A rule written in PHP and JS reads its cases from one JSON table** in `tests/Fixture/`, so the twins cannot drift apart.
- Vitest transforms `@nextcloud/vue`, because Node refuses its `.css` imports.
  Mount its components with `shallowMount`.
- `actionlint` and `reuse` run through Docker: `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest` and `docker run --rm -v "$PWD":/data fsfe/reuse:latest lint`.
- `npm run docs:diagrams` renders every Mermaid block under `docs/`.
  It needs Docker and runs only locally.
  Its `[@zenuml/core] Store is a function` lines are noise.

### CI

GitHub Actions (`.github/workflows/ci.yml`).
Five Nextcloud majors times every PHP times three databases is too many jobs.
So PHP and the database are trimmed, never Nextcloud, because that is the axis users vary.

| When | Runs |
|---|---|
| Pull request | NC 31 with its oldest PHP and NC 35 with its newest, both on MariaDB, and NC 35 on SQLite. The oldest pair is where breakage hides. |
| Merge to `main` | Adds PostgreSQL on NC 35 |
| Weekly, or `gh workflow run ci.yml` | Adds every major on every PHP it accepts, *user_migration*, [Oracle](#oracle) on NC 35, the upgrade checks, and an E2E smoke run on NC 32 to 34 |

Static checks, the frontend checks and `reuse lint` run once each.
Vitest also runs in `America/Los_Angeles`, because UTC is the one zone where a local date never differs from a UTC date.
The job lists live in the `plan` job's environment, so `CiWorkflowTest` can check them: `actionlint` only proves GitHub will run the file, not that it runs the right thing.
Nothing marks the weekly run as allowed to fail: `continue-on-error` would show it green, which tells nobody anything.

### Oracle

Oracle runs on its own stack, `.docker/oracle/`, and in the weekly CI job.
No official image has `oci8`, so the Dockerfile adds Oracle's Instant Client.
Its licence forbids redistribution, so the image is never pushed.
The compose file's header says how to start it.
The integration suite then takes about five minutes; `down -v` throws the stack away.

What Oracle asks for:

- **No `IN` over 1 000 items.**
  Every list goes through `Db\InList`.
- **Sequence names are cut at 30 characters,** but core asks for the uncut name.
  Such a table takes its id from the sequence first (`BaseMapper::takeOracleId()`).
- **A bare column name turns to upper case** in raw SQL.
  Quote it with `getColumnName()`.
- **A string column counts bytes.**
  Typed text is 4n there ([data model](architecture.md#data-model)).

## Local dev environment

**Toolchain:** PHP 8.1+, Composer, Node 22.22+ or 24+, Docker.
`composer.json` pins its platform to PHP 8.1.31, so a lock file written on a newer PHP still installs on the oldest CI job.
On WSL2, a Windows browser reaches `localhost` in Ubuntu directly.
Keep the repository under `/home`, not `/mnt/c`: crossing file systems is slow.

The stack (`.docker/compose.yml`, project name `nextfleet`):

```
db        mariadb:11          the real database, not SQLite
app       nextcloud:35-apache port 8080, the repository mounted as custom_apps/nextfleet
app31     nextcloud:31-apache port 8081, the same mount
cron      runs cron.php every 5 minutes for app, so reminders fire
cron31    the same for app31
mail      axllent/mailpit     SMTP on :1025, web UI on :8025
```

- **The bind mount is the whole trick:** edit, reload.
  `npm run watch` rebuilds the frontend on save.
- Both majors share the database server and nothing else.
  Otherwise one would upgrade the other's install, and the gate would test one version twice.
- Each major needs its own cron runner.
  Without one, stale file locks pile up and a reused E2E path answers 423.
- Docker creates `custom_apps` as root.
  NC 31 then refuses to install ("Cannot write into apps directory"), so both app services fix the owner before they start.
- **Disable `firstrunwizard`** on both servers.
  Its modal blocks the first click in Playwright.
- **A migration runs only once.**
  Changing `lib/Migration/` does nothing on an install that ran it.
  Drop the tables, or `docker compose down -v`.
  The integration schema test drops them itself, which makes it the quickest way to try a change.

**Seed data:** `occ nextfleet:seed <user>` writes a demo fleet over the two years before the run.
It holds the awkward cases on purpose: a counter that went backwards, a plug-in hybrid, a vehicle in hours, a truck with engine hours, an unfinished vehicle, costs in every month, reminders and two papers.
It writes through the services, so a fleet it cannot write is a fleet the app cannot hold.
`--grant-to <uid>` makes the Passat shared, with a trip and a booking by that account.

**E2E:** `npm run test:e2e` runs one Playwright project per major, so a failure names the version.
`npm run test:e2e:docker` runs the same specs in Playwright's own image, when you cannot install Chromium's packages.
Its image tag must match `@playwright/test`; `playwright-pin.spec.js` checks it.

- `m0-gate.spec.js` is the gate: one bundle mounts on NC 31 and NC 35.
  Each `m<n>-slice.spec.js` drives one milestone's screens end to end, and `roles.spec.js` each role.
- **The reminder job at a moved clock:** `tests/e2e/job.php` runs `ReminderJob` with a stopped clock.
  The job sweeps every vehicle, so reseed afterwards.
- A test tagged `@nc35` runs on NC 35 only.
  The sweep at the end of `m5-slice.spec.js` checks every screen at 320 px and 1280 px, light and dark, with axe.
- Each spec owns a plate prefix (`E2E-`, `M2-E2E-` …) and deletes its own vehicles first.
  Keep the prefixes disjoint: the files run at once.
- What every spec shares lives in `tests/e2e/app.js`.

## Release

The maintainer's steps, on a machine that holds the signing key.

**Once:** request the app certificate from `nextcloud/app-certificate-requests` and keep the key offline ([supply chain](security.md#supply-chain)).
Register the app on apps.nextcloud.com.
Turn on private vulnerability reporting in the repository settings: [`SECURITY.md`](../SECURITY.md) sends reporters there.

**Each release:** `tools/release.sh` runs steps 2 to 6 in two phases and stops where you act.

- `npm run release -- prepare <YYYY-MM-DD>`: steps 2 and 3, then every check.
  Review its diff and commit.
- `npm run release -- build --key <path> --cert <path>`: steps 4 to 6, about an hour.
  It refuses a key inside the repository.

1. **Review and commit.**
   All checks pass.
   Run `/security-review` on the diff since the last release's tag ([process](security.md#process)), and fix what it finds.
2. **Date the CHANGELOG section.**
   `## <x> — not released` becomes `## <x> — <YYYY-MM-DD>`.
   The store shows only that section, so it must read whole on its own.
   `InfoXmlTest` fails until `info.xml`, `package.json` and the lock file agree.
3. **Promise the API.**
   Copy `openapi.json` over `tests/Api/v1-baseline.json`, and name the new promises in the CHANGELOG ([what v1 promises](api.md#what-v1-promises)).
4. **Build.**
   `npm run package` writes `build/artifacts/nextfleet-<x>.tar.gz`.
   If a screen changed, reseed, run `npm run screenshots:docker` and commit the pictures first.
5. **Sign.**
   `occ integrity:sign-app` reads the key from stdin, so it never lands in a container or the repository.
   The build phase signs on a throwaway NC 35 and checks the result with `occ integrity:check-app`.
6. **Check the upgrade** on that signed tarball: `npm run upgrade-check -- <tarball> [<base>]`.
   It takes four paths on throwaway servers: NC 31 fresh and over the last release, NC 34 the same, the last release on NC 34 with the server then upgraded to NC 35, and NC 35 fresh.
   It fails if a row is lost, the schema differs from a fresh install, or the app ends anything but enabled at the new version with nothing in `occ nextfleet:check` or the log ([`tools/upgrade-check.sh`](../tools/upgrade-check.sh) says what it allows).
   Run it again with `--db pgsql`, and with `--db oracle`, which takes only the two paths that end on NC 35.
7. **Publish the source.**
   Merge into `main` by pull request.
   Tag the commit `v<x>` and push the tag.
8. **Upload.**
   Attach the tarball to a GitHub release.
   On apps.nextcloud.com, *Upload app release* takes its URL and its signature: `openssl dgst -sha512 -sign nextfleet.key <tarball> | openssl base64`.
9. **Open the next version** with its first change: set it in `info.xml`, run `npm version <y> --no-git-tag-version`, and add `## <y> — not released`.

Next: the [design diagrams](design/README.md), then the [decision records](adr/).
