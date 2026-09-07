The shared country kit: one suite every jurisdiction takes, so a merged one is provably wired up
rather than merely present ([what a country owes us](../../docs/contributing.md#what-a-country-owes-us)).

`JurisdictionTestCase` asks a profile everything `IJurisdiction` asks and nothing a country would
answer differently. A country adds a directory with a case naming its profile, and `KitCoverageTest`
fails for a profile that is registered without one.

It asks profiles questions and needs no server, so it runs with the unit suite — plain
`vendor/bin/phpunit`, or `--testsuite country` for this one alone. A case may cover a profile `lib/`
does not ship; that is what the UK one is for ([ADR 0002](../../docs/adr/0002-uk-is-a-test-jurisdiction.md)).
