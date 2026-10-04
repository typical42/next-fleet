# Development

Part of the [NextFleet plan](../plan.md). Terms are defined in [CONTEXT.md](../CONTEXT.md).

## Testing

**Design rule that makes testing possible:** never call `time()` or `new DateTime()` in app code.
Inject `OCP\AppFramework\Utility\ITimeFactory` everywhere. A reminder engine you cannot move through
time is a reminder engine you cannot test.

| Layer | Tool | What it covers |
|---|---|---|
| Static | Psalm (with the `nextcloud/ocp` stubs), php-cs-fixer + `nextcloud/coding-standard`, ESLint/Stylelint (`@nextcloud/eslint-config`), TypeScript | Wrong types, private API use, style |
| Unit | PHPUnit, mappers mocked | The logic worth trusting: odometer derivation and the observed-beats-derived rule, due-date/km evaluation, recurrence from actual completion, consumption and cost per 100 km, mileage projection and its data floor |
| Integration | PHPUnit inside a running Nextcloud container, real DB | Migrations, QBMapper queries, optimistic concurrency (a stale `updated_at` must 412), the access layer (owner vs. role vs. stranger) |
| API | PHPUnit over HTTP with an app password (`tests/Api/`) | What a client gets from the [OCS API](api.md): signing in, a write, a 412, a download, sync across a delete and its restore, an import; the M8 slice, an owner and a driver kept in step by sync. A breaking change to the contract must fail CI |
| Frontend | Vitest for stores and pure components | Consumption/cost formatting, form validation |
| E2E | Playwright against the dev container | A slice per milestone with a screen, from creating a vehicle to the import (M8's slice is the API suite's), each role's screens, and every screen through axe ([the specs](#local-dev-environment)) |
| Mail | Mailpit as the SMTP sink | The digest actually renders and sends |
| Upgrade | Install version N-1, run `occ upgrade`, assert data survives | Migration mistakes, the class of bug users never forgive |
| Country kit | One shared `tests/Country/` suite every jurisdiction must pass, including the UK fixture and the generic profile | That a merged jurisdiction is provably wired up, and that every seam tolerates one answering "I don't know" ([ADR 0002](adr/0002-uk-is-a-test-jurisdiction.md)) |
| Security | An IDOR sweep asserting the stranger case on every endpoint, a CSV-export escaping test, and `npm audit`/`composer audit` as merge gates ([security](security.md)) | The bugs that end up in a CVE rather than an issue |
| Accessibility | axe inside the Playwright run | A table-heavy app fails keyboard and screen-reader use easily, and this audience notices |

**A mapper unit test needs `doctrine/dbal`.** `IQueryBuilder` defines its `PARAM_*` constants as
`Doctrine\DBAL\ParameterType` values, so mocking the interface loads that class, and `nextcloud/ocp`
does not bring it. It is a `require-dev` pinned to the major the server ships (3.x); the server's
own copy wins at runtime, ours only feeds the tests.

**The integration suite needs a server**, so it has its own bootstrap and config and runs inside the
dev container:

```bash
docker compose -f .docker/compose.yml exec -u www-data -w /var/www/html/custom_apps/nextfleet \
  app php vendor/bin/phpunit -c phpunit.integration.xml
```

`NEXTCLOUD_ROOT` says where the server is, `/var/www/html` by default. The schema test drops and
rebuilds the app's tables, so run it against a dev instance and nothing else.

`UserMigrationTest` runs the [personal data export](architecture.md#personal-data-export) the way
`occ user:export` does, through Nextcloud's *user_migration* app, and imports the archive into a
fresh account. It skips without that app; `occ app:install user_migration` on a dev server runs it,
and no other suite is affected.

**The API suite is a client.** It loads no server class: it calls `http://localhost` with curl and
uses `occ` only for what a client cannot do: make a fresh account and its app password, as
`user:auth-tokens:add` does on 31 and 34 alike, delete the accounts again, and forgive its failed
logins. Same place, its own config:

```bash
docker compose -f .docker/compose.yml exec -u www-data -w /var/www/html/custom_apps/nextfleet \
  app php vendor/bin/phpunit -c phpunit.api.xml
```

`NEXTFLEET_API_URL` overrides the address. The failed logins forgiven are the ones its 401 case
counts against `127.0.0.1` and `::1` — Nextcloud slows every later login from an address that
failed, the E2E run's included.

**No suite writes as `admin`.** Its fleet is the demo, and a dev server holds more than tests. Each
suite makes accounts of its own and deletes them when it is done, and again up front in case a
crashed run left some. Deleting an account takes its app passwords and its home folder with it; the
app keeps its rows under a pseudonym ([ADR 0008](adr/0008-erasing-a-driver-pseudonymises.md)).

| Suite | Its accounts | Deleted by |
|---|---|---|
| Integration | `nextfleet-test-…`, one fixed set per class | the class, after its last test or after each |
| API | `nextfleet-api-…` and `nextfleet-m8-…`, a random tail each | the class, after its last test |
| E2E | `nextfleet-e2e-<ms>`, the run's own, in the `admin` group so it can make the rest; `m4e2e-` to `m9e2e-` and `rolese2e-`, each spec file's | `tests/e2e/accounts.js`, Playwright's global setup and teardown |
| Unit, country | none | — |

The E2E setup deletes every such account it finds, a running run's included: one E2E run per
server at a time.

`demo-fleet.spec.js` and `tools/screenshots.mjs` sign in as `admin` to read the seeded fleet, and sign
out, which deletes the session. Two things still reach `admin`'s rows: the integration schema test
drops the app's tables, and the M4 slice runs the reminder job over every vehicle at a moved clock.
Reseed after either.

**`openapi.json` is generated** ([api](api.md#the-document)): `composer openapi` after any change to
an OCS controller or to `lib/ResponseDefinitions.php`, and commit the result. The extractor needs
`nikic/php-parser` 5 and Psalm 5 pins 4, so it lives in `vendor-bin/openapi-extractor/` behind
`bamarni/composer-bin-plugin`; `composer install` installs it too. The unit suite then holds the
result to the v1 baseline ([api](api.md#what-v1-promises)).

PHPUnit loads our autoloader first, stubs included, and `lib/base.php` then puts the server's own
in front of it — so `OCP\` resolves to the running server and not to the pinned stubs. That is the
server's doing, not ours, and it holds on 31 and 34 alike; `tests/Integration/AutoloadingTest.php`
is there so a version that changes it says so.

`ISchemaWrapper` gained `dropAutoincrementColumn()` in NC 33. The stubs are older and do not have
it, so a test double implementing that interface has to declare it anyway — without it the class is
abstract, and fatal, on a newer server.

Migration 4 calls `setPrimaryKey(['id'], $primaryKey ?? false)`. `false` is DBAL 3's "no name";
DBAL 4 takes `?string` there and would throw. No supported Nextcloud ships DBAL 4, and migrations
are frozen, so it stays; a server on DBAL 4 needs it changed to `null` first.

**CI matrix** (GitHub Actions). Four supported Nextcloud majors times every PHP version times three
databases is dozens of jobs, so the trim happens on the PHP and database axes — never on Nextcloud,
because that is the axis users actually vary.

| When | Combinations |
|---|---|
| Pull request | Three: NC 31 with the oldest PHP it supports, and NC 34 with the newest, MariaDB both — the oldest combination is where breakage hides, so it belongs on every PR rather than in a nightly nobody reads — and NC 34 on SQLite, which a first try runs on and which accepts the least |
| Merge to `main` | Add PostgreSQL, on NC 34 |
| Weekly | The fuller matrix with *user_migration*, plus Oracle, the upgrade check and an E2E smoke run on NC 32 and NC 33, allowed to fail loudly without blocking anyone |

`.github/workflows/ci.yml` implements it. Alongside the matrix run — a Nextcloud checkout, a real
database, `occ maintenance:install`, `occ app:enable`, then the unit and integration suites, which is
where the schema meets PostgreSQL and SQLite, then `php -S localhost:8080` in front of the checkout
(four workers, so the suite's two requests at once can meet) and the API suite against it — three
jobs run once each:
static analysis with `composer lint`, `composer audit` and a fresh `openapi.json` diffed against the
committed one, the frontend checks — Vitest twice, the second time in `America/Los_Angeles`, since
a runner's UTC is the one zone where a local date and a UTC date never differ — and `reuse lint`.
The weekly run installs *user_migration* in every matrix job before the integration suite, and adds
[Oracle](#oracle), the [upgrade check](#release) from 0.2.0 and from 0.3.0 on MariaDB and on
PostgreSQL, and an E2E smoke run on NC 32 and NC 33: the stack in
`.docker/weekly/compose.yml`, the M0 gate and the M1 slice, with `NEXTFLEET_URL_NC32` and
`NEXTFLEET_URL_NC33` adding those two projects to `playwright.config.js`. A `plan` job picks the
combinations for the event that triggered the run; the three lists sit in its environment as JSON
so `tests/Unit/CiWorkflowTest.php` can read them, because `actionlint` only proves GitHub will run
the file, not that it runs the right thing.

Nothing in the file marks the weekly run as allowed to fail. It blocks nobody already — no merge
waits on a scheduled run — and `continue-on-error` would conclude it green, which is the one outcome
that tells nobody.

Which PHP a major accepts comes from its own `lib/versioncheck.php`: 31 and 32 take 8.1 to 8.4, 33
and 34 take 8.2 to 8.5. So "NC 34 with the newest PHP" is 8.5, which is what the `nextcloud:34-apache`
image ships anyway.

Neither `actionlint` nor `reuse` is installed here — both want a package manager that needs root —
so run them through docker:

```bash
docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest
docker run --rm -v "$PWD":/data fsfe/reuse:latest lint
```

REUSE reads test files too, so a line that merely quotes an SPDX identifier has to be fenced; see
[legal](legal.md).

The E2E job starts the compose stack on the runner and waits for it with `docker compose up
--wait`, which means *installed* only because both app services carry a healthcheck asking
`status.php` — apache answers long before Nextcloud does, and answers even when the install failed.
It builds the bundle first: the specs assert what Vue mounted, and an unbuilt `js/` leaves the root
empty.

There is no `krankerl`: `InfoXmlTest` validates `info.xml` against the store's own schema, and
`tools/package.sh` builds the tarball and `tools/upgrade-check.sh` installs it
([release](#release)).

**The M0 gate is a build, not a test.** One frontend bundle must build and run against NC 31 and
NC 34 before any feature work starts ([milestones](../plan.md#milestones)). `@nextcloud/vue` moves
fast and a component present in 34 may be missing in 31 — which is why
[the interface](ui.md#look-like-nextcloud-not-like-fleet-software) restricts itself to the old,
stable components. If no single bundle spans the range, the 31 floor moves; the UI does not.

**Seed data:** `occ nextfleet:seed <user>` writes a demo fleet spanning the two years before it runs
— including the awkward rows: a flagged backwards odometer, a plug-in hybrid with both energy
types, a vehicle counted in hours, a truck that counts engine hours beside its kilometres, and one
nobody finished creating, which is what the "complete this vehicle" hint has to ask about
([ui](ui.md#details-that-decide-whether-it-feels-easy)). The last year carries costs: fill-ups with
a partial and a `missed_previous`, the hybrid charging at home and in public, maintenance, expenses
with a stated VAT rate and without one, and a vehicle with purchase price and
residual, so every header figure has something to show. Three reminders look the other way: an
HU/AU three weeks out, an oil change by kilometres with the Readings an estimated date needs, and
a truck the scheme inspects every 12 months. The Passat has a cost in every month of that year, so
the Costs screen has bars; a business trip for the mileage claim and a private one it leaves out;
and two papers in the seeding account's Files under `Fleet demo/`: the Fahrzeugschein, and the
HU/AU invoice linked to its maintenance record. It powers screenshots for the app store, manual
clicking and `demo-fleet.spec.js`. The other E2E specs write their own rows, in a year that is
already over when a figure is read: the demo is dated back from the seeding day, so its months move
with that day.

It writes through the services a request writes through, so a fleet it cannot produce is a fleet the
app cannot hold, and the odometer rules decide the flags rather than the fixture. Every vehicle
states `de`, whatever the account seeding it has chosen: the plates, the VAT rates and the HU/AU are
one country's, and a profile without an inspection scheme has no HU/AU to write. Every plate starts
`NF-`, which keeps the demo out of the way of the E2E's `E2E-`; a re-run retires the rows it is
about to write again, and leaves anything else parked next to them alone — a vehicle another
account owns included.

`--grant-to <uid>` makes the Passat a car two people use: that account becomes its driver and
enters one business trip, so the timeline and the Fahrtenbuch name who entered each trip, and the
account's overview shows the Passat with "Owned by" beneath. It sends the grant's notification.
The account also books the Passat for tomorrow 09:00–12:00, Berlin time, so the Bookings section has
a coming booking to show. The grant follows the admin's sharing settings, as one from the screen
does: with sharing off for the seeding account, or "share only with group members" on and no group
in common, the fleet is still seeded, but the run exits 1 with a sentence saying why and writes no
trip or booking for the account.

Testing the reminder job by waiting is not testing. Move the clock, then run
`occ background-job:list` / `background-job:execute <id>` to fire the job on demand.

### Oracle

Oracle runs on a stack of its own, `.docker/oracle/`, and in CI's weekly `oracle` job. No official
Nextcloud image carries `oci8`, so the Dockerfile adds Oracle's Instant Client at build time (free to
use, not to redistribute, so the image is never pushed) and `oci8` from PECL, which it left php-src
for in PHP 8.4. The database is `gvenzl/oracle-free:23-slim-faststart`. The compose file's header
starts and installs it; then the suite, about five minutes:

```bash
docker compose -f .docker/oracle/compose.yml exec -u www-data -w /var/www/html/custom_apps/nextfleet \
  app php vendor/bin/phpunit -c phpunit.integration.xml
```

`down -v` throws the stack away; it never touches the dev stack's volumes.

First tried 2026-10-04 on NC 34 (PHP 8.5, oci8 3.4.1, Oracle Free 23): install, `app:enable` with
migrations 1–7, the seed and the integration suite. What Oracle asked for:

- **No `IN` over 1 000 items** (ORA-01795). Every list in a query goes through `Db\InList`, and
  `InListTest` calls each list-taking mapper method with 2 500.
- **Sequence names are cut at 30 characters.** Doctrine names the key's sequence
  `oc_fleet_reminder_recipien_SEQ`; core's `lastInsertId()` asks for the uncut name. Such a table
  takes its id from the sequence first (`BaseMapper::takeOracleId()`).
- **A bare column name folds to upper case** in raw SQL. Quote it with `getColumnName()`.
- **Introspection** reports no autoincrement and quotes index columns; `SchemaExpectations`
  allows for both.

What Oracle covers, measured 2026-10-04:

| Check | Result |
| --- | --- |
| Integration suite, five fresh stacks in a row | 1595 tests green each time, 5:13–5:31 |
| API suite, the same stack after it | 19 tests green, 33 s |
| Upgrade check from 0.2.0, NC 34 only (`--db oracle`, [release](#release)) | 12 tables' rows intact, schema the fresh one's |
| E2E | not run |

The API suite runs as on the dev stack, with `-c phpunit.api.xml`. M12 once saw a first run end
with 10 errors and a failure. Its output was not kept, and the five runs above did not repeat it.
If the weekly job goes red that way, read its log first.

0.2.0 and 0.3.0 could not create a vehicle on Oracle, because of the sequence name above. So the
upgrade check gives the base a synonym under the uncut name, which lets it write its rows. The
check drops the synonym before the upgrade.

## Local dev environment

Yes, this works, and the browser part is free: WSL2 forwards `localhost`, so anything listening in
Ubuntu is reachable at `http://localhost:8080` from a Windows browser. No port mapping, no IP
lookup.

**Toolchain:** PHP 8.1+, Composer, Node 22.22+ or 24+ (CI runs 24; `@types/node` follows the
22 floor) and Docker. `composer.json` pins its resolution
platform to PHP 8.1.31 — the oldest major NC 31 supports, at its last patch — so a lock file
written on a newer PHP still installs on the oldest CI job.

Psalm is held at 5.x. From 6.x it refuses to start below a PHP *patch* level — 8.3.16 — and
distributions ship security-patched builds that keep the old number, so the 8.3.6 in Ubuntu 24.04
cannot run it. Composer will not stop you: the platform pin above is 8.1.31, which satisfies
Psalm 6, so a bump installs and then dies at startup. Raise it once the PHP here comes from a
source that tracks patch releases.

php-cs-fixer formats every PHP file git tracks, `appinfo/` and `templates/` included; `vendor/`,
`node_modules/` and anything `.gitignore` names are skipped. On any PHP newer than the 8.1 floor it
prints a warning about that gap on every run; it goes to stderr and the exit status stays 0.

Psalm analyses `lib/`, `templates/`, `tests/` and `appinfo/routes.php`. Templates call `script()`,
which belongs to Nextcloud's legacy template layer rather than to OCP and so is in no package here;
`tests/Stub/template_functions.php` declares it for both Psalm and PHPUnit.

**A deprecation is an error.** Nextcloud removes what it deprecated a few majors later, so Psalm
fails on every `Deprecated*` issue, tests included, and the integration suite runs with
`failOnDeprecation`, which catches what PHP deprecates at run time in `lib/`; the server's own
deprecations are left out (`restrictDeprecations`), since nothing here can fix them. Tests reach a
service with `\OCP\Server::get()`, which hands an `OCA\NextFleet\` class to the app's container,
never through the deprecated `IAppContainer`. The one exception is `tests/e2e/job.php`, which
swaps the app container's clock: OCP has no other way to replace a service after boot.

`npm run build` bundles one entry per page the app puts a bundle on: `src/main.js` into
`js/nextfleet-main.mjs` for the app itself, and `src/settings.js` into `js/nextfleet-settings.mjs`
for its block on the user's settings page. Vite names each output after its entry key in
`vite.config.js`, which is what the template then asks `script()` for. `npm run watch` does the
same in development mode and rebuilds on save. Two bits of noise to ignore: `@nextcloud/vite-config`
sets `outDir` to the repo root on purpose, so that every build prints Vite's "build.outDir must not
be … a parent directory of root"; and its polyfill chain pulls in `elliptic` and `crypto-browserify`,
so `npm audit` reports seven low-severity advisories with no upstream fix. Gate CI at `--audit-level
moderate` rather than muting the tool.

**The main entry is one dynamic import of `src/boot.js`.** NC 31 loads an entry as
`nextfleet-main.mjs?v=…`, while a lazy chunk (the file picker, a date locale) imports Vite's preload
helper from `./nextfleet-main.mjs`. The browser treats those as two modules, so a second copy of the
entry runs. `src/main.js` therefore holds nothing with state and mounts once. Were the app inside the
entry, the second copy would mount it again the first time a picker opened, and the screen would
fall back to the overview. NC 34 does not show it; only an NC 31 browser does.

The build writes hashed `css/*.chunk.css` beside the hand-written `css/app.css`: `@nextcloud/vue`
ships a stylesheet the bundle does not carry. The main entry's stylesheets load with its dynamic
import, so `templates/main.php` asks only for the script. The settings entry imports statically, so
the build writes `css/nextfleet-settings.css` and `templates/personal.php` asks for both. The
generated files are gitignored by pattern — a new entry needs no new ignore line — and skipped by
Stylelint; `css/app.css` is the only source there. The chunks carry a content hash and the build
cannot empty a directory it shares with sources, so old ones pile up in `css/` and `js/`. Delete
them when they bother you; nothing reads them.

`npm run lint` runs all three static frontend checks in turn — ESLint, Stylelint, then `tsc
--noEmit` — and `npm run lint:js`, `lint:css` and `lint:types` run them one at a time. `npm test`
is Vitest; frontend tests sit next to what they test as `src/**/*.spec.js`, and `tests/js/` holds
the ones about the repository itself rather than about the app. `tests/e2e/` is Playwright's and
stays out of the Vitest run; everything else under `tests/` is PHPUnit's.

Vitest transforms `@nextcloud/vue` rather than letting Node load it (`server.deps.inline` in
`vitest.config.js`). Its components import their own `.css`, which Node refuses with "Unknown file
extension" — as a suite-level import error, so the message names the component and not the cause.
A spec that mounts one also wants `shallowMount`: rendering `NcSelect` for real pulls in the whole
library, and reading a stub's props says more about this app than its markup does.

**A keyboard shortcut is `useHotKey` from `@nextcloud/vue`, never a listener of your own.** It
honours the accessibility setting that turns shortcuts off, ignores a keystroke typed into a field,
and ignores one aimed at an open dialog — so `n` belongs to the screen in view rather than to an
arbiter above it, and a second screen needs no coordination. Its blind spot is `Escape`: `NcDialog`
closes itself through the same composable, so the key does nothing while the caret is in a text
field, which is where every sheet here opens. Each sheet therefore catches `Escape` inside its own
content and stops it, and an open `NcSelect` stops it first so its dropdown still closes on its own.
`NcDateTimePickerNative` does not, so each one is given `@keydown.esc.stop`: the browser draws its
picker over the input and closes it on the key, but the keydown lands on the input either way, and
the sheet would take it and close over every filled-in field. Nothing says whether a native picker
is open, so a date field keeps `Escape` whether one is or not.

`@nextcloud/eslint-config` is held at 8.x: version 9 needs ESLint 10 and Node 22's
`findPackageJSON`. The Node floor allows it now; moving to 9 still means rewriting `.eslintrc.cjs`
as a flat `eslint.config.js`. Its plugin drags in a `fast-xml-parser` with a moderate advisory, so
`package.json` overrides that to 5.x; the plugin only reads `appinfo/info.xml` with it and works
unchanged.

TypeScript checks the JavaScript (`checkJs`), which is what makes `tsc` worth running while no
`.ts` file exists. It cannot read single-file components, so `src/shims-vue.d.ts` declares them and
`.vue` files are covered by ESLint alone.

Two ways to get Docker, if it is not already there:

1. **Docker Desktop for Windows** with WSL integration enabled — least friction, GUI, survives
   reboots.
2. **Docker Engine inside Ubuntu-WSL** — systemd is running here, so `systemctl enable --now
   docker` works and no Windows-side software is needed. Preferred if you want the whole toolchain
   inside WSL.

**The stack** (`.docker/compose.yml`, kept in the repo):

```
db        mariadb:11          — the real DB, not SQLite; catches the errors SQLite hides
app       nextcloud:34-apache — port 8080, our app bind-mounted into /var/www/html/custom_apps/nextfleet
app31     nextcloud:31-apache — port 8081, same mount
cron      nextcloud:34-apache — same image, runs cron.php every 5 min, so reminders actually fire
cron31    nextcloud:31-apache — the same for app31
mail      axllent/mailpit     — SMTP sink on :1025, web UI on :8025
```

The file sets `name: nextfleet`, and everything the stack creates carries that prefix —
`nextfleet_default`, `nextfleet-app-1`. Compose otherwise names the project after the directory the
file sits in, which here is `.docker`, and a stack called `docker` collides with every other repo
that keeps one in the same place.

The bind mount is the whole trick: edit in WSL, reload the browser. `npm run watch` in the repo
rebuilds the frontend into the same directory.

It also costs one line of shell. Docker creates the mount's parent, `custom_apps`, as root, and the
image only takes ownership of that directory while it is still empty — which the mount stops it from
ever being. NC 34 lives with it; NC 31 refuses to install, and says `Cannot write into "apps"
directory` rather than anything about the mount. The image's hook scripts run as `www-data` and
cannot chown, so both app services override the entrypoint to do it first.

The two majors share the database *server* and nothing else — separate schemas, separate `html`
volumes. Whichever started second would otherwise run `occ upgrade` over the other's install and the
gate would test one version twice. The image creates only the schema `MARIADB_DATABASE` names, so
`.docker/db-init/` adds the second.

`cron` sets `entrypoint: /cron.sh`, which bypasses the image's entrypoint — so it reads none of the
`NEXTCLOUD_*` environment and takes its configuration from the `config.php` in the volume it shares
with `app`. `cron31` does the same for `app31`.

Each major needs its own cron runner. Without one a major runs only an AJAX tick on a page load, and
`CleanupFileLocks` falls behind. NC 31 had no runner until M13. By then its `oc_file_locks` held 102
locks past their TTL, the oldest eleven days old. A stale lock on a reused E2E path answers 423
to a `DELETE`. `cron31`'s first `cron.php` run switched NC 31 to cron mode and took ten minutes to
work off the backlog.

Setup, once:

```bash
docker compose up -d
docker compose exec -u www-data app php occ app:enable nextfleet
docker compose exec -u www-data app php occ config:system:set debug --value=true --type=boolean
```

Then `http://localhost:8080` in Windows, and `:8081` for NC 31 — `app31` is not an extra: the M0 gate
above is checked by loading both ports, and after M0 it is the fastest way to catch a component that
only exists in 34. Enabling the app is per service, so run the `occ` lines against `app31` too.

`npm run test:e2e` is that check, automated: one Playwright project per major, so a failure names
the version that broke. `m0-gate.spec.js` is the gate — the page mounts the Vue root and reports
nothing to the console. `m1-slice.spec.js` drives the app: a vehicle created through the sheet and
edited through it, a counter reading refused and then recorded, a save refused as stale and saved
through on the second attempt, a delete undone from the toast, the country changed on the personal
settings page and read back off the next vehicle, and the "complete this vehicle" hint dismissed for
good. Then an axe audit of the overview, the vehicle screen and an open sheet, scoped to
`#nextfleet` at the WCAG 2.1 AA tags — Nextcloud's own header is outside this app's reach.
`m2-slice.spec.js` does the same for the logbook: a trip entered as a counter and a trip entered as
a distance, both moving the vehicle's counter, a refused one leaving the sheet open, an incomplete
one saved and asked for the rest, and Logbook Mode switched on and off. `m3-slice.spec.js` covers
the costs: a fill-up that moves the header, a hybrid's two consumptions, a fill-up edited from its
row and deleted and undone, the period picker, and an axe audit of the header and an open fill-up.
It sets the period back to the default first, since the picker keeps its choice on the server.
`m4-slice.spec.js` covers reminders: a HU/AU entered from the sticker, a recipient and a daily
cadence set in the vehicle sheet, then the job run twice at a moved clock. It checks the one
notification the recipient reads over OCS and the one digest in Mailpit, both in German. Then an
oil change is closed from the banner, the next one shows, and the overview reorders.
`m5-slice.spec.js` covers papers and reports: a paper downloads for a driver of the vehicle, for
nobody else, and not once it is deleted; a paper picked from Files is listed and opens from its
entry; the Costs screen reads a month and exports its trips; the mileage claim prints; the sticker
opens the entry sheet. It ends with the sweep below. `m6-slice.spec.js` has the owner give access,
checks what each role is offered and ends the access; `roles.spec.js` signs in as owner, manager,
driver and viewer of one vehicle and checks what each may do.
`m7-slice.spec.js` covers the pool and the inbox with fresh accounts: a driver books the owner's car,
the owner's booking over it is refused with the driver named, the driver takes the car while the
owner's overview says who has it, returns it and logs the prefilled trip, and files a receipt from
the inbox on their own fill-up in two taps. A second case logs a receipt as a new fill-up. M8 has
no spec here: its slice is a client's, so it lives in the API suite, and the M7 slice is what shows
the web UI did not move. `m9-slice.spec.js` covers importing with fresh accounts: the owner previews
a LubeLogger file whose rows are new, already there and unreadable, imports it, sees the consumption
it closes, undoes it from the toast, and imports a Spritmonitor file. A driver is offered no import.

**The job at a moved clock.** The server's clock cannot move, so `tests/e2e/job.php` runs
`ReminderJob` once with a stopped `ITimeFactory` at the instant it is given. `server.js` runs it
inside the project's container through the Docker Engine API on `/var/run/docker.sock`. It does not
use the docker CLI, because Playwright's image has none; `test:e2e:docker` mounts the socket. The
run sweeps every vehicle on the instance, so the demo fleet gets receipts dated in the future.
Reseed afterwards. Each run creates a fresh `m4e2e-` account as the recipient, so no earlier digest
blocks today's mail, and deletes the previous run's account first. Mailpit must be up
(`NEXTFLEET_MAILPIT` overrides `http://localhost:8025`).

Four things about those files worth knowing before adding to them:

- **A test tagged `@nc34` runs on that major alone**, because every other project would re-measure
  the same stylesheet. The sweep at the end of `m5-slice.spec.js` wears it. It runs every screen
  through `fits()` and axe at 320 × 640 and 1280 × 800, light and dark. Since M6 that includes
  what a grant changes for the owner and the grantee; since M7 the bookings, the handover sheets,
  who has the car and the inbox; since M9 the import's format step, its preview with an open
  question and with a long column list, and its result. The tag is filtered out per project in
  `playwright.config.js`.
- **A dialog is visible from the first frame of its fade-in**, so an audit taken right after
  `toBeVisible()` measures the text against a background it is still blended with and reports a
  contrast violation that is over in 200 ms. `opened()` waits for the opacity instead.
- **A sheet that closes is gone at once, with no fade-out.** Every sheet is mounted with `v-if` and
  closes by unmounting, and Vue skips the leave of a `Transition` unmounted with its parent. The
  sheet leaves the DOM before `press()` or `click()` returns, so `toBeVisible()` straight after a
  keystroke is enough to prove the keystroke did not close it. Close a sheet through `:open` instead
  and that check would pass during the fade.
- **What every spec file shares lives in `app.js`** — signing in, the API, the overview row, opening
  a vehicle, making one, a header tile, WebDAV on an account's Files and choosing its inbox, a
  file chosen in the picker, the way into the import sheet, and the sweep each file starts with.

It needs the stack up and `js/` built — without a bundle the root stays empty and the failure names
the assertion, not the missing build. It logs in through the form — Nextcloud redirects a browser to
`/login` whatever `Authorization` header it carries, so basic auth is no shortcut.
`NEXTFLEET_URL_NC34` and `NEXTFLEET_URL_NC31` override the two ports.

Every vehicle the run makes wears a plate its own spec file owns — `E2E-` for the M1 slice,
`M2-E2E-` to `M7-E2E-` for the next six, `M9-E2E-` for the M9 slice, `ROLES-` for the role cases — and each file deletes what it finds under its prefix before it starts.
They all belong to the run's account or to an account a spec made, so the teardown that deletes the
accounts ([testing](#testing)) takes them out of every list. The sweep up front is for
`--repeat-each`, which runs a file again on the same account. The prefixes have to stay disjoint:
Playwright runs the files at once, and a sweep that matched another file's plates would delete a
vehicle out from under a test still using it.

Chromium's own dependencies are system packages and `npx playwright install --with-deps` needs
root. Where that is not available, `npm run test:e2e:docker` runs the same specs inside Playwright's
own image, which ships them, on the host network. The image tag in that script is the
`@playwright/test` version: Playwright refuses browsers it did not build, so bump the two together.
`npm run screenshots:docker` names the same tag a third time, and `npm update` moves the installed
version without touching any of the three — `tests/js/playwright-pin.spec.js` measures all three
against the lockfile so that drift is a failed test rather than a broken container.

**Disable the first-run wizard on both instances** — `occ app:disable firstrunwizard`. Its modal
covers the page on a fresh install, and Playwright waits out its timeout on the first click without
ever saying what intercepted it. A `docker compose down -v` brings it back.

Three things that will bite:

- **Keep the repo in the Linux filesystem** (`/home/...`, as it is), not under `/mnt/c`. Cross-OS
  file access is slow enough to make `npm run watch` and PHP autoloading painful.
- **`trusted_domains` must contain the host**, or Nextcloud refuses the request with
  `Access through untrusted domain`. `NEXTCLOUD_TRUSTED_DOMAINS=localhost` in the compose file
  covers both ports; the check compares the host and ignores the port.
- **A migration only ever runs once**, so editing `lib/Migration/` changes nothing on an install
  that already recorded it. `occ migrations:execute nextfleet <version>` — which exists only once
  `debug` is on, and says "command is not defined" otherwise — re-runs the step, but a
  step that creates tables skips every table it finds — the guard that lets a re-install over
  leftover tables succeed — and so applies nothing. Drop the tables first, or
  `docker compose down -v`. The integration schema test does that dropping itself, which makes it
  the quickest way to try a change.

For a full server-source setup (debugging Nextcloud itself, multiple versions, LDAP, Collabora),
switch to [nextcloud-docker-dev](https://juliusknorr.github.io/nextcloud-docker-dev/). Overkill for
app work; the right tool once we need to reproduce a server bug.

## Release

The maintainer's steps, in order, on a machine that holds the signing key.

**Once, before the first release.** Request the app's certificate: a private key and a CSR for
`nextfleet`, by pull request to `nextcloud/app-certificate-requests`. Keep the key offline
([supply chain](security.md#supply-chain)). Then register the app on apps.nextcloud.com with the
certificate and the app id signed by the key:

```bash
echo -n nextfleet | openssl dgst -sha512 -sign nextfleet.key | openssl base64
```

Before the first upload, enable private vulnerability reporting in the repository's settings
(*Security → Private vulnerability reporting*). [`SECURITY.md`](../SECURITY.md) sends reporters
there; until it is on, the link is a dead end.

**Each release:**

1. **Review and commit.** `composer test`, `composer lint`, `npm test` and `npm run lint` pass.
   Review everything since the last commit, untracked files included (`git status` lists them),
   and commit it. Then run `/security-review` on the release's diff, against the last release's
   tag ([process](security.md#process)). The first release has none, and `main` already holds
   part of the app, so tell it to diff against the empty tree (`git hash-object -t tree
   /dev/null`): the whole app. Fix what it finds before going on.
2. **Date the CHANGELOG section.** `## <x> — not released` becomes `## <x> — <YYYY-MM-DD>`. The
   store shows only the section named after the version, so it must read whole on its own. 0.3.1's
   already does: neither 0.2.0 nor 0.3.0 was released (decided 2026-10-03), so 0.3.1 is the first
   release and its section says what the app does. The version is already set in `appinfo/info.xml`,
   `package.json` and `package-lock.json`; `InfoXmlTest` fails until all four agree and while any
   other section is `not released`.
3. **Promise the API.** For a release that ships the API, copy `openapi.json` over
   `tests/Api/v1-baseline.json`: everything released is promised. The CHANGELOG section names what
   v1 now promises besides ([what v1 promises](api.md#what-v1-promises)); for 0.3.1 that is the
   OCS API line under *For apps, scripts and admins*. Commit.
4. **Build.** `npm run package` writes `build/artifacts/nextfleet-<x>.tar.gz` from a fresh build.
   What it ships, and why, is in `tools/package.sh`. If a screen changed since the screenshots were
   last taken, first reseed NC 34 (`occ nextfleet:seed admin`), run `npm run screenshots:docker`
   and commit them.
5. **Sign the code.** Unpack the tarball, sign the folder with the dev stack's `occ`, pack it again
   the way `package.sh` does. The container sees the repository at
   `/var/www/html/custom_apps/nextfleet` and nothing else of the host. The key is mounted nowhere:
   `occ` reads it from stdin, so it never lands in the container or the repository. The
   certificate is public; copy it to `build/nextfleet.crt`, which git ignores and `package.sh`
   never packs. `occ` runs as `www-data` and writes `appinfo/signature.json`, so that folder is
   opened to it for the signing and closed again before packing. Run from the repository's root:

   ```bash
   rm -rf build/sign && mkdir -p build/sign && tar -xzf build/artifacts/nextfleet-<x>.tar.gz -C build/sign
   chmod a+w build/sign/nextfleet/appinfo
   docker compose -f .docker/compose.yml exec -T -u www-data app php occ integrity:sign-app \
     --privateKey=php://stdin \
     --certificate=/var/www/html/custom_apps/nextfleet/build/nextfleet.crt \
     --path=/var/www/html/custom_apps/nextfleet/build/sign/nextfleet < <path>/nextfleet.key
   chmod go-w build/sign/nextfleet/appinfo
   tar --sort=name --owner=0 --group=0 --numeric-owner \
     -czf build/artifacts/nextfleet-<x>.tar.gz -C build/sign nextfleet
   ```

6. **Check the upgrade** on that signed tarball, not a rebuild:
   `npm run upgrade-check -- build/artifacts/nextfleet-<x>.tar.gz [<base>]`. It installs the
   tarball on throwaway NC 31 and NC 34 servers, fresh and over the base with its seed and enough
   use to fill every table, and fails if a row is lost or changed or the upgraded schema differs
   from the fresh one in any column or index. The base defaults to `27142b4`, 0.2.0's source,
   since a user may run it from the repository. Once a version is out, pass that release's
   tarball. It takes about ten minutes and never touches the dev servers. Run it a second time on
   PostgreSQL: `npm run upgrade-check -- --db pgsql <tarball> [<base>]`. Then run it a third time
   on Oracle with `--db oracle`, NC 34 only, since only [`.docker/oracle/`](#oracle) builds an image
   with `oci8`. `--db` goes before the tarball. How it works is in `tools/upgrade-check.sh`.
7. **Publish the source.** Fast-forward `main` to `initial` (`git push origin initial:main`, or a
   pull request if `main` is protected): `info.xml` points the store at the screenshots on `main`.
   Then tag the release commit `v<x>` and push the tag.
8. **Upload.** Attach the tarball to a GitHub release for the tag; the store downloads it from
   there. On apps.nextcloud.com, *Upload app release* takes that download URL and the tarball's
   signature:

   ```bash
   openssl dgst -sha512 -sign nextfleet.key build/artifacts/nextfleet-<x>.tar.gz | openssl base64
   ```

9. **Open the next version** when the first change for it lands: set it in `appinfo/info.xml`, run
   `npm version <y> --no-git-tag-version`, and add `## <y> — not released` above the dated section.
