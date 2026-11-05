# Logbook for the tax office

In Germany, the tax office takes the private use of a company car from the 1 % rule or from a logbook (Fahrtenbuch).
It accepts an electronic logbook only if no entry changes without a record.
**Logbook mode** gives you that record.

> [!IMPORTANT]
> Nobody did a legal review of NextFleet.
> Ask your tax adviser before you use the logbook for your tax return.

## What Logbook mode does

- Each change to a trip goes into an audit trail, with the old and the new values.
- **Delete** becomes **Void trip**.
  A voided trip stays in the printed logbook.
- A trip without a necessary field shows **Incomplete**.
- The timeline shows the gaps between trips ([Close a gap](entries.md#close-a-gap)).
- The printed logbook lists each void and each late change.

The necessary fields depend on the category of the trip:

| Category | Necessary fields |
|---|---|
| **Business** | Plate, departure, counter at departure and arrival, destination, purpose, business partner |
| **Commute** | Plate, departure, counter at departure and arrival |
| **Private** | None. Only the distance counts. |

Logbook mode is off by default.
Only the country **Germany** has these rules.

The audit trail records only the changes made in NextFleet.
The administrator of your Nextcloud can change the database directly.
That change leaves no record, so a tax auditor can reject the logbook ([A German tax audit can reject the logbook](../legal.md#a-german-tax-audit-can-reject-the-logbook)).

## Turn it on or off

For a German vehicle, the **Overview** page asks the owner and the managers once: **Keep a logbook for the tax office with this vehicle?**
Click **Switch Logbook mode on**.

You can also use the **Logbook mode** switch in **Edit vehicle**, under **Vehicle**, then click **Save**.
To switch it off, NextFleet asks you to confirm.
The records of the audited period stay as they are.
A trip that started while the mode was on keeps its audit trail.

## Print the logbook

1. Click **Reports** in the navigation.
2. Select the **Vehicle** and the **Year**.
   Until the end of February, the year is last year.
3. Click **Open logbook**.
4. Print the page from your browser.

A German vehicle gets a German **Fahrtenbuch**.
Other vehicles get a plain logbook in your language.

## Print the mileage claim

The mileage claim lists the business trips that you recorded on one vehicle in one year, with the flat rate per kilometre.
It exists for German vehicles that count kilometres.
If the owner keeps the costs from you, you get no mileage claim ([Access](access.md#keep-the-costs-from-drivers-and-viewers)).

1. In **Reports**, select the **Vehicle** and the **Year**.
2. Click **Open mileage claim**.
3. Print the page.

Commutes are not on it.
The flat rate is only for vehicles that are not business assets.

Next: [Figures and statistics](statistics.md)
