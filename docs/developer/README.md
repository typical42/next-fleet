# Developer guide

For people who change the code.
Start a dev server, run the checks, find your way in the code, and send a change.
Then follow the reading path at the end.

## Read first

1. [CONTEXT.md](../../CONTEXT.md): the glossary.
   Code, screens and docs use these words and no others.
2. [plan.md](../../plan.md): the purpose of the app and its milestones.
3. [adr/](../adr/): the decisions that are hard to reverse.
   Where a document and an ADR disagree, the ADR wins.

## Start a development server

You need PHP 8.1+ with Composer, Node.js 22.22+ or 24+, and Docker with Compose.

1. Install and build:

   ```bash
   composer install
   npm ci
   npm run build
   ```

2. Start Nextcloud 34 and 31:

   ```bash
   docker compose -f .docker/compose.yml up -d
   ```

3. Enable the app and add the demo fleet, on `app` (NC 34) and `app31` (NC 31):

   ```bash
   docker compose -f .docker/compose.yml exec -u www-data app php occ app:enable nextfleet
   docker compose -f .docker/compose.yml exec -u www-data app php occ nextfleet:seed admin
   docker compose -f .docker/compose.yml exec -u www-data app31 php occ app:enable nextfleet
   docker compose -f .docker/compose.yml exec -u www-data app31 php occ nextfleet:seed admin
   ```

4. Open <http://localhost:8080> (NC 34) or <http://localhost:8081> (NC 31) and log in as `admin` with the password `admin`.

The servers run your working copy.
A PHP change shows on reload, after up to a minute.
`npm run watch` rebuilds the frontend on save.
Mailpit shows the sent mail at <http://localhost:8025>.
[Local dev environment](../development.md#local-dev-environment) explains the stack.

## Run the checks

| Command | Checks | Where |
|---|---|---|
| `composer test` | PHP unit tests | Host |
| `composer lint` | Code style and Psalm | Host |
| `npm test` | Frontend unit tests (Vitest) | Host |
| `npm run lint` | ESLint, Stylelint, TypeScript | Host |
| `phpunit -c phpunit.integration.xml` | PHP against a real Nextcloud and database | Container |
| `phpunit -c phpunit.api.xml` | The OCS API over HTTP | Container |
| `npm run test:e2e:docker` | Playwright in a browser, on both servers | Host, with Docker |
| `npm run docs:diagrams` | Every Mermaid diagram under `docs/` renders | Host, with Docker |

> [!WARNING]
> Run the integration tests only on a development server.
> They drop the app's tables.

[Testing](../development.md#testing) explains each layer and the CI jobs.

## Where the code is

| Folder | Holds |
|---|---|
| `lib/Controller/` | The routes of the web UI and the OCS API. Thin: they call a service. |
| `lib/Service/` | The rules. Every write goes through a service. |
| `lib/Db/` | Entities and mappers, one per table. |
| `lib/Migration/` | Database migrations. Never change one after a release. |
| `lib/Jurisdiction/` | Country rules: logbook, inspection, rates, reports. |
| `lib/Import/` | The CSV importers. |
| `lib/Command/` | The `occ` commands. |
| `src/` | The Vue 3 frontend. |
| `l10n/` | English, German informal (`de`) and formal (`de_DE`). |
| `tests/` | PHPUnit, Vitest helpers, Playwright (`tests/e2e/`). |
| `tools/` | Packaging, the upgrade check, screenshots. |

## Send a change

Changes come as merge requests.
There is no plugin system ([contributing](../contributing.md)).
Each merge request:

- has a test that fails without the change,
- passes every check above,
- updates the docs and `CHANGELOG.md` in the same request, the user manual included,
- adds each new UI text to all translation files,
- runs `composer openapi` after a change in `lib/Controller/` and commits `openapi.json`,
- names each AI tool that helped ([AI assistance](../contributing.md#ai-assistance)).

Report a security problem privately, as [SECURITY.md](../../SECURITY.md) says.

## The reading path

Read these in order.
Each one ends with a link to the next.

1. [Architecture](../architecture.md): layers, data model, the counter rules, the figures, reminders, access.
2. [Interface and languages](../ui.md): the screens and the rules for English and German.
3. [The OCS API](../api.md): what a client is built against.
4. [Security](../security.md): the threat model and its rules.
5. [Licensing and legal](../legal.md): the licence, trademarks and data protection.
6. [Contributing](../contributing.md): how to add a country, an importer or a report.
7. [Development](../development.md): testing, CI, the dev stack and the release.
8. [Design diagrams](../design/README.md) and the [decision records](../adr/).

The [features](../features.md) page holds the prior art and the backlog.

Next: [Architecture](../architecture.md)
