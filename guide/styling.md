# Styling boxes and edges

## Border styles

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Graph, Node, NodeStyle};
use PhpDag\Style\BorderStyle;

$graph = new Graph();
$graph->addNode(new Node('a', 'Rounded', style: new NodeStyle(borderStyle: BorderStyle::Rounded)))
      ->addNode(new Node('b', 'Solid',   style: new NodeStyle(borderStyle: BorderStyle::Solid)))
      ->addNode(new Node('c', 'Double',  style: new NodeStyle(borderStyle: BorderStyle::Double)))
      ->addNode(new Node('d', 'Dashed',  style: new NodeStyle(borderStyle: BorderStyle::Dashed)))
      ->addNode(new Node('e', 'Dotted',  style: new NodeStyle(borderStyle: BorderStyle::Dotted)));

echo AsciiDag::default()->render($graph);
```

```
╭─────────╮  ┌───────┐  ╔════════╗  ┌╌╌╌╌╌╌╌╌┐  ┌┈┈┈┈┈┈┈┈┐
│ Rounded │  │ Solid │  ║ Double ║  ╎ Dashed ╎  ┊ Dotted ┊
╰─────────╯  └───────┘  ╚════════╝  └╌╌╌╌╌╌╌╌┘  └┈┈┈┈┈┈┈┈┘
```

## Multi-line content and badges

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Badge, Edge, Graph, Node, NodeStyle};
use PhpDag\Style\BorderStyle;

$graph = new Graph();
$graph->addNode(new Node('db', 'Database', ['PostgreSQL', 'Port 5432'],
    style: new NodeStyle(borderStyle: BorderStyle::Double, titleBodySeparator: true),
));
$graph->addNode(new Node('api', 'API Server', ['PHP 8.2'],
    style: new NodeStyle(badge: new Badge('v2')),
));
$graph->addEdge(new Edge('db', 'api'));

echo AsciiDag::default()->render($graph);
```

```
╔════════════╗
║  Database  ║
║            ║
║ PostgreSQL ║
║ Port 5432  ║
╚══════┬═════╝
       │
       ▼
╭──────┴───v2╮
│ API Server │
│ PHP 8.2    │
╰────────────╯
```

## Edge stroke styles

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Node};
use PhpDag\Style\EdgeStrokeStyle;

$graph = new Graph();
foreach (EdgeStrokeStyle::cases() as $style) {
    $graph->addNode(new Node($style->name, $style->name))
          ->addNode(new Node($style->name.'-end', 'end'))
          ->addEdge(new Edge($style->name, $style->name.'-end', $style));
}

echo AsciiDag::default()->render($graph);
```

```
╭───────╮  ╭───────╮  ╭────────╮  ╭────────╮  ╭────────╮
│ Solid │  │ Heavy │  │ Dashed │  │ Dotted │  │ Double │
╰───┬───╯  ╰───┳───╯  ╰────┬───╯  ╰────┬───╯  ╰────╦───╯
    │          ┃           ╎           ┊           ║
    ▼          ▼           ▼           ▼           ▼
 ╭──┴──╮    ╭──┻──╮     ╭──┴──╮     ╭──┴──╮     ╭──╩──╮
 │ end │    │ end │     │ end │     │ end │     │ end │
 ╰─────╯    ╰─────╯     ╰─────╯     ╰─────╯     ╰─────╯
```

## Highlighting a path

Make one route stand out with a different stroke style:

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Node};
use PhpDag\Style\EdgeStrokeStyle;

$graph = new Graph();
$graph->addNode(new Node('app', 'my-app'))
      ->addNode(new Node('fw', 'framework'))
      ->addNode(new Node('http', 'http-client'))
      ->addNode(new Node('log', 'logger'))
      ->addEdge(new Edge('app', 'fw'))
      ->addEdge(new Edge('app', 'http'))
      ->addEdge(new Edge('fw', 'log'));
$graph->highlightPath(['app', 'fw', 'log'], EdgeStrokeStyle::Double);

echo AsciiDag::default()->render($graph);
```

```
         ╭────────╮
         │ my-app │
         ╰────╦───╯
              ║
      ╔═══════╩───────┐
      ▼               ▼
╭─────╩─────╮  ╭──────┴──────╮
│ framework │  │ http-client │
╰─────╦─────╯  ╰─────────────╯
      ║
      ▼
 ╭────╩───╮
 │ logger │
 ╰────────╯
```

## ANSI colors

Color a path and render with the ANSI formatter for the terminal:

```php
use PhpDag\Style\AnsiColor;

$graph->colorPath(['app', 'fw', 'log'], AnsiColor::Red);

echo AsciiDag::builder()->ansi()->build()->render($graph);
```

When a colored path shares cells with plain edges (a shared trunk or a
crossing), the colored edge wins. The highlighted route stays unbroken from
start to end.

## ASCII-only output

For places without Unicode support (logs, old terminals, plain-text email),
render with plain ASCII characters:

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Node};

$graph = new Graph();
$graph->addNode(new Node('A', 'Start'))
      ->addNode(new Node('B', 'End'))
      ->addEdge(new Edge('A', 'B'));

echo AsciiDag::builder()->asciiGlyphs()->build()->render($graph);
```

```
+-------+
| Start |
+---+---+
    |
    v
 +--+--+
 | End |
 +-----+
```

## Spacing

Set the gap between sibling boxes and between layers:

```php
echo AsciiDag::builder()->nodeSpacing(4)->rankSpacing(3)->build()->render($graph);
```

The node spacing is at least 1 and the rank spacing at least 2; smaller values
are raised to that minimum without an error. The CLI has the same options:
`--node-spacing=N` and `--rank-spacing=N`.

## Wide characters

Text is measured in terminal columns, so CJK characters and most emoji take two
cells and line up. Each code point is drawn in its own cell, though: text that
combines several code points into one visible character, such as an accent
written as a separate combining mark, a flag, or a family emoji joined with
zero-width joiners, is measured and drawn wrongly. Use the precomposed form
(`é` instead of `e` plus `◌́`) where you can.
