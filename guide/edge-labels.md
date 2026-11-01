# Edge labels

Give an edge a `Label` and it is drawn beside its own line:

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Label, Node};

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

Labels never overwrite a box, an arrowhead, another edge or another label. When
two labels need the same spot, the layout makes room: boxes move apart or a gap
gets an extra row.

## Where the label sits

`LabelPosition::Middle` is the default. `Source` puts the label near the start
of the edge, `Target` near its end:

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Label, LabelPosition, Node};

$graph = new Graph();
$graph->addNode(new Node('a', 'Build'))
      ->addNode(new Node('b', 'Test'))
      ->addNode(new Node('c', 'Package'))
      ->addNode(new Node('d', 'Release'))
      ->addEdge(new Edge('a', 'b'))
      ->addEdge(new Edge('b', 'c'))
      ->addEdge(new Edge('a', 'd', label: new Label('hotfix', LabelPosition::Source)))
      ->addEdge(new Edge('c', 'd'));

echo AsciiDag::default()->render($graph);
```

```
     ╭───────╮
     │ Build │
     ╰───┬───╯
         │
     ┌───┴───┐
     ▼       │ hotfix
 ╭───┴──╮    │
 │ Test │    │
 ╰───┬──╯    │
     │       │
     ▼       │
╭────┴────╮  │
│ Package │  │
╰────┬────╯  │
     │       │
     └───┬───┘
         ▼
    ╭────┴────╮
    │ Release │
    ╰─────────╯
```

## Long labelled edges

An unlabelled edge that spans several layers may share a trunk with others. A
labelled one keeps a lane of its own, so you can always tell which edge a label
belongs to. That makes the drawing a little wider.
