# php-dag

[![Latest version](https://img.shields.io/packagist/v/wazum/php-dag.svg)](https://packagist.org/packages/wazum/php-dag)
[![PHP version](https://img.shields.io/badge/php-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-8892BF.svg)](https://packagist.org/packages/wazum/php-dag)
[![Total downloads](https://img.shields.io/packagist/dt/wazum/php-dag.svg)](https://packagist.org/packages/wazum/php-dag)
[![CI](https://github.com/wazum/php-dag/actions/workflows/ci.yml/badge.svg)](https://github.com/wazum/php-dag/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Render directed acyclic graphs (DAGs) as ASCII art using the Sugiyama algorithm.
Cyclic graphs and self-loops work too.

```
     ╭──────╮
     │ Root │
     ╰───┬──╯
         │
    ┌────┴────┐
    ▼         ▼
╭───┴──╮  ╭───┴───╮
│ Left │  │ Right │
╰───┬──╯  ╰───┬───╯
    │         │
    └────┬────┘
         ▼
     ╭───┴──╮
     │ Sink │
     ╰──────╯
```

## Requirements

- PHP 8.2+ (tested on 8.2, 8.3, 8.4, and 8.5)
- `ext-mbstring`

## Installation

```bash
composer require wazum/php-dag
```

## Quick Start

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Graph, Node, Edge};

$graph = new Graph();
$graph->addNode(new Node('A', 'Start'))
      ->addNode(new Node('B', 'End'))
      ->addEdge(new Edge('A', 'B'));

echo AsciiDag::default()->render($graph);
```

Output:

```
╭───────╮
│ Start │
╰───┬───╯
    │
    ▼
 ╭──┴──╮
 │ End │
 ╰─────╯
```

## Building Graphs

For simple graphs you do not need explicit `Node` and `Edge` objects.
`Graph::fromEdges()` takes a map of titles and a list of edge pairs:

```php
$graph = Graph::fromEdges(
    nodes: ['a' => 'Start', 'b' => 'Build', 'c' => 'Deploy'],
    edges: [['a', 'b'], ['b', 'c']],
);
```

Or build it step by step with `connect()`. It creates any node that does not
exist yet (the title defaults to the id):

```php
$graph = (new Graph())
    ->connect('build', 'test')
    ->connect('test', 'deploy');
```

The full `addNode(new Node(...))` / `addEdge(new Edge(...))` API is still there
when you need titles, bodies, styles, labels, colors, or weights.

The graph also answers questions about its structure, so you do not have to
build your own indexes:

```php
$graph->successors('build');     // direct child node ids
$graph->predecessors('deploy');  // direct parent node ids
$graph->outgoingEdges('build');  // outgoing Edge objects
$graph->incomingEdges('deploy'); // incoming Edge objects
$graph->roots();                 // node ids with no incoming edges
$graph->leaves();                // node ids with no outgoing edges
$graph->descendants('build');    // all children, direct and indirect
$graph->ancestors('deploy');     // all parents, direct and indirect
$graph->shortestPath('build', 'deploy'); // node ids, or null
$graph->topologicalOrder();      // throws LogicException on a cycle
$graph->nodeCount();
$graph->edgeCount();
```

`successors()` and `predecessors()` list each neighbour once, even with
parallel edges. `outgoingEdges()` and `incomingEdges()` keep every edge.
Self-loops are separate, through `selfLoops()`.

## Examples

### Fan-Out

```php
$graph = new Graph();
$graph->addNode(new Node('A', 'Hub'))
      ->addNode(new Node('B', 'Task 1'))
      ->addNode(new Node('C', 'Task 2'))
      ->addNode(new Node('D', 'Task 3'))
      ->addEdge(new Edge('A', 'B'))
      ->addEdge(new Edge('A', 'C'))
      ->addEdge(new Edge('A', 'D'));

echo AsciiDag::default()->render($graph);
```

```
              ╭─────╮
              │ Hub │
              ╰──┬──╯
                 │
     ┌───────────┼───────────┐
     ▼           ▼           ▼
╭────┴───╮  ╭────┴───╮  ╭────┴───╮
│ Task 1 │  │ Task 2 │  │ Task 3 │
╰────────╯  ╰────────╯  ╰────────╯
```

### Diamond (Branch and Merge)

```php
$graph = new Graph();
$graph->addNode(new Node('ci', 'CI Pipeline'))
      ->addNode(new Node('lint', 'Lint'))
      ->addNode(new Node('test', 'Unit Tests'))
      ->addNode(new Node('build', 'Build'))
      ->addNode(new Node('deploy', 'Deploy'))
      ->addEdge(new Edge('ci', 'lint'))
      ->addEdge(new Edge('ci', 'test'))
      ->addEdge(new Edge('lint', 'build'))
      ->addEdge(new Edge('test', 'build'))
      ->addEdge(new Edge('build', 'deploy'));

echo AsciiDag::default()->render($graph);
```

```
    ╭─────────────╮
    │ CI Pipeline │
    ╰──────┬──────╯
           │
    ┌──────┴─────┐
    ▼            ▼
╭───┴──╮  ╭──────┴─────╮
│ Lint │  │ Unit Tests │
╰───┬──╯  ╰──────┬─────╯
    │            │
    └──────┬─────┘
           ▼
       ╭───┴───╮
       │ Build │
       ╰───┬───╯
           │
           ▼
      ╭────┴───╮
      │ Deploy │
      ╰────────╯
```

### Border Styles

```php
use PhpDag\Graph\NodeStyle;
use PhpDag\Style\BorderStyle;

new Node('a', 'Rounded', style: new NodeStyle(borderStyle: BorderStyle::Rounded));
new Node('b', 'Solid',   style: new NodeStyle(borderStyle: BorderStyle::Solid));
new Node('c', 'Double',  style: new NodeStyle(borderStyle: BorderStyle::Double));
new Node('d', 'Dashed',  style: new NodeStyle(borderStyle: BorderStyle::Dashed));
new Node('e', 'Dotted',  style: new NodeStyle(borderStyle: BorderStyle::Dotted));
```

```
╭─────────╮  ┌───────┐  ╔════════╗  ┌╌╌╌╌╌╌╌╌┐  ┌┈┈┈┈┈┈┈┈┐
│ Rounded │  │ Solid │  ║ Double ║  ╎ Dashed ╎  ┊ Dotted ┊
╰─────────╯  └───────┘  ╚════════╝  └╌╌╌╌╌╌╌╌┘  └┈┈┈┈┈┈┈┈┘
```

### Multi-Line Content and Badges

```php
use PhpDag\Graph\Badge;

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

### Edge Stroke Styles

```php
use PhpDag\Style\EdgeStrokeStyle;

new Edge('A', 'B', EdgeStrokeStyle::Solid);   // │ ─ (default)
new Edge('A', 'B', EdgeStrokeStyle::Heavy);   // ┃ ━
new Edge('A', 'B', EdgeStrokeStyle::Dashed);  // ╎ ╌
new Edge('A', 'B', EdgeStrokeStyle::Dotted);  // ┊ ┈
new Edge('A', 'B', EdgeStrokeStyle::Double);  // ║ ═
```

### Edge Labels

```php
use PhpDag\Graph\Label;

$graph = new Graph();
$graph->addNode(new Node('review', 'Review'))
      ->addNode(new Node('approve', 'Approve'))
      ->addNode(new Node('reject', 'Reject'))
      ->addEdge(new Edge('review', 'approve', label: new Label('yes')))
      ->addEdge(new Edge('review', 'reject', label: new Label('no')));

echo AsciiDag::default()->render($graph);
```

```
      ╭────────╮
      │ Review │
      ╰────┬───╯
           │
 yes ┌─────┴──────┐ no
     ▼            ▼
╭────┴────╮  ╭────┴───╮
│ Approve │  │ Reject │
╰─────────╯  ╰────────╯
```

### Left-to-Right Flow

```php
$graph = new Graph();
$graph->addNode(new Node('review', 'Review'))
      ->addNode(new Node('approve', 'Approve'))
      ->addNode(new Node('reject', 'Reject'))
      ->addNode(new Node('done', 'Done'))
      ->addEdge(new Edge('review', 'approve', label: new Label('yes')))
      ->addEdge(new Edge('review', 'reject', label: new Label('no')))
      ->addEdge(new Edge('approve', 'done'))
      ->addEdge(new Edge('reject', 'done'));

echo AsciiDag::builder()->leftToRight()->build()->render($graph);
```

```
            yes
              ╭─────────╮
            ┌▶┤ Approve ├─┐
            │ ╰─────────╯ │
╭────────╮  │             │ ╭──────╮
│ Review ├──┤             ├▶┤ Done │
╰────────╯  │ ╭────────╮  │ ╰──────╯
            └▶┤ Reject ├──┘
              ╰────────╯
            no
```

### Path Highlighting

Make one route stand out with a different stroke style:

```php
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

### ANSI Colors

Color a path and render with the ANSI formatter for the terminal:

```php
use PhpDag\Style\AnsiColor;

$graph->colorPath(['A', 'B'], AnsiColor::Red);

echo AsciiDag::builder()->ansi()->build()->render($graph);
```

When a colored path shares cells with plain edges (a shared trunk or a
crossing), the colored edge wins. The highlighted route stays unbroken from
start to end.

### Groups (Clusters)

Draw a labelled box around a set of nodes:

```php
use PhpDag\Graph\Group;

$graph->addGroup(new Group('quality', 'Quality', ['lint', 'test']));

echo AsciiDag::default()->render($graph);
```

```
       ╭──────╮
       │ Push │
       ╰───┬──╯
      ┌────┴────┐
╔═════╪ Quality ╪═════╗
║     ▼         ▼     ║
║ ╭───┴──╮  ╭───┴───╮ ║
║ │ Lint │  │ Tests │ ║
║ ╰───┬──╯  ╰───┬───╯ ║
║     │         │     ║
╚═════╪═════════╪═════╝
      └────┬────┘
           ▼
      ╭────┴───╮
      │ Deploy │
      ╰────────╯
```

Edges that enter the group pass through the top border as `╪`. Edges that cross
a side border show as `╫`. Nodes that are not members but land inside the
group's rows are pushed out, so the border wraps members only. Groups also work
in left-to-right flow. Nested groups are not supported yet.

### Graphviz DOT Import and Export

Read DOT from `terraform graph`, *Symfony*'s `workflow:dump`, or any other tool:

```php
$graph = Graph::fromDot('digraph { rankdir=LR; a -> b [label="ok"]; }');

echo AsciiDag::default()->render($graph);

$dot = $graph->toDot();   // back to Graphviz
```

The parser reads the common DOT that real tools emit: `strict digraph`,
subgraphs, clusters (imported as groups), default attributes, quoted
identifiers, numeric values (negatives included), HTML-like labels (tags
removed), multi-line labels (`\n`), and comments. Unknown attributes are
ignored. `strict` graphs merge repeated edges; non-strict graphs keep parallel
edges. Self-loops are kept and drawn. Control characters in labels are stripped,
so an untrusted file cannot inject terminal escape sequences.
`PhpDag\Dot\DotParser::flowDirection()` reports the `rankdir`.

It is a pragmatic subset of the [DOT
grammar](https://graphviz.org/doc/info/lang.html), not a full implementation:
subgraphs used as edge endpoints (`{a b} -> c`) and quoted-string concatenation
with `+` are not supported.

### Command Line

The package ships a `php-dag` binary. It reads a DOT file, or DOT from stdin:

```bash
vendor/bin/php-dag deps.dot
terraform graph | vendor/bin/php-dag --direction=lr
vendor/bin/php-dag --ascii deps.dot > graph.txt
```

The `rankdir` from the file is used automatically; `--direction=tb|lr` overrides
it. Colors are auto-detected (`--ansi` / `--no-ansi` to force). `--node-spacing`,
`--rank-spacing`, and `--quality` map to the options below. Run `php-dag --help`
for the full list. Syntax errors go to stderr with the line and column and a
non-zero exit code.

### Symfony Workflow

*Symfony*'s [`workflow:dump`](https://symfony.com/doc/current/workflow/dumping-workflows.html)
command prints a workflow as *Graphviz* DOT. Pipe it to *php-dag* to see the
diagram right in the terminal, with no *Graphviz* install:

```bash
php bin/console workflow:dump blog_publishing | vendor/bin/php-dag
```

The dump sets `rankdir="LR"`, so `php-dag` draws it left to right:

```
╭───────╮  ╭───────────╮  ╭──────────╮  ╭─────────╮  ╭───────────╮
│ draft ├─▶┤ to_review ├─▶┤ reviewed ├─▶┤ publish ├─▶┤ published │
╰───────╯  ╰───────────╯  ╰──────────╯  ╰─────────╯  ╰───────────╯
```

Places and transitions both become boxes. Add `--ascii` for plain output, or
`--direction=tb` to force top-to-bottom.

### Spacing

Set the gap between sibling boxes and between layers:

```php
echo AsciiDag::builder()->nodeSpacing(4)->rankSpacing(3)->build()->render($graph);
```

The CLI has the same options: `--node-spacing=N` and `--rank-spacing=N`.

### Layout Quality

Trade layout effort for speed on large graphs:

```php
use PhpDag\Layout\LayoutQuality;

echo AsciiDag::builder()->quality(LayoutQuality::Fast)->build()->render($graph);
```

- `Standard` (the default) and `Quality` place nodes in layers with **network
  simplex**, the method *Graphviz dot* uses. It keeps edges short, so the drawing
  is compact. `Quality` spends extra passes on tangled graphs.
- `Fast` uses simpler longest-path layering and fewer passes. Use it for very
  large graphs where the better layout is not worth the cost.

On the CLI: `--quality=fast|standard|quality`.

### ASCII-Only Output

For places without Unicode support (logs, old terminals, plain-text email),
render with plain ASCII characters:

```php
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

### Customizing the Layout Pipeline

The layout engine is a chain of processors. Take the default chain, change it,
and pass it back:

```php
use PhpDag\Layout\LayerAssigner;

$pipeline = AsciiDag::builder()->defaultPipeline();
$pipeline->insertAfter(LayerAssigner::class, new MyCustomProcessor());
$pipeline->replace(LayerAssigner::class, new LayerAssigner(new MyLayering()));

echo AsciiDag::builder()->withPipeline($pipeline)->build()->render($graph);
```

`insertBefore()`, `insertAfter()`, `replace()`, and `remove()` each match the
first processor of the given class and throw if none is found.

### Cycles

Cycles are handled for you: back edges are reversed for layout and drawn dashed.
Use `$graph->isCyclic()` to check first.

```php
$graph = new Graph();
$graph->addNode(new Node('a', 'Start'))
      ->addNode(new Node('b', 'Process'))
      ->addNode(new Node('c', 'End'))
      ->addEdge(new Edge('a', 'b'))
      ->addEdge(new Edge('b', 'c'))
      ->addEdge(new Edge('c', 'a'));

echo AsciiDag::default()->render($graph);
```

```
 ╭───────╮
 │ Start ├◀╌╌┐
 ╰───┬───╯   ╎
     │       ╎
     ▼       ╎
╭────┴────╮  ╎
│ Process │  ╎
╰────┬────╯  ╎
     │       ╎
     ▼       ╎
  ╭──┴──╮    ╎
  │ End ├╌╌╌╌┘
  ╰─────╯
```

### Reusing a Layout and Streaming Output

`render()` is a shortcut. For more control, `layout()` computes the drawing once
and returns an immutable `LayoutResult`. You can render, measure, or stream it
many times without computing again:

```php
$result = AsciiDag::default()->layout($graph);

echo $result->render();      // the diagram as a string
$width  = $result->width();  // width in columns
$height = $result->height(); // height in rows
```

For large diagrams, `renderTo()` writes the output line by line to any writable
resource, so the whole string never sits in memory at once:

```php
$result->renderTo(STDOUT);
// or a file: $result->renderTo(fopen('graph.txt', 'wb'));
```

`renderTo()` writes exactly the same bytes that `render()` returns.

## Performance

*php-dag* stays fast on large graphs. It uses the same algorithms as
*Graphviz dot* and *dagre*, kept close to linear time:

- **Layering** with network simplex (the dot method); each exchange recomputes
  cut values in one O(V + E) pass, and the number of exchanges is bounded.
- **Cycle breaking** with a greedy heuristic and incremental degree updates,
  O((V + E) log V).
- **Crossing minimization** scores each swap from the two nodes' own edges, not a
  full layer recount.
- **Batched mutations**: dummy nodes and edge changes rebuild the indexes once
  per phase, not per edge.
- **Fan-aware rendering** paints a shared trunk once, so it stays linear in the
  trunk length, not quadratic in the fan width.
- **Compact cells** store a few scalars per cell, not one object per edge.

For very large graphs, `LayoutQuality::Fast` trades layout effort for speed (see
[Layout Quality](#layout-quality)).

## How It Works

The library runs the [Sugiyama algorithm](https://en.wikipedia.org/wiki/Layered_graph_drawing)
as a three-stage pipeline:

```
                             ┌──────────────────────────────────────────────┐
                             │                                              │
╭───────╮    ╭───────────╮   │   ╭──────────────────────────────────────╮   │   ╭──────────╮    ╭────────╮
│ Graph │───>│  Layout   │───│──>│          Processor Pipeline          │───│──>│ Renderer │───>│ string │
╰───────╯    │  Engine   │   │   ╰──────────────────────────────────────╯   │   ╰──────────╯    ╰────────╯
  Domain     ╰───────────╯   │     LayoutGraph (mutable IR) passed through  │     Canvas
  Model        Facade        │                                              │     + Formatters
                             └──────────────────────────────────────────────┘
```

### Stage 1: Graph (Domain Model)

The public API. Build a graph with nodes and edges:

```
 Graph (aggregate root)
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

### Stage 2: Layout Engine (Sugiyama Pipeline)

Turns the graph into coordinates. Each processor changes one mutable
`LayoutGraph`:

```
 Graph
   │
   ▼
 LayoutGraph::fromGraph()         Wrap nodes/edges into layout IR
   │
   ▼
 ┌──────────────────────────┐
 │   1. CycleBreaker        │    Reverse back edges so layout
 │                          │    sees an acyclic graph
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   2. LayerAssigner       │    Put each node in a layer (rank).
 │      NetworkSimplex /    │    Standard/Quality keep edges short;
 │      LongestPath         │    Fast uses longest-path layering
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   3. DummyNodeInserter   │    Split long edges (over 1 layer)
 │                          │    into unit segments with dummy nodes
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   4. Ordering            │    Order nodes in each layer to cut
 │      DepthFirst + Median │    crossings; keep group members together
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   5. Position + Groups   │    Assign coordinates, move non-members
 │      Brandes-Kopf        │    out of clusters, reserve group borders
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   6. VerticalCompactor   │    Remove extra whitespace along
 │      / Horizontal        │    the flow axis
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   7. LabelReserver       │    Reserve space for edge labels
 │                          │    before routing
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   8. EdgeRouter          │    Compute waypoints for each edge
 │      ChainAwareRouting   │    (exit, via points, entry)
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │   9. DummyNodeRemover    │    Remove dummy nodes, keep the
 │                          │    routed waypoints
 └────────────┬─────────────┘
              ▼
 ┌──────────────────────────┐
 │  10. Special Edges       │    Route reversed edges and self-loops
 │      Feedback + SelfLoop │    after the normal edges
 └────────────┬─────────────┘
              ▼
 LayoutGraph with coordinates + waypoints
```

Left-to-right flow uses its own positioning, compaction, label, and routing
processors, but the stages stay the same.

### Stage 3: Renderer (Canvas-Based)

Draws the positioned nodes and edges onto a sparse 2D character buffer:

```
 LayoutGraph
   │
   ├──> EdgeRenderer          Draw edge paths with direction bitmasks.
   │      │                   Bits OR together at junctions:
   │      ▼                     UP|DOWN     = │
   │    Canvas                  LEFT|RIGHT  = ─
   │      ▲                     UP|RIGHT    = └
   │      │
   ├──> GroupRenderer         Draw cluster borders and crossings
   ├──> LabelRenderer         Place edge labels
   └──> BoxRenderer           Draw borders, titles, body, badges
          │
          ▼
 PlainTextFormatter           Turn canvas cells into a string.
   │                          Resolve z-index conflicts
   ▼                          (connections > boxes > labels > groups > edges)
 Text output
```

## Prior Art & Inspiration

php-dag stands on established graph-drawing work:

- **[Graphviz `dot`](https://graphviz.org/)** (C) — the layered pipeline and the
  algorithms reimplemented here: network-simplex ranking, the weighted-median
  crossing heuristic, and Brandes–Köpf coordinate assignment.
- **[dagre](https://github.com/dagrejs/dagre)** (JavaScript) — a practical
  blueprint for network-simplex ranking outside a C codebase.
- **[ELK (Eclipse Layout Kernel)](https://eclipse.dev/elk/)** (Java) — the
  processor-pipeline architecture: layout as a chain of small, swappable phases
  rather than one monolith.
- **[Ratatui](https://ratatui.rs/)** (Rust) — the styled cell-buffer model
  behind `Canvas`, where each cell carries a glyph, direction bits, and style.

## Support

Bug reports, questions, and feature ideas → the [issue
tracker](https://github.com/wazum/php-dag/issues).

## License

Released under the [MIT License](LICENSE).

---

Made with love for the PHP community by [Wolfgang Klinger](https://www.wolfgang-klinger.dev/).
