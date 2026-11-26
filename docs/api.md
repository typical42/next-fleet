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
[Sync](#sync) has no twin: the web UI keeps nothing to bring up to date.
`…/costs/{year}` has none either: v1 promises it, and the web UI reads `…/statistics` instead.

## Answers

Every answer is the OCS envelope.
Its HTTP status is the envelope's `statuscode`.
`data` is the row, with the column names as keys (`lib/ResponseDefinitions.php`).

| Status | Means | The reason is in |
| --- | --- | --- |
| 200, 201 | Done; 201 for a new row | `data` is the row |
| 400 | The request is wrong | `data.message`; `data.reason` where a client may word it: `code_taken`, `currency_in_use`, `end_below_start`, `ends_in_future`, `in_use`, `name_taken`, `not_in_question`, `no_currency`, `no_energy`, `target_gone`, `year_taken` |
| 401 | No login, or a bad app password. Nextcloud answers it, before the app runs. | `meta.message` |
| 403 | The role does not cover this | `meta.message` |
| 404 | No such vehicle or row, or not on this vehicle | `meta.message`: `No such partner`, `No such cost centre` or `No such vehicle or budget` on their routes, else `No such vehicle` |
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
  Only the figures of `…/kpis`, `…/costs/{year}` and `…/statistics` are worked out, and some of them are floats.
- A Reading names the Entry that wrote it as `source_uuid`.
  `source_id` is a server row id and means nothing to a client.
- A trip names its partner record as `partner_uuid`; `partner` stays the text as entered ([ADR 0013](adr/0013-a-partner-is-a-record-the-trip-keeps-its-text.md)).
  A write sends the text only.
  The server links the record of that name in the owner's list, else the record another live trip with this text links, else a new one.
  An edit that keeps the text keeps the link.
- A trip, a fill-up, a maintenance record and an expense name a [cost centre](#cost-centres) as `cost_centre_uuid`, and so does a vehicle for the one its new entries take.
- `{fillUp}` in a path names an Energy Entry. v1 froze the path, so the old word stays.

## Costs

A vehicle's `may` holds `costs` when the caller sees what it costs.
The owner and managers always do; drivers and viewers while the vehicle's `costs_shared` is true, its default.
Only the owner changes `costs_shared`; a manager may send it back unchanged.

Without `costs`, the caller still sees every amount they entered themselves.
Of everything else:

- `purchase_price` and `residual_est` are null;
- a fill-up's `unit_price` and `total` and a maintenance record's `cost` are null, on every route;
- an expense is left out of the timeline, its single read, sync and the CSV file, and its update, delete and restore are a 404;
- a document linked to somebody else's fill-up, maintenance record or expense, a `receipt` on a booking somebody else is the booker of, and a `receipt` on the vehicle itself, is left out of `…/documents`, the list a write answers and sync, and its download, detach and restore are a 404;
- attaching a document to an expense left out is a 404, as for one that does not exist;
- `…/kpis` and `…/costs/{year}` answer with every amount in `cost` null, as for a period with nothing recorded;
- `…/statistics` adds nothing of the vehicle to `costs` or to the costs in `by_cost_centre`, gives no `budget`, and says so with `costs: false` in `vehicles`;
- `…/budgets` is empty, and sync hands out no budget;
- the mileage claim is a 403.

## Statistics

`GET /statistics` reads one vehicle, named by `vehicle`, or every vehicle the caller sees, disposed ones included.

- `from` and `to` are calendar days, `to` the day after the last; send neither for all of it.
  All of it runs from 1 January of the year of the first trip, fill-up, maintenance or expense to the end of the current year.
  Only an expense the caller sees counts ([costs](#costs)).
- `tz` is required: the buckets are cut at midnight there.
- `bucket` is `month` up to 24 months and `year` beyond; all of it is always `year`.
  `buckets` holds each one's `from` and `to`, the first and the last cut to the range.
- `costs` holds one series per currency, never a sum across them: each bucket's cost and the range's, as `…/costs/{year}` itemises them.
  A sum is null where nothing was recorded.
  A vehicle without a currency adds nothing.
- `per_distance` rates the same costs per 100 km, or per hour on a counter of hours: one series per currency and unit, each bucket's and the range's `total`, `distance` and `value` (cents).
  The sums count every vehicle; `value` only those with a distance.
  A bucket with a distance has a `value`, 0 when nothing was recorded in it; one without has none, whatever it cost.
  `vehicles` holds each vehicle's range.
  A vehicle's range `value` is the costs of its buckets with a distance over those distances, so it lies between its lowest and highest bucket.
  The fleet's range adds those up over the vehicles.
  `…/costs/{year}` rates its year the same way over its months, per distance and per engine hour, `tco` included, so its `value` is the range's of that year read by month.
- `consumption` holds each vehicle's per energy, over the full-to-full segments that close in the range: the amount over the distance, so a long segment weighs more.
  One vehicle's carries its `segments`; all vehicles' carry null.
  It needs no cost right.
- `distance` holds one series per unit: each bucket's and the range's `trips` and the distance of each category, `business`, `private` and `commute`.
  A trip falls in the bucket it set off in; one without a distance counts as a trip and adds none.
  Voided trips and Reconciliation Trips do not count: nobody drove them.
- `by_driver` holds one series per unit: each driver's `trips`, `distance` and `business` distance over the range, by who entered the trip, with their `name`.
  An erased driver keeps their pseudonym as both `user_id` and `name`.
  The owner and managers get every driver's; anyone else only their own.
  Each of `vehicles` says which in `drivers`.
- `by_cost_centre` holds a row per [cost centre](#cost-centres) that an entry in the range names, by code and name, then one with `uuid` null for the entries that name none.
  Each row has its `code` and `name`, `costs` with a `total` for each currency of any row, and `distance` with the `trips` and `distance` for each unit of any row.
  Costs count as `costs` does, from the vehicles whose costs the caller sees; distance counts as `distance` does, for everybody.
  A cost counts to the cost centre its own entry names; nothing is shared out by distance.
- `co2` is the vehicle's for the range, as `…/costs/{year}` states it; null when all vehicles are read.
- `budget` is one vehicle's [budget](#budgets) for a range that is one calendar year, with `year`, `amount`, `net`, `currency` and `spent`.
  `spent` counts as the warning does: net or gross as the budget says, over the year in the owner's zone, so it can differ from `costs`.
  It is null for all vehicles, any other range, a year without a budget, or a caller without [costs](#costs).

## Booking notices

There are five booking events: `booked`, `taken`, `returned`, `cancelled`, `changed`.
`…/booking-recipients` is the list: accounts and groups, each `recipient` with its `recipient_type` and the `events` it hears.
`POST` puts one on the list.
Posting one already on it with `events` sets them; without, it changes nothing.
A new one hears the events sent, else the vehicle's `booking_notices`.
`DELETE …/booking-recipients/{type}/{recipient}` takes one off, `type` being its `recipient_type`; a slash in a group id stays a plain slash in the path.
An empty list means the owner, who hears the vehicle's `booking_notices`.
A new vehicle's are `booked` and `taken`; `[]` hears none.
A change of `booking_notices` changes no recipient already on the list.
Only the owner changes the list, its events and `booking_notices`; a manager reads the list and may send `booking_notices` back unchanged.
The notices are Nextcloud notifications ([architecture](architecture.md#booking-notices)).

## Booking approval

While a vehicle's `booking_approval` is true, a driver's booking waits for an answer ([architecture](architecture.md#booking-approval)).
`booking_approval_rule` makes it so: `default` follows the owner's `booking_approval` in `/api/v1/preferences`, `always` and `never` do not.
A new vehicle is `default`, and the owner's default starts false.

- Writing `booking_approval_rule` sets the rule; it outranks `booking_approval` in the same request.
- Writing `booking_approval` sets `always` or `never`, but only where it differs from the value read.
  Sent back unchanged, it changes nothing, so a client that writes the whole vehicle keeps it `default`.
- Only the owner changes the rule; a manager may send both back unchanged.
- `/api/v1/preferences` also answers `owns_shared`: whether the caller owns a vehicle somebody else has or had access to.
  The web UI offers the default only then.

- A booking's `state` keeps its four values.
  `approval` says where the answer stands: null (none asked), `requested`, `asked`, `approved` or `declined`.
  `requested`, `asked` and `approved` mean something only while the state is `booked`; `declined` stays on the booking it cancelled, and the web UI shows it as **Declined**.
  A cancel keeps the last `approval` as history.
  `approval_comment` and `approval_by` hold the last answer's comment and who gave it; a change that makes the booking `requested` again clears both.
  `approval_comment` is null to anyone but the booker and the approvers.
- The owner's and the managers' own bookings are `approved` at once, and so are those they move.
  A booking that starts within five minutes and ends by the next midnight after the server's now is *Take it now*: its `approval` is null while the vehicle's `may` holds `take_now`, and `requested` without it.
  Midnight is in the booker's Nextcloud time zone, or in `starts_at_off` when they set none.
- A vehicle's `may` holds `take_now` always for the owner and the managers, and for a driver while one of their grants' `take_now` is true.
  A new grant's is true unless the request says otherwise; a request that does not send it keeps the grant's as it is.
- `POST …/bookings/{booking}/approve`, `…/ask` and `…/decline` answer a `requested` or `asked` booking before its end.
  Each takes `updated_at` and an optional `comment`, and is offered in the booking's `may` as `approve`, `ask` and `decline`.
  A decline cancels the booking.
  One still `requested` or `asked` at its end is declined within the hour, with `approval_by` null.
- A request holds its span.
  The 409 of a booking in the way carries its `approval`, and so does a vehicle's `my_next_booking`.
  `check_out` is not offered, and refused, until it is approved.
- A `PUT` with a new start or end makes the booking `requested` again, whatever its `approval`, unless an approver sends it: then it is `approved`.
  On an approved booking, a new purpose keeps the answer.
  The booker's `PUT` to an `asked` booking is their answer, a new purpose too: the booking is `requested` again.
- When `booking_approval` turns false, every booking that waits on the vehicle is approved: by its rule, or by the owner's default for each vehicle that follows it.
- `occ nextfleet:transfer` pins the rule to `always` or `never`, as `booking_approval` stood, so the new owner's default changes nothing.

## Booking for a driver

The owner or a manager may book for somebody else ([architecture](architecture.md#booking-for-a-driver)).

- `POST …/bookings` takes an optional `user_id`: whom the booking is for.
  Left out, or the caller's own, it books for the caller.
  Anybody else is refused with a 403 unless the caller answers the vehicle's bookings, and with a 400 unless the account may book the vehicle.
- `GET …/bookers` lists whom the caller may name: `user_id` and `display_name` of everybody who may book the vehicle, the caller included, by name.
  It answers 403 to anybody who does not answer the vehicle's bookings.
- The booking is the named account's: `user_id` and `user_name` are theirs, `created_by` and `created_by_name` the caller's.
  `user_id` is the uid as the instance spells it, in whatever case the request sent it.
  It is `approved` at once where the vehicle asks for approval, *Take it now* included, and has null `approval` where it does not.
- A retry under the same `client_uuid` answers the row it wrote, whatever became of the booker's access since.
- `seen_at` is when the booker confirmed it, null until then and on every booking they made themselves.
  Anyone but the booker and the approvers reads it as null.
- `POST …/bookings/{booking}/seen` confirms it, as its booker only, with no token; the first moment stays.
  It answers 403 to anybody else, and 400 on a booking the caller made themselves.
  The first confirmation moves the booking's `updated_at`, so sync hands it out; a client that read the booking before gets a 412 on its next write and reads it again.
  The booker's notice has a **Seen** action that posts this route.

## Partners

A partner list belongs to the vehicle owner, and all their vehicles share it ([ADR 0013](adr/0013-a-partner-is-a-record-the-trip-keeps-its-text.md)).
The routes name no vehicle.

| Route | Does | Who |
| --- | --- | --- |
| `GET /partners` | The caller's own list, by name | Anybody |
| `GET /partners/{record}` | One record | The owner; without `address` and `note`, whoever may `log` on one of the owner's vehicles |
| `PUT /partners/{record}` | Sets `name`, `address` and `note`, the whole record | The owner |
| `POST /partners/{record}/merge` | Merges it into `into`, another record of the list, and answers that record | The owner |
| `DELETE /partners/{record}` | Deletes it, with no undo | The owner |
| `GET /partners/{record}/trips?from=&to=` | Its live trips on the owner's vehicles, each with its `distance`, and their sum per unit | The owner |

- **A name is unique in its list**, compared after trimming and Unicode NFC, case kept.
  A rename onto a taken name is a 400 with `reason` `name_taken`: merge instead.
- **A merge into a record not on the list** is a 400 with `reason` `target_gone`, whether it was merged or deleted meanwhile or never was.
- **No trip text changes.**
  A merge and a delete move only links.
  Each trip whose link moved gets a new `updated_at`, so sync hands it over again.
- **A delete takes the name, the address and the note.**
  Its trips answer `partner_uuid: null`.
  A new trip with the same text makes a new record.
- **The trips** are those that set off in the period by their own offset, as the logbook dates them.
  `from` and `to` are calendar days as for [statistics](#statistics), or neither for all of them.

## Cost centres

A cost-centre list belongs to the vehicle owner, as a partner list does.
A record holds an optional `code`, such as `4711`, a `name`, and `archived`.

| Route | Does | Who |
| --- | --- | --- |
| `GET /cost-centres` | The caller's own list and that of every owner whose vehicle the caller reaches, archived records too, by owner and name | Anybody |
| `POST /cost-centres` | Adds `code` and `name` to the caller's own list | Anybody, for their own list |
| `PUT /cost-centres/{record}` | Sets `code`, `name` and `archived`, the whole record | The owner |
| `DELETE /cost-centres/{record}` | Deletes it while nothing names it | The owner |

- **Code and name are unique in their list**, compared after trimming and Unicode NFC, case kept.
  A taken one is a 400 with `reason` `code_taken` or `name_taken`.
- **One that an entry or a vehicle names stays.**
  Its delete is a 400 with `reason` `in_use`; archive it instead.
  An archived one stays on its entries and is no longer offered.
- **An entry names one of its vehicle owner's list** in `cost_centre_uuid`.
  Any other uuid is a 400.
  A create that leaves the field out takes the vehicle's `cost_centre_uuid`, unless that one is archived; `null` is none.
  An edit that leaves it out keeps the entry's own, so a client that does not know the field loses nothing.
  The vehicle's default changes no entry already there.
  The import takes the default, and so does a check-in's `trip_draft`.
- **A trip's cost centre is on its trail** like any field, but no logbook prints it.
- **Whether to show the field**: a vehicle's `uses_cost_centres` says whether its owner uses cost centres and budgets.
  They do once they share a vehicle, or once they set `cost_centres` true in `/api/v1/preferences`.
  The server takes the field either way.

## Budgets

A budget is the amount the owner plans for one vehicle in one calendar year: `year`, `amount` in cents of the vehicle's currency, and `net`.

| Route | Does | Who |
| --- | --- | --- |
| `GET /vehicles/{uuid}/budgets` | The vehicle's budgets, by year | Whoever sees its [costs](#costs); empty for the others |
| `POST /vehicles/{uuid}/budgets` | Sets `year`, `amount` and `net` | The owner |
| `PUT /vehicles/{uuid}/budgets/{budget}` | Sets `amount` and `net`; the year stays | The owner |
| `DELETE /vehicles/{uuid}/budgets/{budget}` | Deletes it, with no undo | The owner |

- **One per year.**
  A second for a year is a 400 with `reason` `year_taken`.
  A vehicle without a currency is a 400 with `reason` `no_currency`, and its currency stays while a budget does.
- **`net` counts the costs the same way**, net of VAT or gross, whatever the reader's VAT switch.
  An entry without a VAT rate counts gross.
- **The year is the calendar year in the owner's time zone.**
- **The warning** comes at 80 % and at 100 % of the costs of the year, to the owner and the managers, as a notification and, unless the vehicle's `reminder_mail` is `off`, a mail ([architecture](architecture.md#reminder-engine)).
  Costs that pass both shares at once are told the 100 % share only.
  An edit that leaves the costs below a share it told arms that share again.

## Sync

`GET /api/v1/sync?cursor=&limit=` hands a client what changed since its last call.
The first call sends no cursor.
Each answer hands out the next one.

| Key | Holds |
| --- | --- |
| `vehicles` | Every vehicle the caller reaches, in full, on every call. Their caches move without a token. |
| `changes` | Per table, the rows changed since the cursor: `vehicle_uuid`, `uuid`, `updated_at`, `deleted_at` and `row`, which is null for a deleted row. `budgets` is one of them, left out for a caller without [costs](#costs). |
| `partners` | The [partner](#partners) lists of every owner on one of whose vehicles the caller may `log`, as `changes` holds rows but with the owner's `user_id` for `vehicle_uuid`. A viewer gets none: a name is personal data, and only a trip needs it. `address` and `note` go to the owner alone. On a run's first page only. |
| `cost_centres` | The [cost-centre](#cost-centres) lists of every owner whose vehicle the caller reaches, a viewer's too: a viewer names the cost centre an entry shows. The same way as `partners`. |
| `unreachable` | Vehicles the client held that it no longer reaches. Drop them and their rows. |
| `cursor` | What the next call sends. Opaque. |
| `more` | Another page waits. |
| `reset` | An erasure, a transfer, a change of `costs_shared`, a change of access to a vehicle that keeps its costs, a role change that gives or takes `edit`, or a grant or a group join that gives `log` happened. Drop everything and start from this answer. |

- **At least once.**
  A run's mark is the server's clock at its first page minus 120 seconds.
  A row is stamped before its transaction commits, so a late commit could slip past otherwise.
  Upsert by `uuid` and keep the higher `updated_at`.
- **Whole where a token cannot tell.**
  A vehicle the client does not hold yet (new, granted, restored) comes with every live row.
  So do the partner and cost-centre lists of an owner none of whose vehicles the client holds yet.
  Drop an owner's cost-centre list once they own none of `vehicles`, and their partner list once no vehicle of theirs in `vehicles` has `log` in its `may`: no delete in them reaches you, and they come whole if you reach the owner again.
- **Paging.**
  `limit` is 1 to 2000, 500 by default, in (`updated_at`, table, `id`) order.
  A run counts as held once its last page, `more: false`, is answered.
- **As of the call.**
  A field worked out on read, such as `flags`, `may` or a reminder's `state`, is what it was when its row was sent.

## Downloads

A file is not an envelope, so these stay internal routes under `/index.php/apps/nextfleet`.
Each is a `GET` with the same app password, no CSRF token and no `OCS-APIRequest`.
A refusal is JSON with `message`.

| Path | Serves |
| --- | --- |
| `/vehicles/{uuid}/documents/{document}` | A paper's file, always as an attachment |
| `/vehicles/{uuid}/csv/{year}/{table}` | One table of one year as CSV |
| `/vehicles/{uuid}/logbook/{year}`, `/vehicles/{uuid}/mileage/{year}` | The printable logbook and mileage claim, as HTML |
| `/partners/{record}/csv?from=&to=` | A partner's trips as CSV, as `…/partners/{record}/trips` reads them |

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
