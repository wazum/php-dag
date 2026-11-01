# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Faster on long and wide graphs: a 2000-node chain renders in about half the time (0.10 s instead of 0.21 s), `render()` formats the drawing once instead of twice, and graphs with thousands of separate pieces no longer slow down layering.
- A fan-out whose labelled branch sits between two siblings lifts its bar one row, so the label gets its own row under the bar. Labelled fan-outs in the same gap only take separate rows when their bars and labels would collide.

### Documentation

- The README is short now; the reference moved into guides under `guide/`. Every sample with a drawing is run by the test suite, so the drawings shown are the real output.
- The Groups sample shows the whole graph, the guides say that spacing below the minimum (node 1, rank 2) is raised without an error and that text combining several code points into one character is measured wrongly, and `bench/run.php` backs the performance numbers.
- Added `SECURITY.md` and `CONTRIBUTING.md`.

### Removed

These are breaking changes; the next release is 2.0.0.

- `LayoutGraph::roots()`, `leaves()`, `nodeCount()` and `layerCount()`: use `predecessors()`, `successors()`, `count($graph->nodeIds())` and `count($graph->layerIndex())`. `Graph::roots()`, `leaves()` and `nodeCount()` stay.
- `EdgeRenderer::renderRoute()` and the `sourceArrow`/`targetArrow` parameters of `EdgeRoute`: `EdgeRenderer::render()` draws each edge's arrow from the layout.
- `EdgeGlyphs::crossingCharacter()`, `vertical()` and `horizontal()`, and the `crossingCharacter` constructor parameter: use `junctionFor()`.
- The `median` parameter of `MedianOrdering` and the `maxExchanges` parameter of `NetworkSimplexLayering`.
- `VerticalCompactor` and `HorizontalCompactor` are replaced by `LayerCompactor(FlowDirection, minimumSpacing)`; use `LayerCompactor::class` with `Pipeline::insertAfter()` and similar. `LayerTransitions::hasBendingEdgeLeftToRight()` is now `hasBendingEdge($graph, $layer, FlowDirection::LeftToRight)`.

### Fixed

- Left-to-right edge labels no longer overwrite group borders or other edges. A labelled edge that crosses a group border gets room for its label outside the border, and a label that meets an obstacle slides along its own edge before moving to the next row.
- The DOT parser needs about half the memory: a 1 MB file with 20 000 edges peaks at 62 MB instead of 118 MB, so it fits PHP's default 128 MB limit.
- DOT input: a symbol in the graph-name slot (`digraph -> { }`) is a syntax error; `rankdir` or other graph attributes set inside a subgraph no longer change the whole graph; a backslash at the end of a line continues a quoted string; nested clusters fail with "Nested clusters are not supported" instead of being flattened into siblings.
- `toDot()` keeps double edge strokes: they still export as `style=bold` for Graphviz, plus `phpdag_stroke=double`, which `fromDot()` reads back.
- A badge wider than a box's content (for example two wide characters) widens the box instead of overwriting its top-left corner.
- An edge label no longer starts on the right half of a wide character such as `漢`, which split the character.
- With ASCII glyphs, a group border no longer cuts the edges that pass through it; the crossing is drawn as `+`.
- CLI: run without input in a terminal, `php-dag` shows its usage instead of waiting for stdin; a directory path fails with "is not a file" instead of "Empty input".
- Labels on the outer branches of a fan-out no longer push sibling boxes far apart; room is only made beside the lane where a label is drawn, and parents move with their children.
- A long edge leaving a lifted fan-out now bends on the bar row instead of crossing the label row.
- A fan-out label beside a long-edge sibling stays next to its own edge.
- Parallel long edges (`a -> c` twice, spanning layers) keep separate lanes with their own ports and arrowheads instead of merging into one lane, in both flow directions.
- A single edge through a box's shared trunk port lines up with its other end when one of the boxes can move without putting a long-edge lane onto another box, so it no longer jogs one cell; a long edge entering a box with a trunk port uses that port.
- Labels on parallel edges sit on their own lane in both flow directions, so each label belongs to exactly one lane: they no longer overwrite an arrowhead, get cut off, or land beside an unrelated edge. Labelled parallel lanes are spaced so neighbouring labels keep two blank cells apart.
- Ports line up across a parallel group: each set of ports sits straight across from the box it leads to, and one end of the group moves sideways when that turns every lane into a straight line, so parallel edges no longer bend for a one-cell offset.
- Parallel edges attach inside both boxes in both flow directions: a box grows until every parallel edge has its own port (single edges share one trunk port), ports follow the order of the far ends, and bending parallel lanes nest without crossing. Previously lanes could leave past a box border, replace a corner, or disappear in left-to-right flow.
- When the outer labels of two neighbouring fan-outs would collide, the right fan-out moves over so both bars share one row and every label stays beside its own edge; a separate row is only used for bars that really interleave.
- Boxes in one layer no longer overlap when a feedback edge skips that layer (`r -> b; r -> x; b -> z; z -> r`); the positioner now aligns nodes only with neighbours in the adjacent layer.
- A feedback edge no longer runs through a neighbouring box: when a box blocks the usual side (east, or south in left-to-right flow), the edge takes the opposite side if that side is free.
- A self-loop on a node that also has a feedback edge no longer merges with it: the loop moves to the free side of the box (west in top-to-bottom flow, top in left-to-right flow).
- DOT input: a quoted keyword such as `"node"` or `"subgraph"` is a plain node id, so graphs with such ids survive a `toDot()` round trip.
- `Graph::highlightPath()` rejects a one-node path naming a missing node, like `colorPath()` does.
- No `SplObjectStorage` deprecation notices on PHP 8.5.
- DOT input: an empty node label (`label=""`, `label=<>`) falls back to the node id instead of throwing, an empty edge label means no label, and `\n` in an edge label becomes a space instead of gluing the words together.
- `Graph::isCyclic()` no longer throws a `TypeError` for numeric node ids such as `1 -> 2`.
- A node with two or more parallel edge pairs (`a -> b; a -> b; a -> c; a -> c`) no longer crashes the layout with a division by zero.

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
