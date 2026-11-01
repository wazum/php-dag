# How it works

php-dag runs the [Sugiyama algorithm](https://en.wikipedia.org/wiki/Layered_graph_drawing)
in three stages: a **graph** you build, a **layout** that gives every box and
edge a position, and a **renderer** that draws them onto a grid of characters.

## Stage 1: Graph

The public API. Build a graph with nodes and edges:

```
 Graph
   │
   ├── Node  ── id, title, body[], NodeStyle
   │                                  ├── BorderStyle (Rounded, Solid, Double, ...)
   │                                  ├── Badge
   │                                  └── ContentAlignment
   │
   ├── Edge  ── sourceId, targetId, EdgeStrokeStyle, weight, minLength,
   │            Label, AnsiColor
   │
   └── Group ── id, label, nodeIds[]
```

## Stage 2: Layout

Turns the graph into coordinates. Each processor changes one mutable
`LayoutGraph`, in this order:

```
 Graph
   │
   ▼
 LayoutGraph::fromGraph()         Wrap nodes and edges for the layout
   │
   ▼
 ┌──────────────────────────┐
 │   1. CycleBreaker        │    Reverse back edges so the layout
 │                          │    sees an acyclic graph
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   2. LayerAssigner       │    Put each node in a layer (rank).
 │      NetworkSimplex /    │    Standard and Quality keep edges short;
 │      LongestPath         │    Fast uses longest-path layering
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   3. DummyNodeInserter   │    Split edges that skip layers
 │                          │    into one-layer steps
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   4. Ordering            │    Order nodes in each layer to cut
 │      DepthFirst + Median │    crossings; keep group members together
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   5. Position + Groups   │    Assign coordinates, move non-members
 │      Brandes–Köpf        │    out of groups, reserve group borders
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   6. LayerCompactor      │    Remove extra space along
 │                          │    the flow
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   7. LabelReserver       │    Make room for edge labels
 │                          │    before routing
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   8. EdgeRouter          │    Compute the corner points of each
 │      ChainAwareRouting   │    edge: exit, bends, entry
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   9. DummyNodeRemover    │    Remove the helper nodes, keep the
 │                          │    routed points
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │  10. Special edges       │    Route reversed edges and self-loops
 │      Feedback + SelfLoop │    after the normal edges
 └────────────┬─────────────┘
              ▼
 LayoutGraph with coordinates and edge points
```

Left-to-right flow uses its own positioning, label and routing processors, but
the stages stay the same.

## Stage 3: Render

Draws the positioned nodes and edges onto a sparse grid of cells:

```
 LayoutGraph
   │
   ├──> EdgeRenderer          Draw edge paths with direction bits.
   │      │                   Bits OR together at junctions:
   │      ▼                     UP|DOWN     = │
   │    Canvas                  LEFT|RIGHT  = ─
   │      ▲                     UP|RIGHT    = └
   │      │
   ├──> GroupRenderer         Draw group borders and crossings
   ├──> LabelRenderer         Place edge labels
   └──> BoxRenderer           Draw borders, titles, body, badges
          │
          ▼
 PlainTextFormatter /         Turn canvas cells into a string.
 AnsiFormatter                Each cell keeps the drawing with the
   │                          highest z-index: edge ends on a box
   ▼                          border, then boxes, labels, groups, edges
 Text output
```

## Prior art

php-dag stands on established graph-drawing work:

- **[Graphviz `dot`](https://graphviz.org/)** (C): the layered pipeline and the
  algorithms reimplemented here: network-simplex ranking, the weighted-median
  crossing heuristic, and Brandes–Köpf coordinate assignment.
- **[dagre](https://github.com/dagrejs/dagre)** (JavaScript): a practical
  blueprint for network-simplex ranking outside a C codebase.
- **[ELK (Eclipse Layout Kernel)](https://eclipse.dev/elk/)** (Java): the
  processor-pipeline architecture, layout as a chain of small, swappable phases
  rather than one monolith.
- **[Ratatui](https://ratatui.rs/)** (Rust): the styled cell-buffer model
  behind `Canvas`, where each cell carries a glyph, direction bits, and style.
