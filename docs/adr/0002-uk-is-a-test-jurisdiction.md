# The UK is a test jurisdiction, not a shipped one

`tests/Country/` carries a UK jurisdiction — miles, mpg, MOT, pence per mile — so that unit
assumptions hiding in the core break before v1 rather than after it. It is not shipped in `lib/`.
Shipping it would put our name and our signed release behind UK rates and deadlines nobody here can
maintain. v1 ships `de` plus a generic jurisdiction (metric, no logbook ruleset, no inspection
scheme) for every other install. A real `uk` ships when a maintainer signs up in `CODEOWNERS`.
