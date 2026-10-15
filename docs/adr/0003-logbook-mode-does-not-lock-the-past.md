# Switching Logbook mode on locks nothing retroactively

Logbook mode makes a vehicle's trips append-only from the moment it is switched on.
Older trips stay as they were, and the export states the date the mode began.

Locking them too would be cheap.
But it would claim an integrity those records never had: the audit trail cannot rebuild history that nobody recorded.
Admitting the gap is worth more than an export that lies.
