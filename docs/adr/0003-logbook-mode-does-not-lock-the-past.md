# Switching Logbook Mode on locks nothing retroactively

Turning Logbook Mode on for a vehicle makes its trips append-only from that moment. Trips recorded
before it stay as they were, and the export states the date the mode began. Locking them too would
be cheap to implement and would claim an integrity those records never had — the audit trail cannot
reconstruct history that was never recorded. Admitting the gap is worth more than a plausible export
that lies. Related: a delete under Logbook Mode voids the trip rather than removing it.
