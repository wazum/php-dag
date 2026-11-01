<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use PhpDag\AsciiDag;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Layout\LayoutQuality;

function chain(int $length): Graph
{
    $graph = new Graph();
    for ($index = 0; $index < $length; ++$index) {
        $graph->addNode(new Node('n'.$index, 'Node '.$index));
        if ($index > 0) {
            $graph->addEdge(new Edge('n'.($index - 1), 'n'.$index));
        }
    }

    return $graph;
}

function randomDag(int $nodeCount, bool $labels, int $reach = 6): Graph
{
    mt_srand(1);
    $graph = new Graph();
    for ($index = 0; $index < $nodeCount; ++$index) {
        $graph->addNode(new Node('n'.$index, 'Node '.$index));
    }
    for ($index = 1; $index < $nodeCount; ++$index) {
        for ($edge = 0; $edge < 2; ++$edge) {
            $source = mt_rand(max(0, $index - $reach), $index - 1);
            $graph->addEdge(new Edge('n'.$source, 'n'.$index, label: $labels ? new Label('label '.$edge) : null));
        }
    }

    return $graph;
}

$cases = [
    'chain, 500 nodes' => chain(500),
    'chain, 2000 nodes' => chain(2000),
    'random, 300 nodes, 600 edges' => randomDag(300, false),
    'random, 300 nodes, 600 labelled edges' => randomDag(300, true),
    'random, 1000 nodes, 2000 edges' => randomDag(1000, false),
    'random, 1000 nodes, 2000 edges of any length' => randomDag(1000, false, 1000),
];

printf("PHP %s\n\n", PHP_VERSION);
echo "| Graph | Quality | Time | Peak memory |\n";
echo "| --- | --- | ---: | ---: |\n";
foreach ($cases as $name => $graph) {
    foreach ([LayoutQuality::Fast, LayoutQuality::Standard] as $quality) {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage();
        $start = hrtime(true);
        AsciiDag::builder()->quality($quality)->build()->render($graph);
        printf(
            "| %s | %s | %.2f s | %.0f MB |\n",
            $name,
            $quality->name,
            (hrtime(true) - $start) / 1e9,
            (memory_get_peak_usage() - $memoryBefore) / 1048576,
        );
    }
}
