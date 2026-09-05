# Development

Part of the [NextFleet plan](../plan.md). Terms are defined in [CONTEXT.md](../CONTEXT.md).

## Testing

**Design rule that makes testing possible:** never call `time()` or `new DateTime()` in app code.
Inject `OCP\AppFramework\Utility\ITimeFactory` everywhere. A reminder engine you cannot move through
time is a reminder engine you cannot test.

| Layer | Tool | What it covers |
|---|---|---|
| Static | Psalm (with the `nextcloud/ocp` stubs), php-cs-fixer + `nextcloud/coding-standard`, ESLint/Stylelint (`@nextcloud/eslint-config`), TypeScript | Wrong types, private API use, style |
| Unit | PHPUnit, mappers mocked | The logic worth trusting: odometer derivation and the observed-beats-derived rule, due-date/km evaluation, recurrence from actual completion, consumption and cost per km, mileage projection and its data floor |
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

**CI matrix** (GitHub Actions). Four supported Nextcloud majors times every PHP version times three
databases is dozens of jobs, so the trim happens on the PHP and database axes — never on Nextcloud,
because that is the axis users actually vary.

| When | Combinations |
|---|---|
| Pull request | Two: NC 31 with the oldest PHP it supports, and NC 34 with the newest. MariaDB both. The oldest combination is where breakage hides, so it belongs on every PR rather than in a nightly nobody reads |
| Merge to `main` | Add PostgreSQL and SQLite, on NC 34 |
| Weekly | The fuller matrix, allowed to fail loudly without blocking anyone |

`.github/workflows/ci.yml` implements it. Alongside the matrix run — a Nextcloud checkout, a real
database, `occ maintenance:install`, `occ app:enable`, then PHPUnit — three jobs run once each:
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

The E2E run is the one check still missing from CI. It needs the whole compose stack on the runner
and the app services carry no healthcheck to wait on, so it belongs with the E2E work rather than
with the matrix.

Add the app store's `krankerl`/appinfo validation as a release gate.

**The M0 gate is a build, not a test.** One frontend bundle must build and run against NC 31 and
NC 34 before any feature work starts ([milestones](../plan.md#milestones)). `@nextcloud/vue` moves
fast and a component present in 34 may be missing in 31 — which is why
[the interface](ui.md#look-like-nextcloud-not-like-fleet-software) restricts itself to the old,
stable components. If no single bundle spans the range, the 31 floor moves; the UI does not.

**Seed data:** an `occ nextfleet:seed` command that generates a demo fleet with two years of trips
and fill-ups — including the awkward rows: a flagged backwards odometer, a `missed_previous` gap, a
plug-in hybrid with both energy types, and a vehicle counted in hours. It powers E2E tests,
screenshots for the app store, and manual clicking.

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

`npm run build` bundles `src/main.js` into `js/nextfleet-main.mjs`; `npm run watch` does the same
in development mode and rebuilds on save. Two bits of noise to ignore: `@nextcloud/vite-config`
sets `outDir` to the repo root on purpose, so that every build prints Vite's "build.outDir must not
be … a parent directory of root"; and its polyfill chain pulls in `elliptic` and `crypto-browserify`,
so `npm audit` reports seven low-severity advisories with no upstream fix. Gate CI at `--audit-level
moderate` rather than muting the tool.

`npm run lint` runs all three static frontend checks in turn — ESLint, Stylelint, then `tsc
--noEmit` — and `npm run lint:js`, `lint:css` and `lint:types` run them one at a time. `npm test`
is Vitest; frontend tests sit next to what they test as `src/**/*.spec.js`. The only frontend tests
under `tests/` are Playwright's, in `tests/e2e/`; everything else there is PHPUnit's.

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

`npm run test:e2e` is that check, automated: one Playwright project per major, asserting that the
page mounts the Vue root and reports nothing to the console. It needs the stack up and `js/` built —
without a bundle the root stays empty and the failure names the assertion, not the missing build. It
logs in through the form — Nextcloud redirects a browser to `/login` whatever `Authorization` header it
carries, so basic auth is no shortcut. `NEXTFLEET_URL_NC34` and `NEXTFLEET_URL_NC31` override the
two ports.

Chromium's own dependencies are system packages and `npx playwright install --with-deps` needs
root. Where that is not available, `npm run test:e2e:docker` runs the same specs inside Playwright's
own image, which ships them, on the host network. The image tag in that script is the
`@playwright/test` version: Playwright refuses browsers it did not build, so bump the two together.

Three things that will bite:

- **Keep the repo in the Linux filesystem** (`/home/...`, as it is), not under `/mnt/c`. Cross-OS
  file access is slow enough to make `npm run watch` and PHP autoloading painful.
- **`trusted_domains` must contain the host**, or Nextcloud refuses the request with
  `Access through untrusted domain`. `NEXTCLOUD_TRUSTED_DOMAINS=localhost` in the compose file
  covers both ports; the check compares the host and ignores the port.
- **A migration only ever runs once**, so filling in `lib/Migration/` after the app has been enabled
  changes nothing on an install that already recorded it. Re-run it with
  `occ migrations:execute nextfleet <version>`, or start from `docker compose down -v`.

For a full server-source setup (debugging Nextcloud itself, multiple versions, LDAP, Collabora),
switch to [nextcloud-docker-dev](https://juliusknorr.github.io/nextcloud-docker-dev/). Overkill for
app work; the right tool once we need to reproduce a server bug.
