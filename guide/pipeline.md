# Customising the layout

## Layout quality

Trade layout effort for speed on large graphs:

```php
use PhpDag\Layout\LayoutQuality;

echo AsciiDag::builder()->quality(LayoutQuality::Fast)->build()->render($graph);
```

- `Standard` (the default) and `Quality` place nodes in layers with **network
  simplex**, the method Graphviz `dot` uses. It keeps edges short, so the
  drawing is compact. `Quality` spends extra passes on tangled graphs.
- `Fast` uses simpler longest-path layering and fewer passes. Use it for very
  large graphs where the better layout is not worth the cost.

On the CLI: `--quality=fast|standard|quality`.

## Changing the processor pipeline

The layout engine is a chain of processors (see
[How it works](how-it-works.md)). Take the default chain, change it, and pass it
back:

```php
use PhpDag\Layout\LayerAssigner;

$pipeline = AsciiDag::builder()->defaultPipeline();
$pipeline->insertAfter(LayerAssigner::class, new MyCustomProcessor());
$pipeline->replace(LayerAssigner::class, new LayerAssigner(new MyLayering()));

echo AsciiDag::builder()->withPipeline($pipeline)->build()->render($graph);
```

`insertBefore()`, `insertAfter()`, `replace()`, and `remove()` each match the
first processor of the given class and throw if none is found. `add()` appends a
processor. All of them return the pipeline, so calls can be chained.

A processor implements `PhpDag\Layout\Processor` and changes the `LayoutGraph`
it is given: layers, order, coordinates or edge waypoints. The built-in
processors expect the earlier ones to have run, so add your own around them
rather than in place of them, unless you replace a whole step such as layering.
