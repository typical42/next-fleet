# Every dated row stores a UTC instant and its UTC offset

A Fahrtenbuch is judged on local dates.
A trip that ends 00:30 in Berlin is on the day before in UTC.
With the instant alone, the app would have to guess a time zone when it reads, and the guess would be the server's, not the driver's.

So each dated row stores both: the instant and the offset where it happened.
Reports derive the local date from them.
Order and durations use the instant.
The entry's own time is the user's and can be edited.
`created_at` is the server's and never comes from a client.
