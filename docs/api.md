# The OCS API

What a client other than the web UI is built against.
It lives at `/ocs/v2.php/apps/nextfleet/api/v1` and is the app's public contract ([ADR 0009](adr/0009-the-ocs-api-v1-is-the-public-contract.md)).
`openapi.json` at the app's root lists every route, parameter and answer.
This page says what the document cannot.

## Signing in

A client signs in with a Nextcloud app password over Basic auth.
The app keeps no credentials of its own.

- **Login Flow v2** gets one without the client seeing the user's password.
  The client `POST`s `/index.php/login/v2`, opens the `login` URL in a browser, and polls `endpoint` with `token` until it gets `server`, `loginName` and `appPassword`.
  The token lasts 20 minutes.
- **By hand**, the user makes one in Personal settings → Security → Devices & sessions.

Revoking it there signs the client out ([security](security.md#the-api)).

```sh
curl -u anna:<app password> \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  https://cloud.example/ocs/v2.php/apps/nextfleet/api/v1/vehicles
```

`OCS-APIRequest: true` exempts the request from Nextcloud's CSRF check.
Without `Accept: application/json`, OCS answers XML.
Send bodies as JSON: some fields need a real boolean, integer or null, which a form cannot carry.

## One rule, two doors

Each OCS route twins an internal route of the web UI: same path under `/api/v1`, same verb, same parameters, a controller of the same name in `lib/Controller/Ocs/`.
Both make the same service call, so neither holds a rule ([architecture](architecture.md#stack-and-layers)).
`OcsRoutesTest` holds the routes to that, and `VehicleIdorTest` walks every OCS route by role.
[Sync](#sync) is the one route without a twin: the web UI keeps nothing to bring up to date.

## Answers

Every answer is the OCS envelope.
Its HTTP status is the envelope's `statuscode`.
`data` is the row, with the column names as keys (`lib/ResponseDefinitions.php`).

| Status | Means | The reason is in |
| --- | --- | --- |
| 200, 201 | Done; 201 for a new row | `data` is the row |
| 400 | The request is wrong | `data.message`; `data.reason` where a client may word it: `currency_in_use`, `end_below_start`, `ends_in_future`, `not_in_question`, `no_energy` |
| 401 | No login, or a bad app password. Nextcloud answers it, before the app runs. | `meta.message` |
| 403 | The role does not cover this | `meta.message` |
| 404 | No such vehicle or row, or not on this vehicle | `meta.message` |
| 409 | A booking in the way, an import file changed since its preview, or an undo that names a foreign entry | `data.message`, `data.booking` |
| 412 | Changed since the client read it | `data.conflict` is `true` |
| 422 | An import file that will not be read | `data.reason`, such as `too_large`; `data.row` |
| 423 | Somebody is writing the import file; send again | `data.message` |
| 429 | Too many calls ([rate limits](#rate-limits)) | — |

A 412 without `conflict` is Nextcloud's failed CSRF check, not ours.

## Writes and tokens

An edit, a delete and a restore carry the `updated_at` the client last read: in the body of a `PUT` or `POST`, in the query of a `DELETE`.
Each answers with the new `updated_at`, which the next write carries ([concurrency](architecture.md#concurrency)).

Recipients, grants, documents and a booking's handover take no token.
They answer the whole list, or depend on the state at the moment the car changes hands.

## Retried creates

A phone that loses the answer to a create cannot tell whether the row landed.
So each create takes an optional `client_uuid`, a uuid the client picks.
The row gets it as its `uuid`.

- The same `client_uuid` again on the same vehicle writes nothing and answers the row as it now stands.
  A row deleted since answers with its `deleted_at`: a retry does not undo a delete.
- The field is `client_uuid`, not `uuid`, because a body field would replace the route's `{uuid}`.
- A `client_uuid` that another vehicle's row holds is a 400.
  Pick a fresh version 4 uuid from a secure random source for each row.
- An import sent again within an hour, same request and same file, answers the first import's answer.
  This needs a memory cache on the server; without one the retry imports again.

## Time and units

The wire carries what the database stores ([data model](architecture.md#data-model)):

- **An instant** is a unix second with `<name>_off` beside it, the offset in minutes where it happened ([ADR 0007](adr/0007-time-is-an-instant-plus-an-offset.md)).
  Show the local time the offset gives, not your own.
- **A calendar day**, such as `first_reg`, is a `YYYY-MM-DD` string.
- **Amounts are integers** in their stored unit: cents with the vehicle's `currency`, kilometres or hours by `odo_unit`, millilitres, watt-hours.
  Only the figures of `…/kpis` and `…/costs/{year}` are worked out, and some of them are floats.
- A Reading names the Entry that wrote it as `source_uuid`.
  `source_id` is a server row id and means nothing to a client.
- `{fillUp}` in a path names an Energy Entry. v1 froze the path, so the old word stays.

## Sync

`GET /api/v1/sync?cursor=&limit=` hands a client what changed since its last call.
The first call sends no cursor.
Each answer hands out the next one.

| Key | Holds |
| --- | --- |
| `vehicles` | Every vehicle the caller reaches, in full, on every call. Their caches move without a token. |
| `changes` | Per table, the rows changed since the cursor: `vehicle_uuid`, `uuid`, `updated_at`, `deleted_at` and `row`, which is null for a deleted row |
| `unreachable` | Vehicles the client held that it no longer reaches. Drop them and their rows. |
| `cursor` | What the next call sends. Opaque. |
| `more` | Another page waits. |
| `reset` | An erasure or a transfer happened. Drop everything and start from this answer. |

- **At least once.**
  A run's mark is the server's clock at its first page minus 120 seconds.
  A row is stamped before its transaction commits, so a late commit could slip past otherwise.
  Upsert by `uuid` and keep the higher `updated_at`.
- **Whole where a token cannot tell.**
  A vehicle the client does not hold yet (new, granted, restored) comes with every live row.
- **Paging.**
  `limit` is 1 to 2000, 500 by default, in (`updated_at`, table, `id`) order.
  A run counts as held once its last page, `more: false`, is answered.
- **As of the call.**
  A field worked out on read, such as `flags`, `may` or a reminder's `state`, is what it was when its row was sent.

## Downloads

A file is not an envelope, so these stay internal routes under `/index.php/apps/nextfleet/vehicles/{uuid}`.
Each is a `GET` with the same app password, no CSRF token and no `OCS-APIRequest`.
A refusal is JSON with `message`.

| Path | Serves |
| --- | --- |
| `/documents/{document}` | A paper's file, always as an attachment |
| `/csv/{year}/{table}` | One table of one year as CSV |
| `/logbook/{year}`, `/mileage/{year}` | The printable logbook and mileage claim, as HTML |

## Rate limits

Nextcloud counts calls per user and minute, and answers 429 past the limit:

| Route | Calls a minute |
| --- | --- |
| Every write, sync, a paper's file | 60 |
| Import preview, import, undo | 30 |
| CSV, logbook, mileage claim | 20 |

Other reads have no limit.
Nextcloud counts per controller method, so the two doors count apart.

## The document

`composer openapi` writes `openapi.json` from `lib/Controller/Ocs/` and `lib/ResponseDefinitions.php` with Nextcloud's `openapi-extractor`.
CI regenerates it and fails when the committed one differs.
Every body field is optional and nullable there, because "required" is the service's rule.
The field's description says `required:` where it is.

## What v1 promises

v1 only grows.
A client built against it keeps working:

- No path, method or media type goes away.
- No answer loses a field or a success status.
  No field changes its type or becomes nullable.
- No request needs a new field.
  No request field narrows its range or loses an `enum` value, and no answer field gains one.

New routes, new answer fields, new optional parameters and new refusals are not a break.
Anything else is a v2.
The promise also covers what the document cannot show: the token and its 412, the sync protocol, and the time and units above.

`tests/Api/v1-baseline.json` is the document as v1 promised it, and `ContractTest` fails on a break.
Copy `openapi.json` over the baseline only when a release ships the additions, so they become promises too.
Never edit it to let a break through.

Next: [Security](security.md)
