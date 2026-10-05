# Entries and the timeline

An entry records one event on a vehicle. There are five types of entry:

| Type | Use it for |
|---|---|
| **Trip** | A journey, with its start, end and purpose. |
| **Energy** | A fill-up of fuel or a charge of the battery. |
| **Maintenance** | A service, repair, inspection, tyre change or upgrade. |
| **Odometer** | A counter reading only, without other data. |
| **Expense** | Insurance, vehicle tax, toll, parking, a fine, a lease or other costs. |

## Add an entry

1. On the vehicle page, click **New entry**. You can also push the `N` key.
2. In **Entry type**, click the type.
3. Complete the fields. The sections below describe them.
4. Click **Save**.

   The entry shows in the **Timeline**.

### Trip

- **Departure** and **Arrival**: the date and time. Both are necessary.
- **Counter or distance**: select **Counter** to type the counter readings at departure and
  arrival. Select **Distance** to type only the distance. Then NextFleet calculates the counter
  reading.
- **Category**: **Business**, **Private** or **Commute**. It is necessary.
- **Purpose**, **Starting point**, **Destination** and **Business partner**. The fields suggest
  values that you typed before.

If NextFleet knows where the last trip ended, the form shows that counter reading. To copy it to
**Odometer at departure**, click **Use it**.

### Energy

- **Energy**: the energies come from **Energy types** of the vehicle.
- **Date**, **Amount (l)** or **Amount (kWh)**, and **Total price**.
- **Counter reading**: NextFleet needs it to calculate the consumption.
- **Full tank** or **Charged to full**: keep this switch on when you fill the tank or charge the
  battery completely. NextFleet calculates the consumption from one full tank to the next.
- **Missed the previous one**: select it if you did not record the energy entry before this one.
- For electric charges: **Where** (**Home** or **Public**) and **DC fast charging**.

### Maintenance

- **Title**: necessary, for example `Oil change`.
- **Type**: **Service**, **Repair**, **Inspection**, **Tyres** or **Upgrade**.
- **Date**, **Cost**, **Vendor**, **VAT rate (%)**, **Counter reading** and **Notes**.
- **Closes reminder**: click a reminder to mark it as done with this record.

### Expense

- **Amount**, **Category**, **Date**, **VAT rate (%)** and **Notes**.

### Odometer

- **Counter reading**: the value on the dashboard. NextFleet never refuses a counter reading. If
  a value looks wrong, NextFleet flags it.

## How NextFleet calculates the counter

NextFleet keeps each counter reading in a history. It does not calculate a running sum. A counter
reading that you read from the dashboard has priority over a value that NextFleet calculated from
a distance.

If a counter reading is lower than the reading before it, the timeline marks the entry
**In question**. It asks: **Was the counter replaced, or is this a typo?**

- If the counter was replaced or reset, click **Counter replaced**. NextFleet starts a new counter
  from this reading. It adds the distances before and after the replacement.
- If you typed an incorrect number, click **Typo**. The entry opens, and you can correct it.

## Close a gap

This function is available when Logbook mode is on. Refer to
[Logbook for the tax office](logbook.md).

A gap is a distance that no trip records. NextFleet finds it when the counter at the start of a
trip is higher than at the end of the trip before. The timeline shows the gap, for example
**12 km unaccounted for before this trip**.

1. Click **Close gap**.
2. Click **Record private trip**.

   NextFleet records the missing distance as one private trip. The timeline marks it as
   **Reconciled**.

## Read the timeline

The timeline shows all entries of the vehicle, newest first, in groups by month. To show only one
type, click it in **Show**.

A row can show these words:

| Word | Meaning |
|---|---|
| **In question** | The counter reading is lower than the reading before it. |
| **Incomplete** | Logbook mode is on, and a field that the logbook needs is empty. **Still missing** names the fields. |
| **Partial** | **Full tank** or **Charged to full** is off. The consumption shows at the next full tank. |
| **No price** | The energy entry has no price. The costs are incomplete. |
| **Overlaps another trip** | The trip has a time that another trip also has. |
| **Entered by** | The user who recorded the entry. It shows only on a vehicle that another user has or had access to. |

## Change or delete an entry

1. In the timeline, click the entry.
2. Change the fields.
3. Click **Save**.

   A message with an **Undo** button shows at the bottom of the page.

To delete the entry, click **Delete** in step 2. If Logbook mode is on, the button for a trip is
**Void trip**. Refer to [Logbook for the tax office](logbook.md).

A driver can change and delete only their own entries. A manager and the owner can change and
delete all entries.

> [!NOTE]
> If another user changed the entry while you had it open, NextFleet tells you. Your values stay in
> the form. **Save anyway** writes your values over the other change.
