# Costs

NextFleet adds up the energy entries, maintenance records and expenses of a vehicle.
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

## The costs per month

1. On the vehicle page, click **Costs**.

   A bar shows each month, in **Energy**, **Maintenance** and **Expenses**.
   The table below has each expense category in its own row.
2. Click the arrows to change the year.

**CO₂ (estimate)** multiplies each energy by an emission factor.
For electricity, NextFleet uses the grid average of the country, or your own factor from your [settings](settings.md).

## Export a CSV file

1. On the **Costs** page, click **Export**.
2. Select **Trips**, **Fill-ups**, **Maintenance** or **Expenses**.

   You get the entries of the year on the page.

The file name starts with the plate, for example `B-XY_123-2026-trips.csv`.
Each user who sees the vehicle can export.

Next: [Reminders](reminders.md)
