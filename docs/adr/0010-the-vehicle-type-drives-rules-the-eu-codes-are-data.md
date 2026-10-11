# The vehicle type drives rules, the EU codes are data

A vehicle has a `vehicle_type`: car, motorcycle, van, truck, trailer, tractor or generator. The
rules read it: a jurisdiction's mileage rate (`IRateProvider::mileageRateAt`), its inspection
template and interval (`IInspectionScheme`), and the sheet's default counter unit. Beside it sit the
two EU codes of the registration certificate, `eu_category` (field J) and `body_type` (the body
code and its add-on). They are optional, and no rule reads them.

One field would not do. The two do not map onto each other. A generator has no EU category. A bus
(M2, M3) and an agricultural trailer (R) have no class of ours, and a three-wheel moped (L2e) or a
quad (L6e, L7e) is not plainly a motorcycle. So a rule keyed on the codes needs an answer for each
of about a hundred of them, where a rate table or an inspection scheme has seven. And a rule cannot
rest on a field most vehicles leave empty: few people know their codes, and no importer sets them.

So the codes are data. A category pre-fills the vehicle type where the match is plain (M1 is a car),
and the person may change it; nothing flows back from the type to the category. The two can
disagree, and the type wins.

Only EU codes: Reg. (EU) 2018/858, 168/2013 and 167/2013. No national lists, such as the German
KBA keys, and no free text; body code `SG` and add-on `99` cover the gaps. The server validates the
codes and holds no words for them (`lib/Service/Registration.php`); the frontend holds the words
(`src/utils/registration.js`); a spec holds the two code lists equal. A new class for the rules,
such as a bus, is a change to `VEHICLE_TYPES` and the jurisdictions, not to these codes.
