# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP library (`wazum/php-dag`) that renders directed graphs as ASCII/Unicode art using the Sugiyama algorithm. User docs live in `README.md` and `guide/*.md`; `guide/how-it-works.md` describes the architecture. Implementation follows strict TDD.

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
ddev exec ./vendor/bin/phpunit --filter methodName tests/Path/To/SomeTest.php

# Static analysis
ddev exec composer phpstan        # PHPStan at level max
ddev exec composer psalm          # Psalm

# Code style
ddev exec composer cs-fix         # Fix code style
ddev exec composer cs-check       # Check without fixing

# Full QA suite (cs-check + phpstan + psalm + tests)
ddev exec composer qa

# Mutation testing (CI requires 100% covered MSI and MSI)
ddev exec 'XDEBUG_MODE=coverage ./vendor/bin/infection --threads=max --initial-tests-php-options="-d zend_extension=xdebug.so"'

# Benchmark, and the README diagrams after editing diagrams/*.html
ddev exec php bench/run.php
ddev exec php diagrams/export.php
```

## Architecture

Three stages forming a pipeline: **Graph** (domain model) -> **Layout** (positioning) -> **Render** (output).

### Namespaces (`PhpDag\`)

- **`Graph\`** — Domain model. `Graph` is the aggregate root with a fluent builder API. `Node`, `Edge` and `Group` are entities; `NodeStyle`, `Label`, `Badge` are value objects. Enums: `LabelPosition`, `ContentAlignment`.
- **`Style\`** — Enums and glyph tables shared by graph and renderer: `BorderStyle`, `EdgeStrokeStyle`, `AnsiColor`, `BorderGlyphs`, `EdgeGlyphs`.
- **`Layout\`** — Sugiyama layout engine as a **processor pipeline** (inspired by ELK). `LayoutEngine` turns a `Graph` into a mutable `LayoutGraph`; each phase is a `Processor` in a `Pipeline`. Algorithm variants sit behind strategy interfaces (`LayerAssignment`, `CrossingMinimization`, `NodePositioning`, `EdgeRouting`).
- **`Render\`** — `Canvas` is a sparse 2D cell buffer (inspired by Ratatui). Each `Cell` carries a character, a 4-bit `Direction` mask, style and z-index. `ElementRenderer` implementations (`BoxRenderer`, `EdgeRenderer`, `GroupRenderer`, `LabelRenderer`) draw onto the canvas; `OutputFormatter` implementations (`PlainTextFormatter`, `AnsiFormatter`) turn it into a string.
- **`Dot\`** — `DotParser` (DOT in) and `DotExporter` (DOT out).
- **`AsciiDag`** / **`AsciiDagBuilder`** — top-level facade composing layout and renderer; `LayoutResult` holds one finished layout.

### Key Design Decisions

- **Processor pipeline**: the layout is a chain of independent, swappable processors, not one function. `AsciiDagBuilder::defaultPipeline()` lists the default order.
- **Direction bitmask for junctions**: 4-bit mask (UP=1, RIGHT=2, DOWN=4, LEFT=8) with a 16-entry glyph lookup. Bits OR together, so corners, T-junctions and crossings need no special cases.
- **Canvas z-index**: edge ends on a box border at 11+, boxes at 10, labels at 8, groups at 6, edges at 5. Removes draw-order dependencies between renderers.
- **Mutable LayoutGraph IR**: processors mutate it in place. The user-facing `Graph` stays clean; `LayoutGraph` is the internal scratchpad with dummy nodes, reversed edges and coordinates.

## Code Style

- PHP 8.2+ with `declare(strict_types=1)` in every file
- Symfony CS rules via php-cs-fixer (trailing commas everywhere, global imports)
- PHPStan at max level, Psalm enabled
- No abbreviated variable/method names (use `$vertex` not `$v`)
- Use modern PHP: `readonly`, `match`, enums, early returns

## Testing

- PHPUnit 11, test-first workflow; `#[Test]` attribute, no `test` method prefix
- `failOnRisky` and `failOnWarning` are enabled
- Test namespaces mirror source: `PhpDag\Tests\Graph\GraphTest` tests `PhpDag\Graph\Graph`
- Integration tests use golden-file snapshots in `tests/Fixtures/`
- `DocumentationTest` runs every PHP and DOT sample in `README.md` and `guide/*.md` that is followed by an output block, and compares the output
- Mutation testing via Infection; annotate a true equivalent mutant with `@infection-ignore-all` and the reason
