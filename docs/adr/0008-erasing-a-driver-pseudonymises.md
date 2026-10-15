# Erasing a driver pseudonymises, it does not delete records

When an account is deleted, its uid on every row is replaced with a random pseudonym.
The records stay.

They belong to the vehicle's owner.
They are the owner's logbook, and under Logbook mode the law says to keep them.
A co-driver who leaves must not shred someone else's tax evidence.
Deleting the rows is the naive reading of erasure, and it destroys the data the app exists to protect.

An erased owner leaves vehicles nobody may decide over.
So the erasure also revokes their grants and moves them to the trash.
The rows stay, kept under Art. 17(3)(b) and Art. 6(1)(f) GDPR ([legal](../legal.md)).
An admin can hand a vehicle to another account first, with `occ nextfleet:transfer`.
