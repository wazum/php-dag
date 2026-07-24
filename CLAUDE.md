# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP library (`wazum/php-dag`) that renders directed acyclic graphs as ASCII art using the Sugiyama algorithm. Greenfield project — architecture is defined in `PLAN.md`, implementation follows strict TDD.

## Strict TDD Workflow

Every change follows the red-green-refactor cycle, one test at a time:

1. **Write ONE failing test** — a single test method for the next small behavior increment
2. **Run it, see it fail for the right reason** — the failure message must prove the test is correct and testing what you intend (not a syntax error, missing import, or wrong setup)
3. **Implement the minimal solution** — write just enough production code to make that one test pass, nothing more
4. **Run it, see it pass** — confirm green
5. **Refactor/improve** — clean up both test and production code while keeping all tests green
6. **Repeat** — pick the next small behavior increment

Never skip ahead. Never write production code without a failing test driving it. Never write multiple tests at once before implementing.

## Commands

All commands run through DDEV (`ddev exec`). The project root inside the container is `/var/www/html`.

```bash
# Run all tests
ddev exec composer test

# Run a single test file
ddev exec ./vendor/bin/phpunit tests/Path/To/SomeTest.php --testdox

# Run a single test method
ddev exec ./vendor/bin/phpunit --filter testMethodName tests/Path/To/SomeTest.php

# Static analysis
ddev exec composer phpstan        # PHPStan at level max
ddev exec composer psalm          # Psalm

# Code style
ddev exec composer cs-fix         # Fix code style
ddev exec composer cs-check       # Check without fixing

# Full QA suite (cs-check + phpstan + psalm + tests)
ddev exec composer qa

# Mutation testing
ddev exec ./vendor/bin/infection
```

## Architecture

Three bounded contexts forming a pipeline: **Graph** (domain model) -> **Layout** (positioning) -> **Render** (output).

### Namespaces (`PhpDag\`)

- **`Graph\`** — Domain model. `Graph` is the aggregate root with fluent builder API. `Node` and `Edge` are entities. `Style`, `Label`, `BoxGlyphs` are value objects. Enums: `BoxStyle`, `EdgeStyle`, `ArrowShape`, `Direction`, `LabelPosition`.
- **`Layout\`** — Sugiyama layout engine using a **processor pipeline** (inspired by ELK). `LayoutEngine` facade takes a `Graph`, produces a `LayoutGraph` (mutable intermediate representation with coordinates). Each pipeline phase is a `Processor` implementation operating on `LayoutGraph`. Algorithm variants use strategy interfaces (`LayerAssignment`, `OrderingStrategy`, `RoutingStrategy`).
- **`Render\`** — `Canvas` is a 2D sparse cell buffer (inspired by Ratatui). Each `Cell` carries a character, 4-bit direction bitmask, style, and z-index. `ElementRenderer` implementations (BoxRenderer, EdgeRenderer, ArrowRenderer, LabelRenderer) draw onto the canvas. `OutputFormatter` implementations (PlainTextFormatter, AnsiFormatter) convert the canvas to a string. `GlyphSet` interface (UnicodeGlyphs, AsciiGlyphs) maps direction bitmasks to junction characters.
- **`AsciiDag`** — Top-level facade composing LayoutEngine + Renderer.

### Key Design Decisions

- **Processor pipeline**: Layout algorithm decomposed into ~10 independent, swappable processors (not a monolithic function). See `PLAN.md` section 3 for the default pipeline phases.
- **Direction bitmask for junctions**: 4-bit mask (UP=1, RIGHT=2, DOWN=4, LEFT=8) with 16-entry glyph lookup. Bits OR together — eliminates all special-case logic for corners, T-junctions, crossings.
- **Canvas z-index**: Boxes at z=10, labels at z=8, edges at z=5. Eliminates draw-order dependencies between renderers.
- **Mutable LayoutGraph IR**: Processors mutate in-place (no cloning per step). The user-facing `Graph` stays clean; `LayoutGraph` is the internal scratchpad with dummy nodes, reversed edges, and coordinates.

### Implementation Roadmap (from PLAN.md)

- **Phase 1 (MVP)**: Linear chains — Graph model, Canvas, BoxRenderer, LongestPathLayering, NodePositioner, EdgeRouter (vertical only), PlainTextFormatter, facade
- **Phase 2**: Branching & merging — CrossingMinimizer, DummyNodes, OrthogonalRouter
- **Phase 3**: Rich annotations — Edge styles, labels, AnsiFormatter, multi-line content
- **Phase 4**: Advanced — NetworkSimplex, CycleBreaker, groups, ChannelRouter, AsciiGlyphs
- **Phase 5**: Ecosystem — HtmlFormatter, parser, framework integrations

## Code Style

- PHP 8.2+ with `declare(strict_types=1)` in every file
- Symfony CS rules via php-cs-fixer (trailing commas everywhere, global imports)
- PHPStan at max level, Psalm enabled
- No abbreviated variable/method names (use `$vertex` not `$v`)
- Use modern PHP: `readonly`, `match`, enums, early returns
- Autoload: `PhpDag\` -> `src/`, `PhpDag\Tests\` -> `tests/`

## Testing

- PHPUnit 11, test-first workflow
- `failOnRisky` and `failOnWarning` are enabled
- Test namespaces mirror source: `PhpDag\Tests\Graph\GraphTest` tests `PhpDag\Graph\Graph`
- Integration tests use golden-file snapshots in `tests/Fixtures/`
- Mutation testing via Infection for quality verification
