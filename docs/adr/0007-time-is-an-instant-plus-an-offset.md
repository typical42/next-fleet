# Every dated row stores a UTC instant and its originating UTC offset

A Fahrtenbuch is judged on local calendar dates: a trip ending 00:30 in Berlin belongs to the
previous day in UTC. Storing the instant alone forces a timezone guess at read time, and the guess
would be the server's rather than the driver's. So each dated row carries both facets. Reporting and
legal views derive the local date from them; ordering and durations use the instant. `occurred_at`
is the user's and editable, `created_at` is the server's and never accepted from a client.
