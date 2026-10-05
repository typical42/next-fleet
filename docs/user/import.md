# Import

You can import the history of a vehicle from a CSV file of another app. NextFleet reads these
formats:

| Format | Types of record |
|---|---|
| CSV (LubeLogger format) | fuel, service, repair, upgrade, tax, supplies, odometer |
| CSV (Spritmonitor format) | fuel, costs |

NextFleet does not import trips.

## Before you start

- Save the CSV file in your Files. The file must be your own, not shared with you.
- The file must be smaller than 5 MB and have not more than 20,000 rows.
- You must be the owner or a manager of the vehicle.
- The vehicle must not be disposed of.

## Import a file

1. On the vehicle page, click **Edit vehicle**.
2. Click **Import from a file…**.
3. Select the CSV file.
4. Click **Choose**.
5. In **Format**, select the format and the type of record.
6. If the form shows them, select the **Distance in the file** and the **Volume in the file**.
7. Click **Preview**.

   The preview shows the number of new rows, of rows already in NextFleet, and of rows it cannot
   read.
8. Answer the questions in the preview. For example, select the **Order of the dates**, or select
   what each cost category becomes.
9. Click **Import**.

   A message shows the number of imported entries.

NextFleet skips the rows that are already in NextFleet. If the preview finds such rows, it shows
the **Import the rows already there as well** switch. To import them also, turn it on before you
click **Import**.

## Undo an import

To remove all entries of an import, click **Undo** in the message after the import. The message
stays until you click **Dismiss**. It also closes when you delete, remove or import something, or
when you change an entry.

> [!NOTE]
> If you delete one entry of an import, the undo of the full import is not possible.

In that case, delete the other entries one by one.
