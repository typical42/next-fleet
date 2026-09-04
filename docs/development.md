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

**CI matrix** (GitHub Actions). Four supported Nextcloud majors times every PHP version times three
databases is dozens of jobs, so the trim happens on the PHP and database axes — never on Nextcloud,
because that is the axis users actually vary.

| When | Combinations |
|---|---|
| Pull request | Two: NC 31 with the oldest PHP it supports, and NC 34 with the newest. MariaDB both. The oldest combination is where breakage hides, so it belongs on every PR rather than in a nightly nobody reads |
| Merge to `main` | Add PostgreSQL and SQLite, on NC 34 |
| Weekly | The fuller matrix, allowed to fail loudly without blocking anyone |

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

**What's missing on this machine right now:** Docker, PHP and Composer. Node 20+ is present.

Two ways to get Docker:

1. **Docker Desktop for Windows** with WSL integration enabled — least friction, GUI, survives
   reboots.
2. **Docker Engine inside Ubuntu-WSL** — systemd is running here, so `systemctl enable --now
   docker` works and no Windows-side software is needed. Preferred if you want the whole toolchain
   inside WSL.

**The stack** (`.docker/compose.yml`, kept in the repo):

```
db        mariadb:11          — the real DB, not SQLite; catches the errors SQLite hides
app       nextcloud:34-apache — port 8080, our app bind-mounted into /var/www/html/custom_apps/nextfleet
cron      nextcloud:34-apache — same image, runs cron.php every 5 min, so reminders actually fire
mail      axllent/mailpit     — SMTP sink on :1025, web UI on :8025
```

The bind mount is the whole trick: edit in WSL, reload the browser. `npm run watch` in the repo
rebuilds the frontend into the same directory.

Setup, once:

```bash
docker compose up -d
docker compose exec -u www-data app php occ app:enable nextfleet
docker compose exec -u www-data app php occ config:system:set debug --value=true --type=boolean
```

Then `http://localhost:8080` in Windows. A second `app` service pinned to `nextcloud:31-apache` on
port 8081 is part of the stack, not an extra: the M0 gate above is checked by loading both ports, and
after M0 it is the fastest way to catch a component that only exists in 34.

Two things that will bite:

- **Keep the repo in the Linux filesystem** (`/home/...`, as it is), not under `/mnt/c`. Cross-OS
  file access is slow enough to make `npm run watch` and PHP autoloading painful.
- **`trusted_domains` must contain `localhost:8080`**, or Nextcloud refuses the request. Set
  `NEXTCLOUD_TRUSTED_DOMAINS=localhost` in the compose file.

For a full server-source setup (debugging Nextcloud itself, multiple versions, LDAP, Collabora),
switch to [nextcloud-docker-dev](https://juliusknorr.github.io/nextcloud-docker-dev/). Overkill for
app work; the right tool once we need to reproduce a server bug.
