# A partner is a record; the trip keeps its text

Until 1.0.0, a trip's partner was free text.
Nothing could list the trips to one partner, so nobody could bill on them.
Now a partner is a record in its owner's list, and a trip links to it.

**The trip keeps the text.**
The partner is a field of the German logbook, and a tax office reads it as it was entered.
So the trip stores the text as entered, and the link next to it.
A rename, a merge or a delete changes the record and the link, never the text.
API v1 keeps `partner` as that text ([ADR 0009](0009-the-ocs-api-v1-is-the-public-contract.md)).

**One list per owner.**
A firm visits the same partner in each of its cars, so the list belongs to the owner, not to a vehicle.
A list for the whole server would show one freelancer's partners to another.
A name that matches no record creates one, so a driver logs a trip as before.
Only the owner changes, merges or deletes a record: a manager's rights end at their vehicle.
Only the owner sees the address and the note.
A transfer copies the records that a vehicle uses into the new owner's list.

**A delete keeps the text.**
A partner can be a private person.
A delete removes the record, its address and the links.
The trip text stays, because the logbook must be kept for ten years ([§ 147 AO](https://www.gesetze-im-internet.de/ao_1977/__147.html)).

**A link is not a logbook field.**
A change of a link is audited like any change to a trip.
The logbook shows a late change only for the fields it prints.
So a merge over locked trips is not a late change to the logbook.
A trip's cost centre is a link too, and follows the same rule.

**Existing texts become records.**
Each owner gets one record per distinct text, compared after trimming and Unicode normalisation.
A variant spelling stays its own record until the owner merges it.
