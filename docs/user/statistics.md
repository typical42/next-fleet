# Figures and statistics

NextFleet adds up the trips, energy entries, maintenance records and expenses of a vehicle.
From them it shows the consumption, the cost per distance, the total cost of ownership and a CO₂ estimate.

## The figures on the vehicle page

Select the time in **Period**: **Last 12 months**, **This year**, **Last year** or **One month**.
Each tile compares the period with the one before it.

| Tile | Meaning |
|---|---|
| **Odometer** | The newest counter reading. |
| Consumption | Each energy, from one full tank to the next. |
| **Electric, at the charger** | Electricity counted at the charger, charging losses included. |
| **Cost** and **Energy cost** | Per 100 km, or per hour for a vehicle in engine hours. Without a distance in the period, they show the total. |
| Per engine hour | For a vehicle that counts kilometres and engine hours: consumption and costs per engine hour. |
| **Total cost of ownership** | The cost plus the loss of value. Only with a **Purchase price** and a **Residual value**. |

A note under a tile tells you when a figure is incomplete, for example when a fill-up has no price.
A consumption tile can also say what it needs, such as **Needs two full fill-ups**.

NextFleet adds up costs in one currency only.
Without a currency, the tiles show **No currency**.
Set it in **Edit vehicle**.

With **I reclaim VAT** on in your [settings](settings.md), the figures are net.
An entry without a VAT rate counts as gross.

## Statistics

1. Click **Statistics** in the navigation.
   If you see the costs, **Costs** on the vehicle page opens it on that vehicle.
2. In **Vehicle**, select **All vehicles** or one vehicle.
3. In **Range**, select **Calendar year**, **Last 12 calendar months**, **All time** or **Between two days**.

   For a calendar year, click the arrows to change the year.
   **Last 12 calendar months** is the current month and the eleven months before it.
   Between two days, type the **First day** and the **Last day**.
   The last day cannot be before the first day.

A range of up to 24 months shows each month, a longer one each year.
**All time** always shows each year, so you can compare the years.

| Section | Shows |
|---|---|
| **Costs in EUR**, or your currency | A bar for each month or year, in **Energy**, **Maintenance** and **Expenses**, and each one's share. The table has each expense category in its own row. |
| **Cost per 100 km** or **Cost per hour** | A line for the vehicle, or for all vehicles together. With all vehicles, a table compares them. A month or year with a distance and no costs shows 0. In the **Whole period** column, the cost per 100 km counts only the costs of the months or years with a distance. |
| **Distance by trip category**, or **Hours by trip category** | A bar for each month or year, in **Business**, **Private** and **Commute**. |
| **Distance by driver**, or **Hours by driver** | The **Trips**, the **Distance** and the **Business share** of each driver. |
| **Consumption** | For one vehicle, each stretch from one full tank to the next. For all vehicles, the average of each vehicle. |
| **CO₂ (estimate)** | For one vehicle: each energy times an emission factor. |

The table under each chart has all the figures.
NextFleet adds up nothing across currencies or units: each currency and each unit gets its own section.
If the range has no figures for you, the page says so.

- **Cost per 100 km** uses the distance on the counter.
  **Distance by trip category** adds up only the trips in the logbook.
  If not every trip is in the logbook, the two distances are different.
  The same is true for hours.
- Under the costs and the cost per distance, a note tells you when a figure is incomplete or net, as on the vehicle page.
- Costs in a month or year without a distance are not in the cost per distance.
  A note under the line tells you when this occurs.
- **Distance by driver** and **Hours by driver**: the owner and the managers of a vehicle see every driver.
  Everyone else sees only their own trips.
- If an owner keeps the costs of a vehicle from you, its costs are left out, and a note says so ([Access](access.md#keep-the-costs-from-drivers-and-viewers)).

For CO₂ of electricity, NextFleet uses the grid average of the country, or your own factor from your [settings](settings.md).

## Export a CSV file

1. In **Statistics**, select one vehicle and **Calendar year**.
2. Click **Export**, then **Trips**, **Fill-ups**, **Maintenance** or **Expenses**.

   You get the entries of the year on the page.

The file name starts with the plate, for example `B-XY_123-2026-trips.csv`.
Each user who sees the vehicle can export.

Next: [Reminders](reminders.md)
