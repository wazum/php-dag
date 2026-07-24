# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
