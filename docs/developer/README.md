# Developer guide

This part is for people who change the code of NextFleet. It tells you how to start a development
server, run the tests and send a change. The design documents explain the reasons for the code.

## Read first

1. [CONTEXT.md](../../CONTEXT.md) is the glossary. The code, the user interface and the documents
   use these words and no others.
2. [plan.md](../../plan.md) gives the purpose of the app and its milestones.
3. [docs/adr/](../adr/) holds the decisions that are difficult to reverse. If a document and an ADR
   do not agree, the ADR is correct.

## Start a development server

This tutorial starts Nextcloud 34 and Nextcloud 31 in Docker, with NextFleet and a demonstration
fleet.

### Before you start

- PHP 8.1 or later, and Composer.
- Node.js 22 (22.22 or later), or 24 or later.
- Docker with Docker Compose.

### Procedure

1. Install the dependencies:

   ```bash
   composer install
   npm ci
   ```

2. Build the frontend:

   ```bash
   npm run build
   ```

3. Start the servers:

   ```bash
   docker compose -f .docker/compose.yml up -d
   ```

4. Enable the app on Nextcloud 34 and on Nextcloud 31:

   ```bash
   docker compose -f .docker/compose.yml exec -u www-data app php occ app:enable nextfleet
   docker compose -f .docker/compose.yml exec -u www-data app31 php occ app:enable nextfleet
   ```

5. Add the demonstration fleet to the `admin` account on both servers:

   ```bash
   docker compose -f .docker/compose.yml exec -u www-data app php occ nextfleet:seed admin
   docker compose -f .docker/compose.yml exec -u www-data app31 php occ nextfleet:seed admin
   ```

6. Open <http://localhost:8080> for Nextcloud 34, or <http://localhost:8081> for Nextcloud 31.
7. Log in as `admin` with the password `admin`.
8. Open the **NextFleet** app.

   The page shows the vehicles of the demonstration fleet.

The servers use your working copy of the repository. When you change PHP code, reload the page.
The server can show the old code for up to one minute.
To build the frontend again on each save, run `npm run watch`. Mailpit shows the sent mails
at <http://localhost:8025>.

[Local dev environment](../development.md#local-dev-environment) explains each service of the
stack.

## Run the checks

| Command | What it checks | Where |
|---|---|---|
| `composer test` | PHP unit tests | Host |
| `composer lint` | Code style and Psalm | Host |
| `npm test` | Frontend unit tests (Vitest) | Host |
| `npm run lint` | ESLint, Stylelint and TypeScript | Host |
| `phpunit -c phpunit.integration.xml` | PHP tests against a real Nextcloud and database | Container |
| `phpunit -c phpunit.api.xml` | The OCS API over HTTP | Container |
| `npm run test:e2e:docker` | Playwright tests in a browser, on both servers | Host, with Docker |

The container has no Composer. Run the integration tests and the API tests with PHPUnit in the
container, for example:

```bash
docker compose -f .docker/compose.yml exec -u www-data -w /var/www/html/custom_apps/nextfleet \
  app php vendor/bin/phpunit -c phpunit.integration.xml
```

> [!WARNING]
> Run the integration tests only on a development server. They delete the tables of the app and
> make them again.

After the tests, add the demonstration fleet again with `occ nextfleet:seed admin`.

[Testing](../development.md#testing) describes each test layer and the CI jobs.

## Where the code is

| Folder | Content |
|---|---|
| `lib/Controller/` | The routes: the web app and the OCS API. Thin, they call a service. |
| `lib/Service/` | The rules of the app. Each write goes through a service. |
| `lib/Db/` | Entities and mappers, one per table. |
| `lib/Migration/` | The database migrations. Never change a migration after a release. |
| `lib/Jurisdiction/` | The rules of each country: logbook, inspection, rates, reports. |
| `lib/Import/` | The CSV importers. |
| `lib/Command/` | The `occ` commands. |
| `src/` | The Vue 3 frontend. |
| `l10n/` | The translations: English, German informal (`de`) and German formal (`de_DE`). |
| `tests/` | PHPUnit, Vitest helpers, Playwright (`tests/e2e/`). |
| `tools/` | Packaging, the upgrade check and the screenshots. |

## Send a change

NextFleet takes changes as merge requests. There is no plugin system. A new country, importer or
report goes into the repository. [Contributing](../contributing.md) tells you what a country must
deliver.

Each merge request must:

- Have a test that fails without the change.
- Pass all checks in [Run the checks](#run-the-checks).
- Change the documents and `CHANGELOG.md` in the same merge request.
- Add each new user interface text to all translation files.
- Run `composer openapi` after a change to a docblock in `lib/Controller/`, and commit
  `openapi.json`.

Report a security problem privately. [SECURITY.md](../../SECURITY.md) tells you how.

## Design documents

| Document | Read it to know |
|---|---|
| [architecture.md](../architecture.md) | The layers, the data model, the Nextcloud integration, the reminder engine and the calculations |
| [api.md](../api.md) | The OCS API for clients: login, answers, tokens, sync, and what version 1 promises |
| [ui.md](../ui.md) | The screens, the entry form, and the rules for English and German texts |
| [features.md](../features.md) | Other apps, the backlog and Logbook mode |
| [development.md](../development.md) | The test layers, CI, the development stack and the release procedure |
| [security.md](../security.md) | The threat model and its rules |
| [legal.md](../legal.md) | The licence, trademarks and data protection |
| [contributing.md](../contributing.md) | How to add a country, an importer or a report |

The [user manual](../user/README.md) and the [administration guide](../admin/README.md) describe
the app from the outside. When you change a function that they describe, change them too.
