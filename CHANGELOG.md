# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-07-24

### Fixed

- DOT export now emits groups as `subgraph cluster_*` blocks, so groups survive a `toDot()`/`fromDot()` round trip instead of being silently dropped.
- The DOT parser rejects tokens after the closing `}` (stray tokens or a second graph) instead of silently ignoring them.
- Group labels are measured in terminal display columns, so wide-glyph labels (CJK, emoji) no longer overrun the group border.
- `roots()` and `leaves()` return numeric node ids as strings, honouring their `list<string>` contract.

## [1.0.0] - 2026-07-24

Initial release.
