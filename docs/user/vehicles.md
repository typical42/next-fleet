# Vehicles

A vehicle is a car, motorcycle, van, truck, trailer, tractor or generator. All entries, reminders,
documents and bookings belong to one vehicle.

## Add a vehicle

1. In the navigation, click **New vehicle**. On the **Overview** page, you can also push the `N`
   key.
2. Type the data that you know:
   - **Registration plate**, **Manufacturer** and **Model**.
   - **Display name**: optional, for example `Blue van`. Refer to [Display name](#display-name).
   - **Engine**: **Petrol**, **Diesel**, **LPG**, **CNG**, **Electric** or **Hybrid**.
   - **Vehicle type**.
   - **Counter unit**: **Kilometres** or **Engine hours**. A tractor and a generator use engine
     hours by default.
   - **Counter reading**: the current value. It becomes the first counter reading.
3. Click **Add vehicle**.

   NextFleet opens the vehicle page.

A new vehicle gets the country from your [personal settings](settings.md). The country sets the
units, the currency, the VAT rates, the inspection and the logbook rules.

### Display name

The display name is a second name for the vehicle, with a maximum of 64 characters. All users who
can see the vehicle see it in the navigation, on the overview and on the vehicle page, under the
plate. The plate stays the name in mails, notifications, exports, the audit trail and the logbook.

A vehicle without a plate shows its display name instead. A vehicle with neither shows its
manufacturer and model.

## The vehicle page

The vehicle page shows these parts, from top to bottom:

1. The name of the vehicle, with the **New entry**, **Costs**, **Edit vehicle** and **QR sticker**
   buttons.
2. The figures for the period that you select in the **Period** list. The [Costs](costs.md) page
   describes them.
3. The reminders, with the **+ Reminder** button. Refer to [Reminders](reminders.md).
4. The **Bookings** section. It shows only if another user has or had access to the vehicle.
5. The **Documents** section. Refer to [Documents](documents.md).
6. The **Timeline**. Refer to [Entries and the timeline](entries.md).

The buttons depend on your [role](access.md#roles). A viewer sees only **Costs**. **Edit vehicle**
shows only for the owner and the managers.

The **Overview** page shows all your vehicles. The vehicles with the most urgent reminders are at
the top.

## Edit a vehicle

1. On the vehicle page, click **Edit vehicle**.
2. Change the fields.
3. Click **Save**.

Some fields need an explanation:

- **Energy types**: the energies that the vehicle uses. They decide which energy entries you can
  record.
- **Also counts engine hours**: a vehicle in kilometres can also record engine hours, for example a
  truck with a crane.
- **Currency**: a code with three letters, for example `EUR`. When the vehicle has a cost in a
  currency, you cannot change the currency.
- **Country**: **Germany** or **Generic**. **Generic** has no national rules.
- **Registration**: the EU type codes from the registration certificate. Refer to
  [Registration type](#registration-type).

If you are the owner or a manager, the overview can show **Some details are still missing**. Then
one or more of these fields is empty: VIN, first registration, tank size, battery capacity or
currency. **Still missing** names the fields. To add them, click the vehicle name, then click **Edit
vehicle**. To hide the message, click **Dismiss**.

### Registration type

The **Registration** group holds the EU vehicle category and the EU body type of the vehicle. Both
are optional. All EU countries use the same codes. [Where to find the codes](#where-to-find-the-codes)
says where your certificate shows them, and the tables after it list every code. Type a part of the
name or of the code to search a list. The code search ignores spaces, hyphens and dots: `L3e A2` finds `L3e-A2`, `T41a` finds
`T4.1a`. `N3G` finds `N3`; the off-road switch adds the `G`.

- **EU vehicle category**: field J on the registration certificate, for example `M1` for a
  passenger car or `L3e-A2` for a medium-performance motorcycle. The categories that suit the
  vehicle type show first.
- **Off-road vehicle (G)**: shows for the categories M1 to M3 and N1 to N3. Switch it on when field
  J ends in `G`, for example `N1G`.
- **EU body type**: the two letters of the body code, for example `AC` for a station wagon. The
  list shows only the body types of the category. It stays closed until you choose a category, and
  for the categories L, T, C, R and S, which have no body type. When you change the category or
  the off-road switch, a body type that does not fit any more is cleared.
- **Body add-on**: the two digits after the letters, for example `03` in `BA03` for a lorry with a
  box body. It shows only for the body types BA, BC, BD, BX and DA to DG.

A stored code the lists do not offer, such as a body type without a category, leaves its field
empty. Saving keeps it as long as you change nothing in the group.

When the vehicle has no display name, the navigation, the overview and the vehicle page show the
body type under the plate, after the manufacturer and model: "Volkswagen Passat · Station wagon".

A category can set the vehicle type for you, and the counter unit then follows the vehicle type
as when you choose it yourself. You can change both after that:

| Category | Vehicle type |
|---|---|
| M1 | Car |
| N1 | Van |
| N2, N3 | Truck |
| O1 to O4 | Trailer |
| L1e, L3e, L4e and their subcategories | Motorcycle |
| T and C | Tractor |

The other categories do not change the vehicle type. The vehicle type, not the category, decides
the mileage rate, the inspection and the default counter unit.

#### Where to find the codes

The codes are on part I of the registration certificate:

- **Field J** holds the category. Some countries do not print it on the certificate, but the
  registration authority has it on record.
- The **body code** has no EU-wide field. Germany prints it in **field 4**, France in **field
  J.2**. Other countries use a national field of their own.

On a German certificate, **field 5** says the same as fields J and 4 in German words. NextFleet
stores only the EU codes.

#### Vehicle categories

The tractor, trailer and towed-equipment codes end in a speed letter: `a` for a maximum of 40 km/h,
`b` for more than 40 km/h.

**Cars and buses**

| Code | Category |
|---|---|
| `M1` | Passenger car, up to 8 seats besides the driver |
| `M2` | Bus, more than 8 seats besides the driver, up to 5 t |
| `M3` | Bus, more than 8 seats besides the driver, over 5 t |

**Goods vehicles**

| Code | Category |
|---|---|
| `N1` | Light goods vehicle, up to 3.5 t |
| `N2` | Goods vehicle, over 3.5 t up to 12 t |
| `N3` | Goods vehicle, over 12 t |

**Trailers**

| Code | Category |
|---|---|
| `O1` | Trailer, up to 0.75 t |
| `O2` | Trailer, over 0.75 t up to 3.5 t |
| `O3` | Trailer, over 3.5 t up to 10 t |
| `O4` | Trailer, over 10 t |

**Two- and three-wheelers and quads**

A vehicle approved under the older EU rules carries only the main code, for example `L3e`. Choose
the code that your certificate shows.

| Code | Category |
|---|---|
| `L1e` | Light two-wheel powered vehicle |
| `L1e-A` | Powered cycle |
| `L1e-B` | Two-wheel moped |
| `L2e` | Three-wheel moped |
| `L2e-P` | Three-wheel moped for passenger transport |
| `L2e-U` | Three-wheel moped for utility purposes |
| `L3e` | Two-wheel motorcycle |
| `L3e-A1` | Low-performance motorcycle |
| `L3e-A1E` | Low-performance enduro motorcycle |
| `L3e-A1T` | Low-performance trial motorcycle |
| `L3e-A2` | Medium-performance motorcycle |
| `L3e-A2E` | Medium-performance enduro motorcycle |
| `L3e-A2T` | Medium-performance trial motorcycle |
| `L3e-A3` | High-performance motorcycle |
| `L3e-A3E` | High-performance enduro motorcycle |
| `L3e-A3T` | High-performance trial motorcycle |
| `L4e` | Two-wheel motorcycle with side-car |
| `L5e` | Powered tricycle |
| `L5e-A` | Tricycle |
| `L5e-B` | Commercial tricycle |
| `L6e` | Light quadricycle |
| `L6e-A` | Light on-road quad |
| `L6e-B` | Light quadri-mobile |
| `L6e-BU` | Light quadri-mobile for utility purposes |
| `L6e-BP` | Light quadri-mobile for passenger transport |
| `L7e` | Heavy quadricycle |
| `L7e-A` | Heavy on-road quad |
| `L7e-A1` | A1 on-road quad |
| `L7e-A2` | A2 on-road quad |
| `L7e-B` | Heavy all-terrain quad |
| `L7e-B1` | All-terrain quad |
| `L7e-B2` | Side-by-side buggy |
| `L7e-C` | Heavy quadri-mobile |
| `L7e-CU` | Heavy quadri-mobile for utility purposes |
| `L7e-CP` | Heavy quadri-mobile for passenger transport |

**Agricultural and forestry**

| Code | Category |
|---|---|
| `T1a`, `T1b` | Wheeled tractor, track width at least 1150 mm |
| `T2a`, `T2b` | Wheeled tractor, track width under 1150 mm |
| `T3a`, `T3b` | Wheeled tractor, up to 600 kg unladen |
| `T4.1a`, `T4.1b` | High-clearance tractor |
| `T4.2a`, `T4.2b` | Extra-wide tractor |
| `T4.3a`, `T4.3b` | Low-clearance tractor |
| `C1a`, `C1b` | Track-laying tractor, track width at least 1150 mm |
| `C2a`, `C2b` | Track-laying tractor, track width under 1150 mm |
| `C3a`, `C3b` | Track-laying tractor, up to 600 kg unladen |
| `C4.1a`, `C4.1b` | High-clearance track-laying tractor |
| `C4.2a`, `C4.2b` | Extra-wide track-laying tractor |
| `C4.3a`, `C4.3b` | Low-clearance track-laying tractor |
| `R1a`, `R1b` | Agricultural trailer, axle masses up to 1500 kg |
| `R2a`, `R2b` | Agricultural trailer, over 1500 kg up to 3500 kg |
| `R3a`, `R3b` | Agricultural trailer, over 3500 kg up to 21000 kg |
| `R4a`, `R4b` | Agricultural trailer, over 21000 kg |
| `S1a`, `S1b` | Interchangeable towed equipment, up to 3500 kg |
| `S2a`, `S2b` | Interchangeable towed equipment, over 3500 kg |

#### Body types

The last column names the categories that a body type fits. The off-road variant of a category fits
too: `AC` fits `M1` and `M1G`.

| Code | Body type | Categories |
|---|---|---|
| `AA` | Saloon | M1 |
| `AB` | Hatchback | M1 |
| `AC` | Station wagon | M1 |
| `AD` | Coupé | M1 |
| `AE` | Convertible | M1 |
| `AF` | Multi-purpose vehicle | M1 |
| `AG` | Truck station wagon | M1 |
| `CA` | Single-deck vehicle | M2, M3 |
| `CB` | Double-deck vehicle | M2, M3 |
| `CC` | Single-deck articulated vehicle | M2, M3 |
| `CD` | Double-deck articulated vehicle | M2, M3 |
| `CE` | Low-floor single-deck vehicle | M2, M3 |
| `CF` | Low-floor double-deck vehicle | M2, M3 |
| `CG` | Articulated low-floor single-deck vehicle | M2, M3 |
| `CH` | Articulated low-floor double-deck vehicle | M2, M3 |
| `CI` | Open-top single-deck vehicle | M2, M3 |
| `CJ` | Open-top double-deck vehicle | M2, M3 |
| `CX` | Bus chassis | M2, M3 |
| `BA` | Lorry | N1, N2, N3 |
| `BB` | Panel van | N1, N2, N3 |
| `BC` | Tractor unit for semi-trailer | N1, N2, N3 |
| `BD` | Road tractor | N1, N2, N3 |
| `BE` | Pick-up truck | N1 |
| `BX` | Chassis-cab or chassis-cowl | N1, N2, N3 |
| `DA` | Semi-trailer | O1, O2, O3, O4 |
| `DB` | Drawbar trailer | O1, O2, O3, O4 |
| `DC` | Centre-axle trailer | O1, O2, O3, O4 |
| `DE` | Rigid drawbar trailer | O1, O2, O3, O4 |
| `DF` | Link semi-trailer | O1, O2, O3, O4 |
| `DG` | Link drawbar trailer | O1, O2, O3, O4 |
| `SA` | Motor caravan | M1, M2, M3 |
| `SB` | Armoured vehicle | M1, M2, M3, N1, N2, N3, O1, O2, O3, O4 |
| `SC` | Ambulance | M1, M2, M3 |
| `SD` | Hearse | M1, M2, M3 |
| `SE` | Trailer caravan | O1, O2, O3, O4 |
| `SF` | Mobile crane | N3 |
| `SG` | Special group | M1, M2, M3, N1, N2, N3, O1, O2, O3, O4 |
| `SH` | Wheelchair-accessible vehicle | M1 |
| `SJ` | Converter dolly | O1, O2, O3, O4 |
| `SK` | Exceptional load transport trailer | O4 |
| `SL` | Exceptional load transport motor vehicle | N3 |
| `SM` | Multi-equipment carrier | N1G, N2G, N3G |

The regulation calls `BB` "Van". NextFleet says **Panel van**, because **Van** is already a vehicle
type. Use `SG` for a body that no other code names.

#### Body add-ons

Use `99` for a body that no other add-on names.

| Add-on | Body |
|---|---|
| `01` | Flat bed |
| `02` | Drop-side |
| `03` | Box body |
| `04` | Conditioned body with insulated walls and equipment to maintain the interior temperature |
| `05` | Conditioned body with insulated walls but without equipment to maintain the interior temperature |
| `06` | Curtain-sided |
| `07` | Swap body (interchangeable superstructure) |
| `08` | Container carrier |
| `09` | Vehicle fitted with hook lift |
| `10` | Tipper |
| `11` | Tank |
| `12` | Tank intended for transport of dangerous goods |
| `13` | Livestock carrier |
| `14` | Vehicle transporter |
| `15` | Concrete mixer |
| `16` | Concrete pump vehicle |
| `17` | Timber |
| `18` | Refuse collection vehicle |
| `19` | Street sweeper, cleansing and drain clearing |
| `20` | Compressor |
| `21` | Boat carrier |
| `22` | Glider carrier |
| `23` | Vehicle for retail or display purposes |
| `24` | Recovery vehicle |
| `25` | Ladder vehicle |
| `26` | Crane lorry (other than a mobile crane) |
| `27` | Aerial work platform vehicle |
| `28` | Digger derrick vehicle |
| `29` | Low-floor trailer |
| `30` | Glazing transporter |
| `31` | Fire engine |
| `32` | Drop-side tarpaulin |
| `99` | Bodywork not included in this list |

## Lay up or dispose of a vehicle

The **Lifecycle** field in **Edit vehicle** has three values:

| Value | Result |
|---|---|
| **Active** | The vehicle is in use. |
| **Laid up** | The reminders pause. Nobody can book the vehicle. The vehicle moves to the end of the overview. Use this value for a seasonal vehicle. |
| **Disposed of** | The vehicle is sold or scrapped. Type the date in **Disposed on**. The reminders stop. The vehicle leaves the navigation and moves to the **Disposed of** list at the bottom of the **Overview** page. Its records stay, and you can still print its logbook in **Reports**. |

Each user who can see a vehicle that is disposed of can open it from the **Disposed of** list. Only
the owner and the managers can set it back:

1. On the **Overview** page, in the **Disposed of** list, click the vehicle.
2. Click **Edit vehicle**.
3. In **Lifecycle**, select **Active** or **Laid up**.
4. Click **Save**.

## Delete a vehicle

Only the owner can delete a vehicle.

1. Click **Edit vehicle**.
2. Click **Delete vehicle**.

   A message shows at the bottom of the page.
3. To cancel the deletion, click **Undo** in the message.

> [!WARNING]
> When the message closes, you cannot restore the vehicle yourself. Then only your administrator
> can restore it.

The message closes when you click **Dismiss**. It also closes when you delete, remove or import
something, or when you change an entry.

## Find a vehicle

Use the search of Nextcloud. Type a part of the plate, the display name, the manufacturer or the
model. The search ignores spaces and hyphens in the plate. It does not find a vehicle that is
disposed of. Each result shows the display name under the plate, or else the manufacturer and
model.

## Print a QR sticker

A QR sticker in the glove box opens the entry form for the vehicle on a phone.

1. On the vehicle page, click **QR sticker**.
2. Click **Print**.

   The sticker is approximately 5 cm wide.

The code contains only the address of the vehicle page. A person who scans it must log in to
Nextcloud and must have access to the vehicle.
