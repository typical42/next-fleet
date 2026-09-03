# Mockups

Source for the UI mockups described in [../plan.md](../plan.md) §11.

| File | Screen |
|---|---|
| `Uebersicht.dc.html` | Overview, vehicles sorted by urgency (1440×900) |
| `Main.dc.html` | Vehicle detail with the unified timeline (1440×900) |
| `Tanken.dc.html` | Mobile fill-up sheet (390×844) |
| `canvas.json` | Layout of the three artboards on one canvas |

Each artboard is a standalone HTML file — open one in a browser to see that screen.
Together they are published as a canvas:
<https://claude.ai/code/artifact/854cd073-fba1-4cda-b2b3-62314da24f39>

Colours and metrics follow the Nextcloud light theme (`#00679e` primary, 300 px navigation,
pill-shaped navigation items, 15 px base type). They are an approximation of the design system, not
values read from a server checkout — verify against the real `@nextcloud/vue` components before
building.
