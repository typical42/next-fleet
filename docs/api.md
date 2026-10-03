# The OCS API

What a client other than the web UI is built against. It lives at
`/ocs/v2.php/apps/nextfleet/api/v1` and is the app's public contract
([ADR 0009](adr/0009-the-ocs-api-v1-is-the-public-contract.md)).

## Signing in

A client signs in with a Nextcloud app password over Basic auth. Nextcloud issues it; the app keeps
no credentials of its own.

- **Login Flow v2** gets one without the client seeing the user's password. The client `POST`s
  `/index.php/login/v2` and is answered a `login` URL and a `poll` with `token` and `endpoint`. It
  opens `login` in a browser, where the user signs in, second factor included, and grants access.
  Meanwhile it `POST`s `token=<token>` to `endpoint` until the answer is no longer a 404; that one
  holds `server`, `loginName` and `appPassword`. The token lasts 20 minutes.
- **By hand**, a user makes one under Personal settings → Security → Devices & sessions.

Either way it is revoked there, and the client is signed out with it
([security](security.md#the-api)).

```sh
curl -u anna:<app password> \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  https://cloud.example/ocs/v2.php/apps/nextfleet/api/v1/vehicles
```

`OCS-APIRequest: true` is what exempts the request from Nextcloud's CSRF check. Without
`Accept: application/json` (or `?format=json`) OCS answers XML. Send a body as JSON
(`Content-Type: application/json`): some fields want a real boolean, integer or null, which a form
cannot carry.

## One rule, two doors

The web UI keeps its internal routes under `/apps/nextfleet/api`. Each OCS route twins one of them
at the same path under `/api/v1`, with the same verb and parameters, through a controller of the
same name in `lib/Controller/Ocs/`. Both controllers make the same service call and refuse alike;
neither holds a rule of its own ([architecture](architecture.md)). `tests/Unit/OcsRoutesTest.php`
holds the routes to that, and `tests/Integration/VehicleIdorTest.php` walks every OCS route by role
with the cases of its internal twin. [Sync](#sync) is the one OCS route with no twin: the web UI
keeps nothing to bring up to date.

## Answers

Every answer is the OCS envelope, and its HTTP status is the envelope's `statuscode`:

```json
{"ocs": {"meta": {"status": "ok", "statuscode": 200, "message": "OK"}, "data": {}}}
```

`data` is the row in its wire form: the column names as keys, as on the internal routes. The forms
are named in `lib/ResponseDefinitions.php`.

| Status | Means | Where the reason is |
| --- | --- | --- |
| 200, 201 | Done; 201 for a new row | `data` is the row |
| 400 | The request is wrong: a field, a missing `updated_at` | `data.message` |
| 401 | No login, or a wrong or revoked app password: Nextcloud's, before the app runs | `meta.message`; Nextcloud 31 answers it as XML whatever `Accept` says |
| 403 | The caller's role does not cover this | `meta.message`, `data` empty |
| 404 | No such vehicle or row, or not on this vehicle | `meta.message`, `data` empty |
| 409 | A booking is in the way, the file an import names changed since its preview, or an entry an undo names is not one the import left live | `data.message`; `data.booking` for a booking |
| 412 | Changed since the client read it | `data.message`, `data.conflict` is `true` |
| 422 | A file an import will not read | `data.reason`, a word such as `too_large` or `binary`; `data.row` where reading stopped, or null |
| 423 | Somebody is writing the file an import names; send it again | `data.message` |
| 429 | Too many calls: Nextcloud's [rate limit](#rate-limits), before the app runs | `data` empty |

A 412 without `conflict` is Nextcloud's own failed CSRF check, not ours.

## Writes and tokens

An edit, a delete and a restore carry the `updated_at` the client last read: in the body of a PUT
or POST, in the query string of a DELETE. Each answers the row with its new `updated_at`, which the
next write carries ([concurrency](architecture.md#concurrency)). A delete answers the token its
restore takes.

## Time and units

The wire carries what the database stores ([data model](architecture.md#data-model)):

- **An instant** is a unix second with `<name>_off` beside it, the offset in minutes where it
  happened: `started_at` and `started_at_off`
  ([ADR 0007](adr/0007-time-is-an-instant-plus-an-offset.md)). A client shows the local time the
  offset gives, not its own.
- **A calendar day** — `first_reg`, `disposed_at` — is a `YYYY-MM-DD` string with no offset.
- **Amounts are integers** in their stored unit: cents with the vehicle's `currency`, kilometres
  or hours by the vehicle's `odo_unit`, millilitres, watt-hours. `unit_price` counts tenths of a
  cent. Nothing is converted for the caller. The figures of `…/kpis` and `…/costs/{year}` are the
  one exception: worked out, not stored, some of them are floats.

## Routes

Under `/api/v1/vehicles`:

| Path | Verbs |
| --- | --- |
| `/` | GET list, POST create |
| `/{uuid}` | GET, PUT, DELETE |
| `/{uuid}/restore` | POST |
| `/{uuid}/readings`, `/{uuid}/readings/{reading}`, `…/restore` | GET list, POST; PUT, DELETE; POST |
| `/{uuid}/trips`, `/{uuid}/trips/{trip}`, `…/restore` | POST; PUT, DELETE; POST |
| `/{uuid}/trips/prefill` | GET |
| `/{uuid}/energy`, `/{uuid}/energy/{fillUp}`, `…/restore` | POST; PUT, DELETE; POST |
| `/{uuid}/energy/prefill` | GET |
| `/{uuid}/maintenance`, `/{uuid}/maintenance/{record}`, `…/restore` | POST; PUT, DELETE; POST |
| `/{uuid}/maintenance/prefill` | GET |
| `/{uuid}/expenses`, `/{uuid}/expenses/{expense}`, `…/restore` | POST; PUT, DELETE; POST |
| `/{uuid}/expenses/prefill` | GET |
| `/{uuid}/timeline`, `/{uuid}/timeline/{type}/{entry}` | GET |
| `/{uuid}/gaps`, `/{uuid}/gaps/{trip}/close` | GET; POST |
| `/{uuid}/kpis`, `/{uuid}/costs/{year}` | GET |
| `/{uuid}/reminders`, `/{uuid}/reminders/{reminder}`, `…/restore` | GET list, POST; PUT, DELETE; POST |
| `/{uuid}/reminders/{reminder}/snooze`, `…/dismiss` | POST |
| `/{uuid}/reminder-templates` | GET |
| `/{uuid}/recipients`, `/{uuid}/recipients/{recipient}` | GET, POST; DELETE |
| `/{uuid}/grants`, `/{uuid}/grants/{grant}` | GET, POST; PUT, DELETE |
| `/{uuid}/access` | GET what the caller holds, DELETE to leave it |
| `/{uuid}/documents`, `/{uuid}/documents/{document}`, `…/restore` | GET, POST; DELETE; POST |
| `/{uuid}/import/preview`, `/{uuid}/import`, `/{uuid}/import/undo` | POST, which writes nothing; POST with the preview's `etag`; POST with the import's `created` ([architecture](architecture.md#import)) |
| `/{uuid}/bookings`, `/{uuid}/bookings/{booking}` | GET, POST; PUT, DELETE (a cancel) |
| `/{uuid}/bookings/{booking}/check-out`, `…/check-in` | POST |

And beside them, under `/api/v1`: `/reminders` (GET, every vehicle's at once), `/preferences` (GET,
PUT), `/inbox` (GET) and `/sync` (GET, [below](#sync)).

A Reading names the Entry that wrote it as `source_uuid`: the trip, fill-up or maintenance record
its `source_type` says, or null for an Odometer Entry, which is its own Entry. Tie the two by it;
`source_id` is the server's row id and means nothing to a client.

Recipients, grants and documents answer the whole list as it now stands, and access what the
caller now holds; their writes carry no token. A booking's handover carries none either: what
counts is the state the booking is in when the car is taken or given back.

## Sync

`GET /api/v1/sync?cursor=&limit=` hands a client what changed since its last call. The first call
sends no cursor; every answer hands out the one the next call sends.

| Key | Holds |
| --- | --- |
| `vehicles` | Every vehicle the caller reaches, in full, on every call: `odo_value` and the other caches move without the vehicle's token |
| `changes` | By table - `readings`, `trips`, `energy`, `maintenance`, `expenses`, `reminders`, `documents`, `bookings`, `grants` - the rows changed since the cursor |
| `unreachable` | The vehicles the last answer listed that the caller no longer reaches: revoked, left, or in the trash. The client drops them and their rows |
| `cursor` | What the next call sends. Opaque; one the route did not hand out is a 400 |
| `more` | Another page waits: call again with `cursor` now |
| `reset` | An account was erased since the cursor: its rows were pseudonymised and kept their `updated_at`. The client drops what it holds and starts from this answer |

Each item of `changes` is `vehicle_uuid`, `uuid`, `updated_at`, `deleted_at` and `row`: the row as
its own list route answers it, or null for a deleted row - a tombstone. `grants` come for the caller's
own vehicles only.

- **At least once.** A run's mark is the server's clock at its first page minus 120 seconds. A row is
  stamped before its transaction commits, so one stamped in the second of a call can land after it;
  every row younger than the mark comes again next time. The client upserts by `uuid`, keeping the
  higher `updated_at`.
- **Whole where a token cannot tell.** A vehicle the client does not hold yet - new, granted, granted
  again, restored - comes with every live row. When one Reading of a vehicle changed, all its live
  Readings come: settling the counter re-flags them without moving their tokens.
- **Paging.** `limit` rows a page, 500 unless named, 1 to 2000, in (`updated_at`, table, `id`)
  order. Outside that range Nextcloud 34 refuses the request itself, as a 500; on 31 it is our 400.
  What a run covers counts as held once its last page, `more: false`, is answered.
- **As of the call.** A field worked out on read - a fill-up's or a booking's `flags`, `may`, a
  paper's `name`, a reminder's `state` and `estimate` - is what it was when its row was sent, and
  comes again only with the row.

## Downloads

A file is not an answer in the envelope, so these stay internal routes, under
`/index.php/apps/nextfleet/vehicles/{uuid}`. Each is a GET that takes the same app password over
Basic auth and no CSRF token; `OCS-APIRequest` is not needed. A refusal is JSON with `message`.

| Path | What it serves |
| --- | --- |
| `/documents/{document}` | A paper's file, always as an attachment |
| `/csv/{year}/{table}` | One table of a year as CSV |
| `/logbook/{year}`, `/mileage/{year}` | The printable logbook and mileage claim, as HTML |

## Rate limits

Nextcloud counts calls per user and per minute, and answers a 429 past the limit:

| Route | Calls a minute |
| --- | --- |
| Every write | 60 |
| An import preview, an import, its undo | 30 |
| Sync | 60 |
| A paper's file | 60 |
| CSV, logbook, mileage claim | 20 |

No other read is limited. Nextcloud counts per controller method, so an OCS route and
its internal twin count apart: a user can write a route 60 times a minute through each door
([security](security.md#the-api)).

## The document

`openapi.json`, at the app's root and in its release, describes every OCS route: its parameters,
its answers and its refusals. `composer openapi` writes it with Nextcloud's `openapi-extractor`, read
from the controllers in `lib/Controller/Ocs/` and the shapes in `lib/ResponseDefinitions.php`; CI
regenerates it and fails when the committed one differs.

Every body field is optional and nullable in it, because whether a field is required is the
service's rule and a missing one is the service's 400. The field's description says `required:`
where the service wants it.

## What v1 promises

v1 only grows. A client built against it keeps working: no path, method or media type goes, no
answer loses a field or a success status, no field changes its type or may suddenly be null or
missing, and no request needs a field or parameter it did not need. New routes, new fields in an
answer, new optional parameters and new refusals are not a break. Anything else is a v2.

The promise covers what the document cannot show, too: a token and its 412, the sync protocol with
its at-least-once delivery and its `reset`, the time and units above. Those change only in a v2.

`tests/Api/v1-baseline.json` is the document as v1 first promised it.
`tests/Unit/Api/ContractTest.php` fails when `openapi.json` breaks one of its promises; a pure
addition passes it.

The baseline moves on purpose only, so that additions become promises too: copy `openapi.json`
over it once the change that added them is released, with a `CHANGELOG.md` line naming what v1 now
promises besides. Never edit it to let a break through.
