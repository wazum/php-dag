# Groups

Draw a labelled box around a set of nodes:

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Group, Node};

$graph = new Graph();
$graph->addNode(new Node('push', 'Push'))
      ->addNode(new Node('lint', 'Lint'))
      ->addNode(new Node('test', 'Tests'))
      ->addNode(new Node('deploy', 'Deploy'))
      ->addEdge(new Edge('push', 'lint'))
      ->addEdge(new Edge('push', 'test'))
      ->addEdge(new Edge('lint', 'deploy'))
      ->addEdge(new Edge('test', 'deploy'))
      ->addGroup(new Group('quality', 'Quality', ['lint', 'test']));

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
group's rows are pushed out, so the border wraps members only.

Groups also work in left-to-right flow:

```php
use PhpDag\AsciiDag;
use PhpDag\Graph\{Edge, Graph, Group, Label, Node};

$graph = new Graph();
$graph->addNode(new Node('checkout', 'checkout'))
      ->addNode(new Node('lint', 'lint'))
      ->addNode(new Node('test', 'test'))
      ->addNode(new Node('deploy', 'deploy'))
      ->addEdge(new Edge('checkout', 'lint', label: new Label('src')))
      ->addEdge(new Edge('lint', 'test'))
      ->addEdge(new Edge('test', 'deploy', label: new Label('ok')))
      ->addGroup(new Group('build', 'Build', ['lint', 'test']));

echo AsciiDag::builder()->leftToRight()->build()->render($graph);
```

```
                   ╔═ Build ════════════╗
                   ║                    ║
  ╭──────────╮     ║ ╭──────╮  ╭──────╮ ║     ╭────────╮
  │ checkout ├─────╫▶┤ lint ├─▶┤ test ├─╫────▶┤ deploy │
  ╰──────────╯ src ║ ╰──────╯  ╰──────╯ ║ ok  ╰────────╯
                   ║                    ║
                   ╚════════════════════╝
```

Groups cannot be nested yet.
