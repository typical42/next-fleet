# GDPR erasure of a driver pseudonymises, it does not delete records

When a driver exercises erasure, the user id on their trips is pseudonymised and the records
remain. That id is `created_by`, the column every row carries: a trip names the person who entered
it and no one else, and a driver column of its own only arrives with the sharing milestone
([data model](../architecture.md#data-model)). Those records belong to the vehicle's owner — they
are the owner's logbook, and under
Logbook Mode they carry a retention obligation. A co-driver leaving must not shred someone else's
tax evidence. Deleting the rows would be the naive reading of erasure and the one that destroys
data the app exists to protect.

## Addendum 2026-10-03: an erased owner's vehicles close

An erased owner leaves vehicles nobody may decide over. The erasure now revokes every grant on
them and soft-deletes them, and the rows stay as above, kept under Art. 17(3)(b) and
Art. 6(1)(f) GDPR ([legal](../legal.md)). `occ nextfleet:transfer` lets an admin hand a vehicle to
another account before the owner's is deleted.
