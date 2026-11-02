# A small firm may opt into approval and hidden costs

[ADR 0004](0004-freelancers-not-fleets.md) keeps NextFleet a freelancer's tool, not a fleet manager's.
Yet a firm with three cars and six drivers uses it as a pool, and one person there runs it.
They asked for two things a freelancer never needs: to keep the costs from the drivers, and to approve a driver's booking before the car is taken under it.

Both are a switch on one vehicle, set by its owner, off by default for approval and open by default for costs.
With both left alone, a vehicle behaves as before.
Each rides on what is already there: the costs on the roles of [ADR 0001](0001-own-access-table.md), approval on the booking's own row with its states unchanged.
So the API v1 only grows ([ADR 0009](0009-the-ocs-api-v1-is-the-public-contract.md)).

That is where it stops.
NextFleet still has no per-driver limit on the history, no separate right to enter costs, no GPS, no cost centres and no budgets.
A firm that needs those needs a fleet manager, and ADR 0004 still holds.
