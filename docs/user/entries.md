# Entries and the timeline

An entry records one event on a vehicle.
There are five types:

| Type | Use it for |
|---|---|
| **Trip** | One trip from a start to an end, with its purpose. |
| **Energy** | A fill-up of fuel or a charge of the battery. |
| **Maintenance** | A service, repair, inspection, tyre change or upgrade. |
| **Odometer** | A counter reading and nothing else. |
| **Expense** | Insurance, vehicle tax, toll, parking, a fine, a lease or another cost. |

## Add an entry

1. On the vehicle page, click **New entry**, or push `N`.
2. In **Entry type**, click the type.
3. Fill in the fields.
   Each type needs only one or two.
4. Click **Save**.

If the connection fails, your values stay in the form.
Click **Try again** when you are online.

### The fields

- **Trip**: **Departure**, **Arrival** and **Category** (**Business**, **Private** or **Commute**).
  Then the counter at departure and arrival, or only the **Distance**.
  **Purpose**, **Starting point**, **Destination** and **Business partner** suggest what you typed before.
  If NextFleet knows where the last trip ended, click **Use it** to copy that reading.
- **Energy**: **Date** and **Amount**.
  Add the **Total price** and the **Counter reading**: NextFleet needs the reading for the consumption.
  Keep **Full tank** on when you fill up completely.
  Turn on **Missed the previous one** if you forgot to record the fill-up before.
- **Maintenance**: **Title** and **Date**.
  **Closes reminder** selects the most urgent open reminder of the same type.
  Click a reminder to select or clear it ([Reminders](reminders.md#mark-a-reminder-as-done)).
- **Expense**: **Amount** and **Date**.
  A category without VAT, such as insurance in Germany, sets the VAT rate to 0.
- **Odometer**: the reading on the dashboard.

A vehicle with **Also counts engine hours** shows a second counter on energy, maintenance and odometer entries.
Trips always use kilometres.

## When a reading looks wrong

NextFleet never refuses a counter reading.
A reading from the dashboard wins over a reading that NextFleet calculated from a distance.

If a reading is lower than the one before, the timeline marks the entry **In question** and asks: **Was the counter replaced, or is this a typo?**

- Click **Counter replaced** if the counter was replaced or reset.
  NextFleet starts a new counter from here and adds the distances before and after.
- Click **Typo** to open the entry and correct the number.

The person who recorded the entry, the owner and the managers see the question.

## Close a gap

Only with Logbook mode on ([Logbook for the tax office](logbook.md)).

A gap is a distance that no trip records.
NextFleet finds one when a trip starts at a higher reading than the trip before it ended.
The timeline shows it, for example **12 km unaccounted for before this trip**.

1. Click **Close gap**.
2. Click **Record private trip**.

   NextFleet records the distance as one private trip, marked **Reconciled**.

## Read the timeline

The timeline shows all entries, newest first, by month.
To show one type, click it in **Show**.

A full tank shows the consumption since the full tank before it, for example **6.0 l/100 km**.
A vehicle with engine hours also shows **l/h** when both full tanks have engine hours.

A row can show these words:

| Word | Meaning |
|---|---|
| **In question** | The reading is lower than the one before. |
| **Incomplete** | Logbook mode is on, and a field that the logbook needs is empty. **Still missing** names it. |
| **Partial** | **Full tank** is off. The consumption shows at the next full tank. |
| **Previous fill-up not recorded** | **Missed the previous one** is on. |
| **No price** | The energy entry has no price, so the costs are incomplete. |
| **Not an energy this vehicle takes** | The energy is not in the **Energy types** of the vehicle. |
| **More than the vehicle holds** | The amount is more than the tank or the battery holds. |
| **Overlaps another trip** | Two trips share a time. Open one and correct **Departure** or **Arrival**. |
| **Reconciled** | NextFleet recorded this private trip to close a gap. |
| **Reconciliation overtaken** | A trip recorded later covers the same distance. Click **Void trip** to remove the private trip. |
| **Entered by** | Who recorded the entry. Only on a vehicle that another user has or had access to. |

## Change or delete an entry

Click the entry in the timeline, change it, and click **Save**.
To delete it, click **Delete**.
Both show a message with **Undo**.
With Logbook mode on, a trip has **Void trip** instead of **Delete**.

A driver changes only their own entries.
The owner and the managers change all entries.

> [!NOTE]
> If another user changed the entry while you had it open, NextFleet tells you.
> Your values stay.
> **Save anyway** writes your values over the other change.

Next: [Logbook for the tax office](logbook.md)
