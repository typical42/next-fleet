# Logbook for the tax office

In Germany, the tax office calculates the private use of a company car with the 1 % rule or with a
logbook (Fahrtenbuch). The tax office accepts an electronic logbook only if nobody can change an
entry without a record. NextFleet has **Logbook mode** for this purpose. Ask your tax adviser before
you use the logbook for your tax return.

> [!IMPORTANT]
> Nobody did a legal review of NextFleet.

## What Logbook mode does

- NextFleet writes each change to a trip into an audit trail, with the old and the new values.
- **Delete** becomes **Void trip**. A voided trip stays in the printed logbook with the date of
  the void.
- A trip without all the necessary fields shows **Incomplete**. **Still missing** names the
  fields.
- The timeline shows the gaps between trips. Refer to [Close a gap](entries.md#close-a-gap).
- The printed logbook lists each void and each late change.

For a German vehicle, the necessary fields depend on the category of the trip:

| Category | Necessary fields |
|---|---|
| **Business** | Registration plate, departure, odometer at departure and arrival, destination, purpose, business partner |
| **Commute** | Registration plate, departure, odometer at departure and arrival |
| **Private** | No field. The logbook counts only the distance. |

Logbook mode is off by default. You can turn it on for each vehicle. Only the country **Germany**
has rules for the necessary fields.

## Turn Logbook mode on

For a German vehicle with Logbook mode off, the **Overview** page asks the owner and the managers a
question: **Keep a logbook for the tax office with this vehicle?** Click **Switch Logbook mode on**.

You can also turn it on with **Edit vehicle**:

1. On the vehicle page, click **Edit vehicle**.
2. Click the **Logbook mode** switch.
3. Click **Save**.

## Turn Logbook mode off

1. Click **Edit vehicle**.
2. Click the **Logbook mode** switch.

   NextFleet asks you to confirm. The audited period ends. The records of that period stay as
   they are.
3. Click **Switch it off**.
4. Click **Save**.

A trip that started while Logbook mode was on keeps its audit trail. NextFleet also records each
later change to that trip.

## Print the logbook

1. In the navigation, click **Reports**.
2. Select the **Vehicle**.
3. Type the **Year**. Until the end of February, the field shows the previous year.
4. Click **Open logbook**.

   The logbook opens in a new browser tab.
5. Print the page with your browser.

For a German vehicle, the logbook is in German and has the title **Fahrtenbuch**. For other
vehicles, it is in your language.

## Print the mileage claim

The mileage claim lists the business trips that you recorded on one vehicle in one year. It gives
the flat rate per kilometre of the country. It is available for vehicles in kilometres with the
country **Germany**.

1. In **Reports**, select the **Vehicle**.
2. Type the **Year**.
3. Click **Open mileage claim**.
4. Print the page with your browser.

Commutes are not on the mileage claim. The flat rate applies only to vehicles that are not business
assets.
