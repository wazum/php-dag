# Contributing

Bug reports and pull requests are welcome. For a bug, a DOT file or a short PHP
script that shows the wrong drawing helps most, together with the drawing you
expected.

## Setup

```bash
git clone https://github.com/wazum/php-dag.git
cd php-dag
composer install
```

## Checks

A pull request must pass the same checks as CI:

```bash
composer qa          # code style, PHPStan (max level), Psalm, PHPUnit
composer validate --strict
XDEBUG_MODE=coverage vendor/bin/infection --threads=max
```

Infection must report 100 % MSI and 100 % covered MSI. Kill a surviving mutant
with a test. When a mutant cannot change the behaviour, mark it with
`@infection-ignore-all` and say why in the same comment.

`composer cs-fix` fixes the code style.

## How changes are made

- Test first: write one failing test, make it pass, then clean up.
- A change to a drawing gets a golden file in `tests/Fixtures/`, added through
  `tests/GoldenFileTest.php`. Check the drawing by eye before you commit it.
- Every PHP or DOT sample in `README.md` and `guide/*.md` that is followed by
  an output block is run by `tests/DocumentationTest.php`. Update the output
  block when a drawing changes.
- Note user-visible changes under `[Unreleased]` in `CHANGELOG.md`.
- After editing `diagrams/*.html`, run `php diagrams/export.php` to update the
  SVG files the README shows.
