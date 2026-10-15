# Import

You can bring the history of a vehicle from another app's CSV file:

| Format | Records |
|---|---|
| CSV (LubeLogger format) | fuel, service, repair, upgrade, tax, supplies, odometer |
| CSV (Spritmonitor format) | fuel, costs |

NextFleet does not import trips.

## Before you start

- Save the file in your own Files.
  A file shared with you does not work.
- The file is smaller than 5 MB and has 20,000 rows at most.
- You are the owner or a manager, and the vehicle is not disposed of.

## Import a file

1. Click **Edit vehicle**, then **Import from a file…**.
2. Select the file and click **Choose**.
3. In **Format**, select the format and the record type.
   If the form asks, select the units of the file.
4. Click **Preview**.

   The preview counts the new rows, the rows already there and the rows it cannot read.
   It asks only what the file does not say, such as the order of the dates.
5. Answer the questions and click **Import**.

NextFleet skips the rows already there.
To import them too, turn on **Import the rows already there as well**.

## Undo an import

Click **Undo** in the message after the import.
It removes all entries of that import, or none.

> [!NOTE]
> After you delete one entry of the import, the undo is gone.
> Delete the others one by one.

Next: [Settings](settings.md)
