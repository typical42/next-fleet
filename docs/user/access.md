# Access and bookings

The owner of a vehicle can give access to other users and to groups. Each user or group gets a
role. The owner, a driver or a manager can book the vehicle, take it and return it.

## Roles

| Role | The user can |
|---|---|
| **Viewer** | See the vehicle, its entries, figures, reminders and documents. Export CSV files. |
| **Driver** | Do what a viewer does. Add entries, and change or delete their own entries. Book the vehicle. Attach documents to their own entries and bookings. |
| **Manager** | Do what a driver does. Change and delete all entries. Change and cancel all bookings. Edit the vehicle, its reminders and the reminder recipients. Attach documents to the vehicle. Import CSV files. |
| Owner | Do what a manager does. Give and remove access. Delete the vehicle. |

Each vehicle has one owner: the user who added it. Only an administrator can give a vehicle to a
different owner.

If a user has access through more than one grant, the strongest role applies.

## Give access

You must be the owner of the vehicle.

1. On the vehicle page, click **Edit vehicle**.
2. Go to the **Access** section.
3. In **Give access to**, select a user or a group.
4. In **Role**, select **Viewer**, **Driver** or **Manager**.
5. Click **Give access**.

   The change is immediate. You do not need to click **Save**. The user gets a notification.

You can give access only to the users and groups that your administrator lets you share with.

To change a role, select a different role in the list.

To remove access:

1. Click **Remove**.
2. Click **Remove access**.

## Leave a vehicle

If you have access to the vehicle of another user, you can return the access.

1. On the vehicle page, under the vehicle name, click **Leave vehicle**.
2. Click **Leave**.

> [!WARNING]
> After you leave, only the owner can give you access again.

If you have access only through a group, you cannot leave. If you also have access through a
group, you keep that access after you leave. Only the owner can change the access of the group.

If you are not the owner, open **Who can see this vehicle** under the vehicle name to see who has
access.

## Book a vehicle

The **Bookings** section shows only on a vehicle that another user has or had access to. You must
be a driver, a manager or the owner.

1. In the **Bookings** section, click **Book**.
2. Type the **Start**, the **End** and the **Purpose**.
3. Click **Book**.

If the vehicle is booked or out at that time, NextFleet tells you who has it.

A booking has these limits:

- It starts now or later, and it ends in the future.
- It lasts 90 days at most.
- It ends not more than one year from now.
- Nobody can book a vehicle that is laid up or disposed of.

To change your booking:

1. Click **Change** on the booking.
2. Change the times or the purpose.
3. Click **Save**.

To cancel your booking:

1. Click **Cancel booking**.
2. Click **Yes, cancel it**.

A manager and the owner can change and cancel all bookings. If another user cancels your booking,
you get a notification.

## Take and return a vehicle

1. On your booking, click **Take the car**.
2. Type the **Counter reading (km)** and the **Tank or battery (%)**. For a vehicle in engine
   hours, the label shows **(h)**. You can add **Notes**.
3. Click **Take the car**.

   The booking shows **Out**.

To take a vehicle without a booking, use **Take it now**. It shows only if nobody has the vehicle
or a booking for it now:

1. In the **Bookings** section, click **Take it now**.
2. Type the return time in **Back by**.
3. Type the **Counter reading (km)** and the **Tank or battery (%)**.
4. Click **Take the car**.

To return the vehicle:

1. On the booking, click **Return the car**.
2. Type the **Counter reading (km)** and the **Tank or battery (%)**.
3. Click **Return the car**.

   The entry form opens with a trip. NextFleet writes the times, the counter readings and the
   purpose from the booking.
4. Select the **Category**.
5. Click **Save**.

If you close the form and do not save the trip, click **Log the trip** on the booking later.

To add photos of the vehicle at the handover, attach them to the booking. Refer to
[Documents](documents.md).
