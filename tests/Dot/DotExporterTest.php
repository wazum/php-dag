<?php

declare(strict_types=1);

namespace PhpDag\Tests\Dot;

use PhpDag\Dot\DotExporter;
use PhpDag\Dot\DotParser;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DotExporterTest extends TestCase
{
    #[Test]
    public function exportsNodesAndEdgesAsDigraph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Start'))
            ->addNode(new Node('db', 'Database', ['PostgreSQL']))
            ->addEdge(new Edge('a', 'db', EdgeStrokeStyle::Dashed, label: new Label('uses')));

        $dot = (new DotExporter())->export($graph);

        $expected = <<<'DOT'
        digraph {
            "a" [label="Start"];
            "db" [label="Database\nPostgreSQL"];
            "a" -> "db" [label="uses", style=dashed];
        }
        DOT;

        self::assertSame($expected, $dot);
    }

    #[Test]
    public function exportedDotRoundTripsThroughTheParser(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Start "quoted"'))
            ->addNode(new Node('db', 'Database', ['PostgreSQL', 'Port 5432']))
            ->addNode(new Node('c', 'End'))
            ->addEdge(new Edge('a', 'db', EdgeStrokeStyle::Dashed, label: new Label('uses')))
            ->addEdge(new Edge('db', 'c'));

        $reparsed = (new DotParser())->parse((new DotExporter())->export($graph));

        self::assertSame(array_keys($graph->nodes()), array_keys($reparsed->nodes()));
        self::assertSame('Start "quoted"', $reparsed->getNode('a')->title);
        self::assertSame(['PostgreSQL', 'Port 5432'], $reparsed->getNode('db')->body);
        self::assertSame($graph->edgeCount(), $reparsed->edgeCount());
        self::assertSame('uses', $reparsed->edges()[0]->label?->text);
        self::assertSame(EdgeStrokeStyle::Dashed, $reparsed->edges()[0]->edgeStrokeStyle);
    }

    #[Test]
    public function exportsAndRoundTripsSelfLoops(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('A', 'A'))
            ->addNode(new Node('B', 'B'))
            ->addEdge(new Edge('A', 'B'))
            ->addEdge(new Edge('A', 'A'));

        $dot = (new DotExporter())->export($graph);
        self::assertStringContainsString('"A" -> "A";', $dot);

        $reparsed = (new DotParser())->parse($dot);
        self::assertCount(1, $reparsed->selfLoops());
        self::assertSame('A', $reparsed->selfLoops()[0]->sourceId);
    }

    #[Test]
    public function roundTripsTitlesContainingBackslashes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'C:\new folder\\'))
            ->addNode(new Node('b', 'ends with backslash \\'))
            ->addEdge(new Edge('a', 'b'));

        $reparsed = (new DotParser())->parse((new DotExporter())->export($graph));

        self::assertSame('C:\new folder\\', $reparsed->getNode('a')->title);
        self::assertSame('ends with backslash \\', $reparsed->getNode('b')->title);
        self::assertSame([], $reparsed->getNode('a')->body);
    }

    #[Test]
    public function distinguishesLiteralBackslashNFromMultiLineSeparator(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'literal\n', ['second line']))
            ->addNode(new Node('b', 'B'))
            ->addEdge(new Edge('a', 'b'));

        $reparsed = (new DotParser())->parse((new DotExporter())->export($graph));

        self::assertSame('literal\n', $reparsed->getNode('a')->title);
        self::assertSame(['second line'], $reparsed->getNode('a')->body);
    }

    #[Test]
    public function declaresGroupMembersInsideAClusterSubgraph(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'))
            ->addNode(new Node('b', 'B'))
            ->addEdge(new Edge('a', 'b'))
            ->addGroup(new Group('direct', 'Direct', ['a']));

        $dot = (new DotExporter())->export($graph);

        $expected = <<<'DOT'
        digraph {
            subgraph "cluster_direct" {
                label="Direct";
                "a" [label="A"];
            }
            "b" [label="B"];
            "a" -> "b";
        }
        DOT;

        self::assertSame($expected, $dot);
    }

    #[Test]
    public function exportsAndRoundTripsGroups(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'A'))
            ->addNode(new Node('b', 'B'))
            ->addNode(new Node('c', 'C'))
            ->addEdge(new Edge('a', 'b'))
            ->addEdge(new Edge('b', 'c'))
            ->addGroup(new Group('cluster_direct', 'Direct dependencies', ['a', 'b']));

        $reparsed = (new DotParser())->parse((new DotExporter())->export($graph));

        self::assertCount(1, $reparsed->groups());
        self::assertSame('Direct dependencies', $reparsed->groups()[0]->label);
        self::assertSame(['a', 'b'], $reparsed->groups()[0]->nodeIds);
    }

    #[Test]
    public function exportsDottedHeavyAndDoubleStrokeStyles(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'a'))
            ->addNode(new Node('b', 'b'))
            ->addNode(new Node('c', 'c'))
            ->addNode(new Node('d', 'd'))
            ->addEdge(new Edge('a', 'b', EdgeStrokeStyle::Dotted))
            ->addEdge(new Edge('b', 'c', EdgeStrokeStyle::Heavy))
            ->addEdge(new Edge('c', 'd', EdgeStrokeStyle::Double));

        $dot = (new DotExporter())->export($graph);

        self::assertStringContainsString('"a" -> "b" [style=dotted];', $dot);
        self::assertStringContainsString('"b" -> "c" [style=bold];', $dot);
        self::assertStringContainsString('"c" -> "d" [style=bold];', $dot);
    }
}
