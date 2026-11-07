# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- A group label longer than its members is no longer cut off: the frame grows to hold it and keeps its members centred, in both flow directions.
- In top-to-bottom flow, a group with no edge entering its top members keeps them centred instead of shifting them right.

## [2.0.0] - 2026-11-01

### Removed

Breaking changes in the layout and render classes. `Graph`, `AsciiDag`, `AsciiDagBuilder`, the DOT classes and the CLI keep their API.

- `LayoutGraph::roots()`, `leaves()`, `nodeCount()` and `layerCount()`: use `predecessors()`, `successors()`, `count($graph->nodeIds())` and `count($graph->layerIndex())`.
- `EdgeRenderer::renderRoute()` and the `sourceArrow`/`targetArrow` parameters of `EdgeRoute`.
- `EdgeGlyphs::crossingCharacter()`, `vertical()`, `horizontal()` and the `crossingCharacter` constructor parameter: use `junctionFor()`.
- The `median` parameter of `MedianOrdering` and the `maxExchanges` parameter of `NetworkSimplexLayering`.
- `VerticalCompactor` and `HorizontalCompactor`: use `LayerCompactor(FlowDirection, minimumSpacing)`.
- `LeftToRightParallelPortReserver`: use `ParallelPortReserver(FlowDirection::LeftToRight)`.
- `LayerTransitions::hasBendingEdgeLeftToRight()`: use `hasBendingEdge($graph, $layer, FlowDirection::LeftToRight)`.

### Changed

- The `Canvas` drawing methods (`putCharacter()`, `markEdgePassthrough()`, `horizontalLine()`, `verticalLine()`, `box()`, `text()`) require a z-index.
- `Pipeline::add()` returns the pipeline.
- Long and wide graphs render about twice as fast.
- The DOT parser uses about half the memory: a 1 MB file with 20 000 edges peaks at 62 MB.
- Each parallel edge has its own port, lane and label, in both flow directions. Boxes grow to fit the ports.
- A labelled fan-out branch between two siblings lifts the bar one row, so its label has its own row.

### Fixed

- Edge labels sit beside their own edge: on edges entering a group, on fan-out branches, and next to group borders in left-to-right flow.
- Labels do not overwrite arrowheads, group borders, other edges or wide characters such as `漢`.
- Labels on outer fan-out branches do not push sibling boxes apart.
- Boxes in one layer do not overlap when a feedback edge skips that layer.
- Feedback edges and self-loops do not run through boxes or merge with other edges.
- A single edge through a shared port runs straight when its boxes can line up.
- With ASCII glyphs, edges cross group borders as `+`.
- A badge wider than the box content widens the box.
- A node with two or more parallel edge pairs does not crash the layout.
- `Graph::isCyclic()` accepts numeric node ids.
- `Graph::highlightPath()` rejects a one-node path with a missing node.
- No `SplObjectStorage` deprecation notices on PHP 8.5.
- DOT input: quoted keywords such as `"node"` are node ids; graph attributes inside a subgraph do not change the whole graph; a backslash at a line end continues a quoted string; an empty node label falls back to the node id; `\n` in an edge label is a space; `digraph -> { }` and nested clusters fail with an error.
- `toDot()` keeps double edge strokes as `phpdag_stroke=double`, which `fromDot()` reads.
- CLI: without input in a terminal, `php-dag` shows its usage; a directory path fails with "is not a file".

### Documentation

- Guides under `guide/`. The test suite checks every sample drawing.
- `SECURITY.md` and `CONTRIBUTING.md`.

## [1.1.0] - 2026-07-25

### Changed

- Long labeled edges (spanning two or more layers) now keep their own lane instead of merging into a shared trunk, and their label renders in-line on the lane — every constraint in a dependency graph is traceable to its edge. Layouts with such edges get wider (label width + 2 columns per corridor) and one extra row per affected gap; unlabeled edges still share trunks.

## [1.0.3] - 2026-07-24

### Fixed

- Edge labels in top-to-bottom convergent layouts (fan-ins, diamonds — what a dependency `why` produces) no longer sit on top of an edge, get orphaned by a box, or overwrite one another. Each label is placed beside its own edge with a clear line beneath it, and converging channels fan out and reserve space so every label fits; long edges route around the reserved channels.

## [1.0.2] - 2026-07-24

### Fixed

- Group ids now round-trip through `toDot()`/`fromDot()`. Ordinary ids are no longer prefixed with `cluster_`, and groups whose names would otherwise collide under that prefix (e.g. `foo` and `cluster_foo`) stay distinct.

### Documentation

- Clarified that `toDot()` is a Graphviz visualization export, not a lossless serializer: nodes, edges, edge labels, stroke styles, and groups round-trip; colors, node styles, edge weights, `minLength`, badges, and label positions do not.

## [1.0.1] - 2026-07-24

### Fixed

- DOT export now emits groups as `subgraph cluster_*` blocks, so groups survive a `toDot()`/`fromDot()` round trip instead of being silently dropped.
- The DOT parser rejects tokens after the closing `}` (stray tokens or a second graph) instead of silently ignoring them.
- Group labels are measured in terminal display columns, so wide-glyph labels (CJK, emoji) no longer overrun the group border.
- `roots()` and `leaves()` return numeric node ids as strings, honouring their `list<string>` contract.

## [1.0.0] - 2026-07-24

Initial release.
