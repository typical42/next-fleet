# Costs

NextFleet calculates the sum of the costs of the energy entries, maintenance records and expenses
of a vehicle. It also calculates the consumption, the cost per distance, the total cost of
ownership and a CO₂ estimate.

## Read the figures on the vehicle page

The top of the vehicle page shows tiles with figures. Select the time in the **Period** list:
**Last 12 months**, **This year**, **Last year** or **One month**.

| Tile | Meaning |
|---|---|
| **Odometer** | The newest counter reading. |
| Consumption | The consumption of each energy, from one full tank to the next. |
| **Cost** and **Energy cost** | The cost per distance. A vehicle in engine hours shows the cost in the period. |
| **Total cost of ownership** | The cost per distance plus the loss of value: the purchase price minus the residual value, divided by the total distance. It shows only if the vehicle has a **Purchase price** and a **Residual value**. |

Each tile compares the period with the period before it. A note under a tile tells you if a
figure is incomplete, for example if an energy entry has no price.

> [!NOTE]
> NextFleet calculates a sum only in one currency. If a vehicle has no currency, the tiles show
> **No currency**.

To set the currency, use **Edit vehicle**.

If you select **I reclaim VAT** in your [settings](settings.md), the figures are net of VAT. An
entry without a VAT rate counts as gross.

## Read the costs per month

1. On the vehicle page, click **Costs**.

   The page shows a bar for each month of the year, and the table **Costs by month**.
2. To show a different year, click the arrow on the left or on the right of the year.

The bars and the table divide the costs into **Energy**, **Maintenance** and **Expenses**. The
table shows each expense category in its own row.

The **CO₂ (estimate)** figure multiplies the energy that you filled up or charged by an emission
factor for that energy. For electricity, NextFleet uses the grid average of the country. You can
set your own grid factor in your [settings](settings.md).

## Export a CSV file

1. On the **Costs** page, click **Export**.
2. Select **Trips**, **Fill-ups**, **Maintenance** or **Expenses**.

   The browser downloads a CSV file with the entries of the year on the page.

Each user who can see the vehicle can export its CSV files.
