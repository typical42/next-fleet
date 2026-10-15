# The UK is a test jurisdiction, not a shipped one

`tests/Country/` has a UK jurisdiction: miles, mpg, MOT, pence per mile.
It breaks the unit assumptions hiding in the core before they reach users.

It does not ship in `lib/`.
Shipping it would put our signed release behind UK rates and deadlines that nobody here can maintain.
Releases ship `de` and `generic`.
A real `uk` ships when a maintainer signs up in `CODEOWNERS`.
