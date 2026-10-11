# Design

The pictures of NextFleet, one file per view, for people who change the code. Each section draws
one part of the app and links the section of [architecture.md](../architecture.md) (or
[ui.md](../ui.md), [api.md](../api.md), [security.md](../security.md)) that holds its rules and
their reasons. The words are in [CONTEXT.md](../../CONTEXT.md).

| File | What it shows |
|---|---|
| [context.md](context.md) | The use cases of each actor; where the app runs inside Nextcloud |
| [components.md](components.md) | The backend's components by layer, the frontend's, and the path a request takes through them |
| [domain.md](domain.md) | The classes of the domain by area, with their relations |
| [data-model.md](data-model.md) | Every `fleet_*` table with its columns, and how the rows point at each other |
| [flows.md](flows.md) | The key requests and jobs step by step, from the browser, client or timer to the database |
| [lifecycles.md](lifecycles.md) | The states of a reminder, a booking, a vehicle, a receipt in the inbox and a grant |

## Notation

UML, drawn in [Mermaid](https://mermaid.js.org/) inside the Markdown, which GitHub renders. Class,
sequence, state and ER diagrams are Mermaid's own. Mermaid has no use-case, component or deployment
diagram, so those are flowcharts in UML notation: actors as nodes outside the system's `subgraph`,
use cases as rounded nodes, stereotypes in guillemets, «include» and «extend» as dashed labelled
edges, components and nodes as `subgraph`s.

A name in a diagram is spelled as in the code, or as CONTEXT.md defines the term. A diagram shows
about 15 nodes at most; a larger area is split.

## Checking

`npm run docs:diagrams` renders every Mermaid block under `docs/` and fails on the first that does
not render. It needs Docker, and runs on your machine only, not in CI
([development](../development.md#testing)).

`composer test` holds some diagrams to the code. `DesignDiagramsTest` fails when a class in
[domain.md](domain.md) is no class, interface or enum under `lib/`, when the tables, columns
or types in [data-model.md](data-model.md) differ from the schema the migrations declare, and when
the states in [lifecycles.md](lifecycles.md) differ from the constants that hold them. Such a state
is written `state "value<br/>Class::NAME" as value`: Mermaid refuses `::` in a plain description.
