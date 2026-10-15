# The vehicle type drives rules, the EU codes are data

A vehicle has a `vehicle_type`: car, motorcycle, van, truck, trailer, tractor or generator.
The rules read it: the mileage rate, the inspection, and the default counter unit.
Beside it sit two optional EU codes from the registration certificate: `eu_category` (field J) and `body_type`.
No rule reads them.

One field would not do.
The two do not map onto each other.
A generator has no EU category.
A bus or an agricultural trailer has no class of ours.
A rule keyed on the codes would need an answer for about a hundred of them, where a rate table needs seven.
And a rule cannot rest on a field most vehicles leave empty: few people know their codes, and no importer sets them.

So the codes are data.
A category pre-fills the type where the match is plain (M1 is a car), and the person may change it.
When the two disagree, the type wins.

Only EU codes (Reg. (EU) 2018/858, 168/2013 and 167/2013), no national lists, no free text.
The server checks the codes (`lib/Service/Registration.php`).
The frontend holds their words (`src/utils/registration.js`).
A new class for the rules, such as a bus, is a change to `VEHICLE_TYPES` and the jurisdictions, not to the codes.
