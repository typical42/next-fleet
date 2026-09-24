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
| API contract | PHPUnit + Guzzle with an app password | Arrives with the OCS API, not before ([ADR 0006](adr/0006-one-api-surface-in-v1.md)). Snapshot the JSON; a breaking change must fail CI |
| Frontend | Vitest for stores and pure components | Consumption/cost formatting, form validation |
| E2E | Playwright against the dev container | Quick-add flow, vehicle creation, reminder appears on the dashboard |
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

PHPUnit loads our autoloader first, stubs included, and `lib/base.php` then puts the server's own
in front of it — so `OCP\` resolves to the running server and not to the pinned stubs. That is the
server's doing, not ours, and it holds on 31 and 34 alike; `tests/Integration/AutoloadingTest.php`
is there so a version that changes it says so.

`ISchemaWrapper` gained `dropAutoincrementColumn()` in NC 33. The stubs are older and do not have
it, so a test double implementing that interface has to declare it anyway — without it the class is
abstract, and fatal, on a newer server.

**CI matrix** (GitHub Actions). Four supported Nextcloud majors times every PHP version times three
databases is dozens of jobs, so the trim happens on the PHP and database axes — never on Nextcloud,
because that is the axis users actually vary.

| When | Combinations |
|---|---|
| Pull request | Two: NC 31 with the oldest PHP it supports, and NC 34 with the newest. MariaDB both. The oldest combination is where breakage hides, so it belongs on every PR rather than in a nightly nobody reads |
| Merge to `main` | Add PostgreSQL and SQLite, on NC 34 |
| Weekly | The fuller matrix, allowed to fail loudly without blocking anyone |

`.github/workflows/ci.yml` implements it. Alongside the matrix run — a Nextcloud checkout, a real
database, `occ maintenance:install`, `occ app:enable`, then both PHPUnit suites, which is where
the schema meets PostgreSQL and SQLite — three jobs run once each:
static analysis with `composer lint` and `composer audit`, the frontend checks, and `reuse lint`. A
`plan` job picks the combinations for the event that triggered the run; the three lists sit in its
environment as JSON so `tests/Unit/CiWorkflowTest.php` can read them, because `actionlint` only
proves GitHub will run the file, not that it runs the right thing.

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
`tools/package.sh` builds the tarball ([release](#release)).

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
about to write again, and leaves anything else parked next to them alone.

Testing the reminder job by waiting is not testing. Move the clock, then run
`occ background-job:list` / `background-job:execute <id>` to fire the job on demand.

## Local dev environment

Yes, this works, and the browser part is free: WSL2 forwards `localhost`, so anything listening in
Ubuntu is reachable at `http://localhost:8080` from a Windows browser. No port mapping, no IP
lookup.

**Toolchain:** PHP 8.1+, Composer, Node 20.19+ (Vite 7's floor) and Docker. `composer.json` pins its resolution
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

`@nextcloud/eslint-config` is held at 8.x, the same trap as Psalm above: version 9 needs ESLint 10
and Node's `findPackageJSON`, which arrives in Node 22, so it installs on the Node 20 here and then
dies on the first run. Moving to 9 means moving the Node floor and rewriting `.eslintrc.cjs` as a
flat `eslint.config.js`. Its plugin drags in a `fast-xml-parser` with a moderate advisory, so
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
with `app`.

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
  the same stylesheet. The audit at 320 × 640 in dark mode is the one that wears it; the tag is
  filtered out per project in `playwright.config.js`.
- **A dialog is visible from the first frame of its fade-in**, so an audit taken right after
  `toBeVisible()` measures the text against a background it is still blended with and reports a
  contrast violation that is over in 200 ms. `opened()` waits for the opacity instead.
- **A sheet that closes is gone at once, with no fade-out.** Every sheet is mounted with `v-if` and
  closes by unmounting, and Vue skips the leave of a `Transition` unmounted with its parent. The
  sheet leaves the DOM before `press()` or `click()` returns, so `toBeVisible()` straight after a
  keystroke is enough to prove the keystroke did not close it. Close a sheet through `:open` instead
  and that check would pass during the fade.
- **What every spec file shares lives in `app.js`** — signing in, the API, the overview row, opening
  a vehicle, making one, and the sweep each file starts with.

It needs the stack up and `js/` built — without a bundle the root stays empty and the failure names
the assertion, not the missing build. It logs in through the form — Nextcloud redirects a browser to
`/login` whatever `Authorization` header it carries, so basic auth is no shortcut.
`NEXTFLEET_URL_NC34` and `NEXTFLEET_URL_NC31` override the two ports.

Every vehicle the run makes wears a plate its own spec file owns — `E2E-` for the M1 slice,
`M2-E2E-` to `M5-E2E-` for the next four — and each file deletes what it finds under its prefix before it starts.
Cleaning up front rather than afterwards leaves a failed run's rows where they can be looked at, and
still makes the next run find one vehicle rather than two. The prefixes have to stay disjoint:
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

1. Set the version in `appinfo/info.xml`, run `npm version <x> --no-git-tag-version`, and rename
   the CHANGELOG's open section to `## <x> — <date>`. `InfoXmlTest` fails until all four agree.
2. Reseed NC 34 (`occ nextfleet:seed admin`), clear any E2E vehicles from admin's fleet, and run
   `npm run screenshots:docker`. `InfoXmlTest` fails for a screenshot `info.xml` names and the
   repository lacks.
3. `npm run package` writes `build/artifacts/nextfleet-<version>.tar.gz`. It empties `js/`, runs
   `npm ci` and a fresh build, since the build never removes old chunks, and copies only the
   list in `tools/package.sh`. `PackageTest` fails when a new top-level entry is on neither list.
4. Signing and upload are a maintainer's, on a machine holding the key
   ([supply chain](security.md#supply-chain)).
