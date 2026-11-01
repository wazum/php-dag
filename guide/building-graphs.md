# Building graphs

## Three ways to build a graph

For simple graphs you do not need explicit `Node` and `Edge` objects.
`Graph::fromEdges()` takes a map of titles and a list of edge pairs:

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\Graph;

$graph = Graph::fromEdges(
    nodes: ['a' => 'Start', 'b' => 'Build', 'c' => 'Deploy'],
    edges: [['a', 'b'], ['b', 'c']],
);

echo AsciiDag::default()->render($graph);
```

```
 ╭───────╮
 │ Start │
 ╰───┬───╯
     │
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

Or build it step by step with `connect()`. It creates any node that does not
exist yet (the title defaults to the id):

```php
$graph = (new Graph())
    ->connect('build', 'test')
    ->connect('test', 'deploy');
```

The full `addNode(new Node(...))` / `addEdge(new Edge(...))` API is there when
you need titles, bodies, styles, labels, colors, or weights.

## Asking the graph about its structure

The graph answers questions about its structure, so you do not have to build
your own indexes:

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

## Fan-out

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Node};

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

## Branch and merge

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Node};

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

## Cycles and self-loops

Cycles are handled for you: back edges are reversed for layout and drawn dashed.
Use `$graph->isCyclic()` to check first.

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Node};

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

An edge from a node to itself is drawn as a small loop beside the box:

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\Graph;

$graph = (new Graph())
    ->connect('retry', 'retry')
    ->connect('retry', 'done');

echo AsciiDag::default()->render($graph);
```

```
╭───────╮
│ retry ├◀┐
╰───┬───╯ │
    ├─────┘
    ▼
╭───┴──╮
│ done │
╰──────╯
```

## Reusing a layout and streaming output

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
