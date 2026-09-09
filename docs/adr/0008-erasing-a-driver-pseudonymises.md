# GDPR erasure of a driver pseudonymises, it does not delete records

When a driver exercises erasure, the user id on their trips is pseudonymised and the records
remain. That id is `created_by`, the column every row carries: a trip names the person who entered
it and no one else, and a driver column of its own only arrives with the sharing milestone
([data model](../architecture.md#data-model)). Those records belong to the vehicle's owner — they
are the owner's logbook, and under
Logbook Mode they carry a retention obligation. A co-driver leaving must not shred someone else's
tax evidence. Deleting the rows would be the naive reading of erasure and the one that destroys
data the app exists to protect.
