# NextFleet also serves a small fleet

ADR 0004 made NextFleet a freelancer's tool and kept the fleet manager out.
Yet small firms run it as a pool: one person keeps a few cars, and several drivers book them.
Each of their asks was weighed alone against ADR 0004, and that gave no rule for the next one.
So this record replaces ADR 0004 and draws the line anew.

**Who.** A small firm in which one person runs the fleet, up to about 10 vehicles.
The code sets no limit; above about 10 vehicles, NextFleet makes no promise.

**Freelancers first.** The logbook the tax office accepts, gap detection and the mileage claim stay what the app is for.
A vehicle that only its owner uses shows no fleet setting.
An owner without a shared vehicle sees no setting for all vehicles.

**What comes in.** Rules for bookings: who may take a car at once, booking for a driver, approval for all vehicles at once, and who hears of which booking.
Driving licence checks, a fleet table, and cost centres, partners and budgets.
Any other fleet feature needs a new ADR or a change to this one.

**What stays out.**

- GPS and any tracking.
- An owner who takes a car over a driver's booking.
- A separate right to enter costs.
- A per-driver limit on the history.
- A Talk integration, for now.
- A ranking or scoring of drivers: Statistics sum per person, they do not compare persons.
- Approval chains: one approver step, the owner or a manager.

**Employee data.**

1. NextFleet keeps only what a booking, the logbook or a legal duty needs.
2. No locations and no tracking; places stay free text.
3. A licence check keeps the date, who checked, the classes and the expiry, and no copy of the licence.
4. A booking made for a driver records who made it and for whom; erasure pseudonymises both ([ADR 0008](0008-erasing-a-driver-pseudonymises.md)).
5. The firm is the controller.
   NextFleet records and asks nobody ([data protection](../legal.md#data-protection)).
