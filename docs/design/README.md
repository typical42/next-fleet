# Design diagrams

The pictures of NextFleet, for people who change the code.
Each file draws one view and links the section of [architecture.md](../architecture.md) that holds the rules and their reasons.
Read them in this order: from the outside in, then from structure to behaviour.

| File | Shows |
|---|---|
| [context.md](context.md) | The use cases of each actor, and where the app runs |
| [components.md](components.md) | The backend by layer, the frontend, and the path of one request |
| [domain.md](domain.md) | The domain classes by area, with their relations |
| [data-model.md](data-model.md) | Every `fleet_*` table with its columns |
| [flows.md](flows.md) | The key requests and jobs, step by step |
| [lifecycles.md](lifecycles.md) | The states of a reminder, a booking, a vehicle, an inbox receipt and a grant |

## Notation

UML in [Mermaid](https://mermaid.js.org/), which GitHub renders.
Class, sequence, state and ER diagrams are Mermaid's own.
Mermaid has no use-case, component or deployment diagram, so those are flowcharts in UML notation: actors outside the system's `subgraph`, use cases as rounded nodes, stereotypes in guillemets, «include» and «extend» as dashed labelled edges.

A name in a diagram is spelled as in the code, or as [CONTEXT.md](../../CONTEXT.md) defines it.
A diagram shows about 15 nodes at most; a larger area is split.

## Checks

- `npm run docs:diagrams` renders every Mermaid block under `docs/` and fails on the first that does not render.
  It needs Docker and runs only on your machine ([development](../development.md#testing)).
- `DesignDiagramsTest` in `composer test` holds three files to the code.
  It fails when a class in [domain.md](domain.md) does not exist under `lib/`, when [data-model.md](data-model.md) differs from the schema the migrations declare, and when a state in [lifecycles.md](lifecycles.md) differs from its constant.
  Write such a state as `state "value<br/>Class::NAME" as value`: Mermaid refuses `::` in a plain description.

Next: [Context](context.md)
