# Decision records

Decisions that are hard to reverse, each with its reason.
Where another document disagrees with an ADR, the ADR wins.
A decision that no longer applies is removed, and the one that replaced it says why.

| ADR | Decision |
|---|---|
| [0001](0001-own-access-table.md) | Vehicle access is our own table, not Nextcloud's share manager |
| [0002](0002-uk-is-a-test-jurisdiction.md) | The UK is a test jurisdiction, not a shipped one |
| [0003](0003-logbook-mode-does-not-lock-the-past.md) | Switching Logbook mode on locks nothing retroactively |
| [0004](0004-freelancers-not-fleets.md) | The primary user is a freelancer, not a fleet manager |
| [0005](0005-no-pdf-library.md) | Reports are HTML with a print stylesheet |
| [0007](0007-time-is-an-instant-plus-an-offset.md) | Every dated row stores a UTC instant and its offset |
| [0008](0008-erasing-a-driver-pseudonymises.md) | Erasing a driver pseudonymises, it does not delete |
| [0009](0009-the-ocs-api-v1-is-the-public-contract.md) | The OCS API v1 is the public contract |
| [0010](0010-the-vehicle-type-drives-rules-the-eu-codes-are-data.md) | The vehicle type drives rules, the EU codes are data |

This is the end of the developer path.
Back to the [developer guide](../developer/README.md).
