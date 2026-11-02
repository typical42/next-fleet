# Access and bookings

The owner of a vehicle can give access to other users and groups.
With access, a driver can book the vehicle, take it and return it.

## Roles

| Role | Can |
|---|---|
| **Viewer** | See the vehicle, its entries, figures, reminders and documents. Export CSV files. See the costs, unless the owner keeps them from drivers and viewers. |
| **Driver** | All a viewer can. Add entries, and change or delete their own. Book the vehicle. Attach documents to their own entries and bookings. |
| **Manager** | All a driver can. Always see the costs. Change and delete all entries and bookings, and answer the ones that wait for approval. Edit the vehicle, its reminders and who gets them. Attach documents to the vehicle. Import. |
| Owner | All a manager can. Give and remove access. Decide whether drivers and viewers see the costs, whether bookings need approval, and who hears of bookings. Delete the vehicle. |

The owner is the user who added the vehicle.
Only an administrator can give it to another owner.
With more than one grant, the strongest role applies.
Every role sees the whole vehicle, every trip with its purpose included.
In [Statistics](statistics.md#statistics), only the owner and managers see the figures of each driver; drivers and viewers see their own.

## Keep the costs from drivers and viewers

You must be the owner.

1. Click **Edit vehicle**.
2. Switch off **Drivers and viewers see costs**.
3. Click **Save**.

Drivers and viewers then see no sums, no prices and no expenses of other users, and no mileage claim.
They still enter amounts, and see the amounts they entered.
On the timeline, **Expenses** shows the expenses they entered.
A receipt shows the price, so they see no document on another user's fill-up, maintenance or expense either.
They also see no receipt on the booking of another user and no receipt on the vehicle itself.
The other documents on the vehicle stay visible, for example the registration and the insurance.
To hide a purchase invoice or a leasing contract, file it as a receipt on the vehicle.
If it is already on the vehicle as a different kind, remove it first and attach it again.
The owner and managers always see the costs.

## Give access

You must be the owner.

1. Click **Edit vehicle** and go to **Access**.
2. In **Give access to**, select a user or a group.
3. In **Role**, select **Viewer**, **Driver** or **Manager**.
4. Click **Give access**.

   The change saves at once.
   The user gets a notification.

You can give access only to the users and groups that your administrator lets you share with.
To change a role, select another one.
To end access, click **Remove**, then **Remove access**.

## Leave a vehicle

On a vehicle of another user, click **Leave vehicle** under the name, then **Leave**.
Only the owner can give you access again.
Access through a group stays; only the owner can change it.

**Who can see this vehicle**, under the name, lists everyone with access.

## Book a vehicle

**Bookings** shows only on a vehicle that another user has or had access to.
Drivers, managers and the owner can book.

1. In **Bookings**, click **Book**.
2. Type the **Start**, the **End** and the **Purpose**.
3. Click **Book**.

If someone else has the vehicle at that time, NextFleet tells you who.
A booking starts now or later, lasts 90 days at most, and ends within one year.
Nobody books a vehicle that is laid up or disposed of.

To change or cancel your booking, click **Change** or **Cancel booking**.
A manager and the owner can do this for all bookings.
If someone else cancels your booking, you get a notification.

### Approve bookings

The owner can make a driver's booking wait for an answer: in **Edit vehicle**, switch on **Bookings need approval**.
Bookings by the owner and the managers are approved at once.

A booking that waits also holds its time: you see **Waiting for approval**, others see **Requested**.
The owner and the managers get a notification, and the booking shows **Answer**.

1. Click **Answer**.
2. Type a comment for the driver, if you like.
3. Click **Approve**, **Ask back** or **Decline**.

The driver gets a notification and sees the comment on the booking.
Only the driver, the owner and the managers see the comment.
**Decline** cancels the booking.
In the list, the booking then reads **Declined**.

**Ask back** keeps the booking waiting, for the driver to change or cancel it.
In the list, it reads **Asked back**.
The driver sees the question in the notification and in **Change booking**.
Under the vehicle's name, it reads **Your booking, asked back**.
When the driver saves a change, the question is answered and the booking waits for approval again.
On a booking that is asked back, a new purpose alone is also an answer.

- A waiting booking cannot be taken, even once its time has come.
  The owner and the managers can still answer it.
  If it is not approved by its end, it is declined.
- When the driver sets a new start or end, the booking waits again, also when it was approved.
  When the owner or a manager sets it, the booking is approved.
  A new purpose alone changes nothing on an approved booking.
- **Take it now** needs no approval if the car is back by midnight.
  Kept longer, it is a booking that waits like any other.
  The sheet then says so and the button reads **Request**.
  You type the counter when you take the car.
- Switching **Bookings need approval** off approves every booking that waits.
- When the owner makes a user or a group a manager, each new manager gets a notification for each request that waits.
  A new owner gets them too.
  A booking that is asked back waits for the driver, so it sends no notification to them.
  A user who joins a group that is a manager already gets them only for bookings made after that.
- The people on the booking notices list hear of a booking only once it is approved.

### Hear of bookings

The owner decides who gets a notification when a vehicle is booked or taken.

1. In **Edit vehicle**, add accounts or groups to **Booking notices go to**.
   With nobody on the list, the notices go to you, the owner.
   The list saves at once.
2. Under **What they hear of**, tick the events: **Booked ahead** and **Taken now** are ticked for a new vehicle.
   **Returned, with the counter and the level** tells of a return.
   **Cancelled** tells of a cancelled booking.
   **Moved to another start or end** tells of a change of the times.
3. Click **Save** to keep the events.

Only people who can see the vehicle get the notices, and nobody hears of what they did themselves.

## Take and return a vehicle

1. On your booking, click **Take the car**.
2. Type the **Counter reading** and the **Tank or battery (%)**.
   You can add **Notes**.
3. Click **Take the car**.
   The booking shows **Out**.

A manager and the owner can hand the car over on someone else's booking: the button reads **Hand over to** and the driver's name.

Without a booking, use **Take it now** and type when you are **Back by**.
It shows only while nobody has the vehicle.

To return it, click **Return the car** on the booking and type the reading and the level.
The entry form then opens a trip with the times, the readings and the purpose of the booking.
Select the **Category** and click **Save**.
If you close the form, click **Log the trip** on the booking later.

For photos of the handover, attach them to the booking ([Documents](documents.md)).

Next: [Documents and the receipt inbox](documents.md)
