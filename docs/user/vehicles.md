# Vehicles

A vehicle is a car, motorcycle, van, truck, trailer, tractor or generator. All entries, reminders,
documents and bookings belong to one vehicle.

## Add a vehicle

1. In the navigation, click **New vehicle**.
2. Type the data that you know:
   - **Registration plate**, **Manufacturer** and **Model**.
   - **Engine**: **Petrol**, **Diesel**, **LPG**, **CNG**, **Electric** or **Hybrid**.
   - **Vehicle type**.
   - **Counter unit**: **Kilometres** or **Engine hours**. A tractor and a generator use engine
     hours by default.
   - **Counter reading**: the current value. It becomes the first counter reading.
3. Click **Add vehicle**.

   NextFleet opens the vehicle page.

A new vehicle gets the country from your [personal settings](settings.md). The country sets the
units, the currency, the VAT rates, the inspection and the logbook rules.

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

If the overview shows **Some details are still missing**, the vehicle has no VIN, first
registration, tank size, battery capacity or currency. Click the vehicle name and add the data. To
hide the message, click **Dismiss**.

## Lay up or dispose of a vehicle

The **Lifecycle** field in **Edit vehicle** has three values:

| Value | Result |
|---|---|
| **Active** | The vehicle is in use. |
| **Laid up** | The reminders pause. Nobody can book the vehicle. The vehicle moves to the end of the overview. Use this value for a seasonal vehicle. |
| **Disposed of** | The vehicle is sold or scrapped. Type the date in **Disposed on**. The reminders stop. The vehicle leaves the navigation and the overview. Its records stay, and you can still print its logbook in **Reports**. |

## Delete a vehicle

Only the owner can delete a vehicle.

1. Click **Edit vehicle**.
2. Click **Delete vehicle**.

   A message shows at the bottom of the page.
3. To cancel the deletion, click **Undo** in the message.

> [!WARNING]
> When the message closes, you cannot restore the vehicle yourself. Then only your administrator
> can restore it.

## Find a vehicle

Use the search of Nextcloud. Type a part of the plate, the manufacturer or the model. The search
ignores spaces and hyphens in the plate. It does not find a vehicle that is disposed of.

## Print a QR sticker

A QR sticker in the glove box opens the entry form for the vehicle on a phone.

1. On the vehicle page, click **QR sticker**.
2. Click **Print**.

   The sticker is approximately 5 cm wide.

The code contains only the address of the vehicle page. A person who scans it must log in to
Nextcloud and must have access to the vehicle.
