# Performance

php-dag uses the same algorithms as Graphviz `dot` and dagre: network simplex
for layering, a weighted median heuristic for crossings, and Brandes–Köpf for
coordinates. Each step is kept close to linear in the size of the graph, but
every layer that an edge skips adds a hidden node to it, so graphs with many
long edges cost more.

`bench/run.php` renders a few generated graphs and prints how long each took.
The "random" graphs give each node two parents among the six nodes before it;
the last case picks parents anywhere, so edges get long. On PHP 8.2 in a Docker
container on an Apple M4 Max:

| Graph | Quality | Time | Peak memory |
| --- | --- | ---: | ---: |
| chain, 500 nodes | Fast | 0.04 s | 6 MB |
| chain, 500 nodes | Standard | 0.02 s | 6 MB |
| chain, 2000 nodes | Fast | 0.09 s | 25 MB |
| chain, 2000 nodes | Standard | 0.10 s | 25 MB |
| random, 300 nodes, 600 edges | Fast | 0.04 s | 6 MB |
| random, 300 nodes, 600 edges | Standard | 0.06 s | 6 MB |
| random, 300 nodes, 600 labelled edges | Fast | 0.11 s | 11 MB |
| random, 300 nodes, 600 labelled edges | Standard | 0.12 s | 10 MB |
| random, 1000 nodes, 2000 edges | Fast | 0.23 s | 26 MB |
| random, 1000 nodes, 2000 edges | Standard | 0.39 s | 23 MB |
| random, 1000 nodes, 2000 edges of any length | Fast | 0.38 s | 41 MB |
| random, 1000 nodes, 2000 edges of any length | Standard | 2.07 s | 36 MB |

Run it yourself with `php bench/run.php`. The benchmark is not part of the
Composer package; clone the repository to run it.

For very large graphs, `LayoutQuality::Fast` trades layout effort for speed (see
[Layout quality](pipeline.md#layout-quality)), and `renderTo()` streams the
output instead of building one big string (see
[Reusing a layout](building-graphs.md#reusing-a-layout-and-streaming-output)).
