# Vehicles

A vehicle is a car, motorcycle, van, truck, trailer, tractor or generator.
Each entry, reminder, document and booking belongs to one vehicle.

## Add a vehicle

1. Click **New vehicle** in the navigation.
   On the **Overview** page, you can also push `N`.
2. Type what you know.
   All fields are optional.
   - **Registration plate**, **Manufacturer** and **Model**.
   - **Display name**: a second name, for example `Blue van`.
   - **Engine**, **Vehicle type** and **Counter unit**.
     A tractor and a generator count engine hours by default.
   - **Counter reading**: the reading on the dashboard now.
3. Click **Add vehicle**.

The vehicle gets the country from your [settings](settings.md).
The country sets the units, the currency, the VAT rates, the inspection and the logbook rules.

### Display name

The display name shows under the plate, for all users who see the vehicle.
It has 64 characters at most.
Mails, notifications, exports and the logbook still use the plate.

A vehicle without a plate shows its display name.
A vehicle without a display name shows its manufacturer, model and body type under the plate.

## The vehicle page

From top to bottom:

1. The name, and the buttons **New entry**, **Costs**, **Edit vehicle** and **QR sticker**.
2. The figures of the selected period ([Costs](costs.md)).
3. The reminders ([Reminders](reminders.md)).
4. **Bookings**, only when another user has or had access ([Access and bookings](access.md)).
5. **Documents** ([Documents](documents.md)).
6. The **Timeline** ([Entries](entries.md)).

You see only the buttons that your [role](access.md#roles) allows.
The **Overview** page lists all your vehicles, the most urgent reminders first.

## Edit a vehicle

Click **Edit vehicle**, change the fields, and click **Save**.
Some fields need a word:

- **Energy types**: the energies that the vehicle takes.
  They decide which energy entries you can record.
- **Also counts engine hours**: a vehicle in kilometres can also count engine hours, for example a truck with a crane.
- **Currency**: three letters, for example `EUR`.
  After the first cost in a currency, you cannot change it.
- **Country**: **Germany** or **Generic**.
  **Generic** has no national rules.
- **Registration**: the EU codes ([Registration type](#registration-type)).

The overview can show **Some details are still missing** to the owner and the managers.
It names the empty fields: VIN, first registration, tank size, battery capacity or currency.
Click the vehicle name to add them, or click **Dismiss**.

### Registration type

Two optional EU codes from part I of the registration certificate:

- **EU vehicle category** is field J, for example `M1` for a passenger car.
  If field J ends in `G`, also switch on **Off-road vehicle (G)**.
- **EU body type** is two letters, for example `AC` for a station wagon.
  Germany prints it in field 4, France in field J.2.
  Some lorry and trailer bodies take a **Body add-on** of two digits, for example `03` in `BA03`.

Type a part of a name or a code to search the list.
The search ignores spaces, hyphens and dots.
The body type list opens only for a category that has body types.

A category can set the vehicle type, for example `M1` sets **Car**.
You can change the vehicle type after that.
The vehicle type, not the category, decides the mileage rate, the inspection and the counter unit.

#### Vehicle categories

The tractor, trailer and towed-equipment codes end in a speed letter: `a` for a maximum of 40 km/h, `b` for more than 40 km/h.

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

A vehicle approved under the older EU rules carries only the main code, for example `L3e`.
Choose the code that your certificate shows.

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

The last column names the categories that a body type fits.
The off-road variant of a category fits too: `AC` fits `M1` and `M1G`.

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

The regulation calls `BB` "Van".
NextFleet says **Panel van**, because **Van** is already a vehicle type.
Use `SG` for a body that no other code names.

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

Set **Lifecycle** in **Edit vehicle**:

| Value | What happens |
|---|---|
| **Active** | The vehicle is in use. |
| **Laid up** | Reminders pause. Nobody can book it. It moves to the end of the overview. Use it for a seasonal vehicle. |
| **Disposed of** | The vehicle is sold or scrapped. Type the date in **Disposed on**. Reminders stop. The vehicle moves to the **Disposed of** list at the bottom of the overview. Its records stay, and you can still print its logbook. |

To bring a vehicle back, open it from the **Disposed of** list, click **Edit vehicle**, and set **Lifecycle** to **Active** or **Laid up**.
Only the owner and the managers can do this.

## Delete a vehicle

Only the owner can delete a vehicle.
Click **Edit vehicle**, then **Delete vehicle**.
To cancel, click **Undo** in the message at the bottom of the page.

> [!WARNING]
> After the message closes, only your administrator can restore the vehicle.

The message closes when you click **Dismiss**, or when you delete, remove, import or change something else.

## Find a vehicle

Use the Nextcloud search.
Type a part of the plate, the display name, the manufacturer or the model.
The search ignores spaces and hyphens in the plate.
It does not find a vehicle that is disposed of.

## Print a QR sticker

A sticker in the glove box opens the entry form for this vehicle on a phone.

1. On the vehicle page, click **QR sticker**.
2. Click **Print**.
   The sticker is about 5 cm wide.

The code holds only the address of the vehicle.
The person who scans it must log in and must have access.

Next: [Entries and the timeline](entries.md)
