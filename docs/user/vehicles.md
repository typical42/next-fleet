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
