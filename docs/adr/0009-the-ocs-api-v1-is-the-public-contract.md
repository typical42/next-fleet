# The OCS API v1 is the public contract

The contract for clients is a versioned OCS API under `/ocs/v2.php/apps/nextfleet/api/v1`.
It is not the internal routes opened to app passwords.

At first the app had only internal routes: a contract kept for no client is a cost for nothing.
An Android client is now planned, and it needs one.

OCS is what Nextcloud clients already speak.
`OCS-APIRequest` exempts it from the CSRF check, Login Flow v2 hands out its app passwords, and Nextcloud's `openapi-extractor` reads it.
The web UI keeps its internal routes, which promise nothing.
Each OCS route twins one and makes the same service call.
One rule, two doors: the second door stays cheap only while neither holds a rule.

v1 only grows ([what v1 promises](../api.md#what-v1-promises)).
A test holds `openapi.json` to the first v1 baseline, so a break fails before a client sees it.

Sync is a delta by `updated_at`, delivered at least once, with no change-log table.
Fleets are a handful of vehicles, so sending a row twice is cheap, and a table would cost a migration.
For this, a restore moves the token like any other write ([concurrency](../architecture.md#concurrency)).
