# Vehicle access is our own table, not Nextcloud's share manager

Access to a vehicle is a row in our own `fleet_access` table, checked in one place (`VehicleAccess::may`).

`OCP\Share\IManager` was the obvious choice.
It brings the share dialog users know, link shares, expiry and circles.
But our roles (manager, driver, viewer) do not fit its permission bits.
And the main user, a freelancer with a co-driver, needs none of that machinery.
