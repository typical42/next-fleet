PHPUnit with no server and no database — the layer for the logic worth trusting, with mappers and
Nextcloud's interfaces mocked against the `nextcloud/ocp` stubs.

Its bootstrap is `../bootstrap.php`, its config `../../phpunit.xml`. Run it on the host with
`composer test`, not in the container: `LicensingTest` and `PackageTest` need git. More in
`../../docs/development.md#testing`.
