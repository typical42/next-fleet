# Vehicle access is our own table, not Nextcloud's share manager

Access to a vehicle is granted through our own `fleet_access` table, checked in one place
(`VehicleAccess::may`), from the first commit. `OCP\Share\IManager` was the obvious alternative — it
brings the share dialog users already know, link shares, expiry and circles — but our roles
(manager, driver, viewer) do not fit its permission bits, and for the primary user, a freelancer
with a co-driver, none of that machinery is wanted. This replaces the two-day spike the plan
scheduled before M1.
