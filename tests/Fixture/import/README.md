# Import fixtures

Written by hand from each format's sources; no real export was available. A comment row would not
be honest CSV, so the sources are named here.

| File | Written from | Read |
| --- | --- | --- |
| `lubelogger-*.csv` | The headers: LubeLogger's export models, `Models/Shared/ImportModel.cs` in `hargata/lubelog` | 2026-10-03 |
| `lubelogger-fuel.csv`, `lubelogger-service.csv` | An en-US server's values: `Controllers/Vehicle/ImportController.cs`, the same repository | 2026-10-03 |
| `lubelogger-fuel-de.csv`, `lubelogger-service-de.csv` | A de-DE server's values, the same source | 2026-10-03 |
| `spritmonitor-fuel.csv` | German headers, codes: `LukasMaly/PySpritmonitor`, `pyspritmonitor/fuelings.py` and `resources/formats.json` | 2026-10-03 |
| `spritmonitor-fuel-en.csv` | English headers, the same sources | 2026-10-03 |
| `spritmonitor-costs.csv` | The cost type codes: `pyspritmonitor/entries.py` and `resources/formats.json`. The amount and counter headers have no source: they are the fuel export's | 2026-10-03 |

The two `BC-*` headers
stand for the on-board computer's columns: the prefix is from the sources, the rest is made up.
`\` is Spritmonitor's escape character, so a note or title may hold `\;`.

LubeLogger writes each value in its server's culture: a fuel date with midnight, a service cost
in the currency format, `$1,234.56` or `1.234,56 €` with a no-break space before the sign. The
other `lubelogger-*.csv` files keep plain values. `lubelogger-odometer.csv` leads with
`VehicleId, Id`, as some LubeLogger exports do. None of the files was checked against a real
export.
