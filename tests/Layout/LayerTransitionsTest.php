<?php

declare(strict_types=1);

namespace PhpDag\Tests\Layout;

use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Node;
use PhpDag\Graph\NodeStyle;
use PhpDag\Layout\LayerTransitions;
use PhpDag\Layout\LayoutGraph;
use PhpDag\Style\BorderStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayerTransitionsTest extends TestCase
{
    #[Test]
    public function equalCentersAreStraight(): void
    {
        $layoutGraph = $this->buildTransition(sourceColumn: 0, targetColumn: 0);

        self::assertFalse(LayerTransitions::hasBendingEdge($layoutGraph, 0));
    }

    #[Test]
    public function centerInsideTargetButTwoColumnsOffIsABend(): void
    {
        $layoutGraph = $this->buildTransition(
            sourceColumn: 0,
            targetColumn: 0,
            targetTitle: 'Wide Target',
        );

        $sourceCenter = $this->centerOf($layoutGraph, 'S');
        $targetCenter = $this->centerOf($layoutGraph, 'T');
        self::assertGreaterThan(1, abs($sourceCenter - $targetCenter), 'Scenario must be more than one column off');

        self::assertTrue(LayerTransitions::hasBendingEdge($layoutGraph, 0));
    }

    #[Test]
    public function offByOneLandingOnTheTargetBorderIsABend(): void
    {
        $layoutGraph = $this->buildTransition(
            sourceColumn: 1,
            targetColumn: 0,
            sourceStyle: new NodeStyle(borderStyle: BorderStyle::None),
            targetStyle: new NodeStyle(borderStyle: BorderStyle::None),
        );

        $sourceCenter = $this->centerOf($layoutGraph, 'S');
        $targetNode = $layoutGraph->getLayoutNode('T');
        self::assertSame(1, abs($sourceCenter - ($targetNode->column + intdiv($targetNode->boxWidth(), 2))));
        self::assertSame($targetNode->column + $targetNode->boxWidth() - 1, $sourceCenter, 'Source center must land exactly on the last target column');

        self::assertTrue(LayerTransitions::hasBendingEdge($layoutGraph, 0), 'Landing on the border column cannot route straight into the box');
    }

    #[Test]
    public function offByOneLandingOnTheFirstTargetColumnIsABend(): void
    {
        $layoutGraph = $this->buildTransition(
            sourceColumn: 1,
            targetColumn: 2,
            sourceStyle: new NodeStyle(borderStyle: BorderStyle::None),
            targetStyle: new NodeStyle(borderStyle: BorderStyle::None),
        );

        $sourceCenter = $this->centerOf($layoutGraph, 'S');
        $targetNode = $layoutGraph->getLayoutNode('T');
        self::assertSame($targetNode->column, $sourceCenter, 'Source center must land exactly on the first target column');

        self::assertTrue(LayerTransitions::hasBendingEdge($layoutGraph, 0), 'Landing on the first border column cannot route straight into the box');
    }

    #[Test]
    public function offByOneLandingOnTheLastInteriorColumnIsStraight(): void
    {
        $layoutGraph = $this->buildTransition(
            sourceColumn: 2,
            targetColumn: 0,
            targetTitle: 'ABC',
            sourceStyle: new NodeStyle(borderStyle: BorderStyle::None),
            targetStyle: new NodeStyle(borderStyle: BorderStyle::None),
        );

        $sourceCenter = $this->centerOf($layoutGraph, 'S');
        $targetNode = $layoutGraph->getLayoutNode('T');
        self::assertSame($targetNode->column + $targetNode->boxWidth() - 2, $sourceCenter, 'Source center must land on the last interior column');

        self::assertFalse(LayerTransitions::hasBendingEdge($layoutGraph, 0), 'The last interior column is still inside the box and routes straight');
    }

    private function buildTransition(int $sourceColumn, int $targetColumn, string $targetTitle = 'T', ?NodeStyle $sourceStyle = null, ?NodeStyle $targetStyle = null): LayoutGraph
    {
        $graph = new Graph();
        $graph->addNode(new Node('S', 'S', style: $sourceStyle ?? new NodeStyle()));
        $graph->addNode(new Node('T', $targetTitle, style: $targetStyle ?? new NodeStyle()));
        $graph->addEdge(new Edge('S', 'T'));

        $layoutGraph = LayoutGraph::fromGraph($graph);
        $sourceNode = $layoutGraph->getLayoutNode('S');
        $sourceNode->layer = 0;
        $sourceNode->column = $sourceColumn;
        $targetNode = $layoutGraph->getLayoutNode('T');
        $targetNode->layer = 1;
        $targetNode->column = $targetColumn;
        $layoutGraph->buildLayerIndex();

        return $layoutGraph;
    }

    private function centerOf(LayoutGraph $layoutGraph, string $nodeId): int
    {
        $node = $layoutGraph->getLayoutNode($nodeId);

        return $node->column + intdiv($node->boxWidth(), 2);
    }
}
