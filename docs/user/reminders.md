# Reminders

A reminder tells you when a service, an inspection or another task is due. It can be due on a
date, at a counter reading, or at the first of the two.

You must be the owner or a manager of the vehicle to add, change, snooze or skip a reminder.

## Add a reminder

1. On the vehicle page, under the figures, click **+ Reminder**.
2. In the **Reminder** list, select a template, for example **Oil change** or
   **Technical inspection (HU/AU)**. To type your own title, select **Own title**.
3. In **Due by**, select **Date**, **Counter** or **Date or counter, whichever comes first**.
4. Type the due date or the counter reading.
5. To repeat the reminder, type the interval in **Repeat every (months)** or
   **Repeat every (km)**.
6. Click **Add reminder**.

For a German vehicle without an HU/AU reminder, the vehicle page asks **When is the next HU/AU?**.
Select the month and year from the sticker on the plate. Then click **Add HU/AU reminder**.

## When a reminder warns you

A reminder has these states:

| State | Meaning |
|---|---|
| **Planned** | The due date or counter reading is not near. |
| **Coming up** | The warning time started. For a date, the default is one month before. |
| **Due** | The due date or counter reading is reached. |
| **Overdue** | The due date is past. A counter reminder stays **Due**. |
| **Snoozed** | You moved the warning to a later date. |
| **Done** | A maintenance record closed the reminder. |
| **Skipped** | You skipped this occurrence. |

For a counter reminder, NextFleet also shows **Expected around** with a date that it calculates
from your counter readings.

A reminder on a vehicle that is laid up pauses. A reminder on a vehicle that is disposed of stops.

## Mark a reminder as done

Drivers can also do this.

1. In the reminders, click **Done** on the reminder.

   The entry form opens with a maintenance record for this reminder.
2. Complete the record.
3. Click **Save**.

   If the reminder repeats, the next occurrence starts from the date and counter reading of the
   record.

## Snooze or skip a reminder

1. Click the reminder.
2. Click **Snooze 1 week** or **Snooze 1 month**.

To snooze until a date, type the date in **Snooze until**. Then click **Snooze until then**.

To skip this occurrence only, click **Skip this time**. The next occurrence of a repeated reminder
stays. To remove the reminder and all its occurrences, click **Delete reminder**.

## How you get the reminders

NextFleet tells you in three ways:

- **Notifications.** A Nextcloud notification shows at each warning that you selected, for
  example **Warn a month before**. It also shows when a counter reminder reaches its warning or
  due value, and when a reminder becomes overdue. The Nextcloud mobile app shows it on your phone.
- **Reminder mail.** A mail with the title `Reminders for your vehicles` lists the news of all your
  vehicles. The **Reminder mail** setting of each vehicle sets how often. The default is
  **Weekly, on Monday**. You get one mail per day at most.
- **Dashboard.** Add the **Vehicle reminders** widget to your Nextcloud dashboard. It shows the
  reminders that are coming up, due or overdue.

## Select who gets the reminders

1. Click **Edit vehicle**.
2. In **Reminders go to**, add or remove users. The owner is on the list by default.
3. In **Reminder mail**, select **No mail**, **Daily**, **Weekly, on Monday** or
   **Monthly, on the 1st**.

A user on this list gets the reminders, but does not get access to the vehicle. You can add a user
that your administrator lets you share with, or a user who already has access to the vehicle.
