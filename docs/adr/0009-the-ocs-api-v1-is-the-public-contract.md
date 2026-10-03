# The OCS API v1 is the public contract

Supersedes [ADR 0006](0006-one-api-surface-in-v1.md). Its reason, no contract kept for nobody, has
expired rather than been reversed: an Android client is to be built, and it needs one.

The contract is a versioned OCS API under `/ocs/v2.php/apps/nextfleet/api/v1`, not the internal
routes opened to app passwords. OCS is what Nextcloud clients already speak: `OCS-APIRequest`
exempts it from the CSRF check, Login Flow v2 hands out its app passwords, and Nextcloud's
`openapi-extractor` reads it. The web UI keeps the internal routes, which promise nothing outside
the app. Each OCS route twins one and calls the same service with the same arguments; neither
controller holds a rule. One rule, two doors — the second door stays cheap only while that holds.

v1 only grows ([what v1 promises](../api.md#what-v1-promises)). `openapi.json` is generated from
the controllers, and a test holds it to the baseline v1 first promised, so a break fails before a
client sees it. Anything that would break a client is a v2.

Sync is a delta by `updated_at`, delivered at least once, with no change-log table: the fleets are
a handful of vehicles, so sending a vehicle or its readings again is cheap, and a table would have
cost a migration. For that, a restore now advances the token, like every other write
([concurrency](../architecture.md#concurrency)).
