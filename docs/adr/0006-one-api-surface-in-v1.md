# v1 has one internal API; the OCS API arrives with the client that needs it

The plan called for an internal route set and a versioned OCS API built together from day one, with
contract snapshot tests failing CI on a breaking change. With the Android client deferred, that is a
public contract maintained for nobody — the same speculative cost the plan rejects elsewhere. v1
ships the internal API only, and `GET /sync?since=` with it. The discipline that makes the OCS layer
cheap later is kept: controllers stay thin, all logic lives in services, no business rules in the
route layer.
