# Left-to-right flow

Top to bottom is the default. Wide, shallow graphs such as workflows read better
from left to right:

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Label, Node};

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

Everything else works the same way in both directions: labels, groups, cycles,
self-loops, styles and parallel edges. On the command line, `--direction=lr`
does the same, and a DOT file with `rankdir=LR` picks it up by itself.
