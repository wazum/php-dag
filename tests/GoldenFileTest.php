<?php

declare(strict_types=1);

namespace PhpDag\Tests;

use PhpDag\AsciiDag;
use PhpDag\Graph\Badge;
use PhpDag\Graph\Edge;
use PhpDag\Graph\Graph;
use PhpDag\Graph\Group;
use PhpDag\Graph\Label;
use PhpDag\Graph\Node;
use PhpDag\Graph\NodeStyle;
use PhpDag\Layout\LayoutQuality;
use PhpDag\Style\BorderStyle;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Compares rendered output against golden files in tests/Fixtures/.
 *
 * To regenerate after an intentional rendering change:
 *   ddev exec 'UPDATE_SNAPSHOTS=1 ./vendor/bin/phpunit tests/GoldenFileTest.php'
 * then review the fixture diff before committing.
 */
final class GoldenFileTest extends TestCase
{
    private const FIXTURES_DIRECTORY = __DIR__.'/Fixtures';

    #[Test]
    #[DataProvider('scenarios')]
    public function rendersScenarioMatchingGoldenFile(string $fixtureName, Graph $graph, AsciiDag $asciiDag): void
    {
        $result = $asciiDag->render($graph)."\n";
        $fixturePath = self::FIXTURES_DIRECTORY.'/'.$fixtureName.'.txt';

        if ('1' === getenv('UPDATE_SNAPSHOTS')) {
            self::assertNotFalse(file_put_contents($fixturePath, $result));
        }

        self::assertFileExists($fixturePath, sprintf(
            'Missing golden file "%s.txt" — run UPDATE_SNAPSHOTS=1 to create it, then review the output.',
            $fixtureName,
        ));
        self::assertStringEqualsFile($fixturePath, $result);
    }

    /** @return iterable<string, array{string, Graph, AsciiDag}> */
    public static function scenarios(): iterable
    {
        $topToBottom = AsciiDag::default();
        $leftToRight = AsciiDag::builder()->leftToRight()->build();

        yield 'linear_chain' => ['linear_chain', self::linearChain(), $topToBottom];
        yield 'fan_out' => ['fan_out', self::fanOut(), $topToBottom];
        yield 'fan_in' => ['fan_in', self::fanIn(), $topToBottom];
        yield 'diamond' => ['diamond', self::diamond(), $topToBottom];
        yield 'ci_pipeline' => ['ci_pipeline', self::ciPipeline(), $topToBottom];
        yield 'skip_layer' => ['skip_layer', self::skipLayer(), $topToBottom];
        yield 'deep_skip' => ['deep_skip', self::deepSkip(), $topToBottom];
        yield 'mixed_heights' => ['mixed_heights', self::mixedHeights(), $topToBottom];
        yield 'multi_line_content' => ['multi_line_content', self::multiLineContent(), $topToBottom];
        yield 'border_styles' => ['border_styles', self::borderStyles(), $topToBottom];
        yield 'edge_styles' => ['edge_styles', self::edgeStyles(), $topToBottom];
        yield 'edge_labels' => ['edge_labels', self::edgeLabels(), $topToBottom];
        yield 'highlighted_path' => ['highlighted_path', self::highlightedPath(), $topToBottom];
        yield 'cycle' => ['cycle', self::cycle(), $topToBottom];
        yield 'disconnected_components' => ['disconnected_components', self::disconnectedComponents(), $topToBottom];
        yield 'wide_characters' => ['wide_characters', self::wideCharacters(), $topToBottom];
        yield 'ascii_glyphs' => ['ascii_glyphs', self::ciPipeline(), AsciiDag::builder()->asciiGlyphs()->build()];
        yield 'dot_import' => ['dot_import', self::fromDotSource(), $topToBottom];
        yield 'cluster' => ['cluster', self::clusteredPipeline(), $topToBottom];
        yield 'cluster_interleaved' => ['cluster_interleaved', self::interleavedCluster(), $topToBottom];
        yield 'cluster_two_groups' => ['cluster_two_groups', self::twoGroups(), $topToBottom];
        yield 'cluster_passing_edge' => ['cluster_passing_edge', self::clusterPassingEdge(), $topToBottom];
        yield 'cluster_evicts_foreign' => ['cluster_evicts_foreign', self::clusterEvictsForeign(), $topToBottom];
        yield 'cluster_stacked' => ['cluster_stacked', self::stackedClusters(), $topToBottom];
        yield 'left_to_right_chain' => ['left_to_right_chain', self::linearChain(), $leftToRight];
        yield 'left_to_right_workflow' => ['left_to_right_workflow', self::labeledWorkflow(), $leftToRight];
        yield 'network_simplex' => ['network_simplex', self::floatingNode(), AsciiDag::builder()->quality(LayoutQuality::Quality)->build()];
        yield 'left_to_right_cluster' => ['left_to_right_cluster', self::clusteredPipeline(), $leftToRight];
        yield 'left_to_right_cluster_evicts' => ['left_to_right_cluster_evicts', self::clusterEvictsForeign(), $leftToRight];
    }

    private static function fromDotSource(): Graph
    {
        return Graph::fromDot(<<<'DOT'
            digraph "pipeline" {
                compound = "true";
                node [shape=box];
                subgraph "root" {
                    "checkout" [label="Checkout"];
                    "build"    [label="Build\ncomposer install"];
                    "test"     [label="Tests"];
                    "deploy"   [label="Deploy"];
                    "checkout" -> "build";
                    "build" -> "test" [label="ok"];
                    "test" -> "deploy" [style=dashed];
                }
            }
            DOT);
    }

    private static function interleavedCluster(): Graph
    {
        // The non-member sits between the two group members in the fan-out
        // layer; GroupOrdering must pull the members contiguous and leave the
        // non-member outside the border.
        $graph = new Graph();
        $graph->addNode(new Node('root', 'Root'))
            ->addNode(new Node('a', 'Mine A'))
            ->addNode(new Node('b', 'Third Party'))
            ->addNode(new Node('c', 'Mine C'))
            ->addEdge(new Edge('root', 'a'))
            ->addEdge(new Edge('root', 'b'))
            ->addEdge(new Edge('root', 'c'))
            ->addGroup(new Group('mine', 'Mine', ['a', 'c']));

        return $graph;
    }

    private static function twoGroups(): Graph
    {
        // Two groups that share every layer must form side-by-side bands that
        // stay on the same side across layers (no band crossing).
        $graph = new Graph();
        $graph->addNode(new Node('root', 'Root'))
            ->addNode(new Node('p1', 'p/one'))
            ->addNode(new Node('q1', 'q/one'))
            ->addNode(new Node('p2', 'p/two'))
            ->addNode(new Node('q2', 'q/two'))
            ->addNode(new Node('sink', 'Sink'))
            ->addEdge(new Edge('root', 'p1'))
            ->addEdge(new Edge('root', 'q1'))
            ->addEdge(new Edge('p1', 'p2'))
            ->addEdge(new Edge('q1', 'q2'))
            ->addEdge(new Edge('p2', 'sink'))
            ->addEdge(new Edge('q2', 'sink'))
            ->addGroup(new Group('p', 'p', ['p1', 'p2']))
            ->addGroup(new Group('q', 'q', ['q1', 'q2']));

        return $graph;
    }

    private static function floatingNode(): Graph
    {
        // 'src' fans to 'mid' and to two length-3 chains ending at 'p'/'q'.
        // Longest-path pins 'mid' just below src (its edges to p/q then span the
        // gap); network-simplex layering (Quality preset) slides 'mid' down so
        // it sits beside p/q, shortening those edges into a more compact drawing.
        $graph = new Graph();
        $graph->addNode(new Node('src', 'Source'))
            ->addNode(new Node('mid', 'Mid'))
            ->addNode(new Node('p', 'P'))
            ->addNode(new Node('q', 'Q'))
            ->addNode(new Node('a', 'A'))
            ->addNode(new Node('b', 'B'))
            ->addNode(new Node('c', 'C'))
            ->addNode(new Node('d', 'D'))
            ->addEdge(new Edge('src', 'mid'))
            ->addEdge(new Edge('mid', 'p'))
            ->addEdge(new Edge('mid', 'q'))
            ->addEdge(new Edge('src', 'a'))
            ->addEdge(new Edge('a', 'b'))
            ->addEdge(new Edge('b', 'p'))
            ->addEdge(new Edge('src', 'c'))
            ->addEdge(new Edge('c', 'd'))
            ->addEdge(new Edge('d', 'q'));

        return $graph;
    }

    private static function stackedClusters(): Graph
    {
        // Two clusters stacked vertically. Each bottom border needs its own
        // extra row, so the compactor must reserve space at both bottom layers,
        // not just one.
        $graph = new Graph();
        $graph->addNode(new Node('a1', 'Alpha 1'))
            ->addNode(new Node('a2', 'Alpha 2'))
            ->addNode(new Node('b1', 'Beta 1'))
            ->addNode(new Node('b2', 'Beta 2'))
            ->addNode(new Node('sink', 'Sink'))
            ->addEdge(new Edge('a1', 'a2'))
            ->addEdge(new Edge('a2', 'b1'))
            ->addEdge(new Edge('b1', 'b2'))
            ->addEdge(new Edge('b2', 'sink'))
            ->addGroup(new Group('alpha', 'Alpha', ['a1', 'a2']))
            ->addGroup(new Group('beta', 'Beta', ['b1', 'b2']));

        return $graph;
    }

    private static function clusterEvictsForeign(): Graph
    {
        // 'Foreign' is pulled onto a layer the cluster spans (child of a member,
        // sibling of another member) and lands inside the members' column band.
        // ForeignNodeEvictor must push it clear so the border wraps members only.
        $graph = new Graph();
        $graph->addNode(new Node('root', 'Root'))
            ->addNode(new Node('ml', 'Member Left'))
            ->addNode(new Node('mr', 'Member Right'))
            ->addNode(new Node('mm', 'Member Mid'))
            ->addNode(new Node('foreign', 'Foreign'))
            ->addNode(new Node('sink', 'Sink'))
            ->addEdge(new Edge('root', 'ml'))
            ->addEdge(new Edge('root', 'mr'))
            ->addEdge(new Edge('ml', 'mm'))
            ->addEdge(new Edge('mr', 'mm'))
            ->addEdge(new Edge('ml', 'foreign'))
            ->addEdge(new Edge('mm', 'sink'))
            ->addEdge(new Edge('foreign', 'sink'))
            ->addGroup(new Group('members', 'Members', ['ml', 'mr', 'mm']));

        return $graph;
    }

    private static function clusterPassingEdge(): Graph
    {
        // A long edge from root to sink skips over the cluster's top layer.
        // Its bend must clear the cluster's top border (routing through dummy
        // lanes) instead of running along the border row.
        $graph = new Graph();
        $graph->addNode(new Node('root', 'Root'))
            ->addNode(new Node('a', 'Inside A'))
            ->addNode(new Node('b', 'Inside B'))
            ->addNode(new Node('sink', 'Sink'))
            ->addEdge(new Edge('root', 'a'))
            ->addEdge(new Edge('a', 'b'))
            ->addEdge(new Edge('b', 'sink'))
            ->addEdge(new Edge('root', 'sink'))
            ->addGroup(new Group('grp', 'Cluster', ['a', 'b']));

        return $graph;
    }

    private static function clusteredPipeline(): Graph
    {
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

        return $graph;
    }

    private static function linearChain(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Start'))
            ->addNode(new Node('b', 'Process'))
            ->addNode(new Node('c', 'End'))
            ->addEdge(new Edge('a', 'b'))
            ->addEdge(new Edge('b', 'c'));

        return $graph;
    }

    private static function fanOut(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('hub', 'Hub'))
            ->addNode(new Node('t1', 'Task 1'))
            ->addNode(new Node('t2', 'Task 2'))
            ->addNode(new Node('t3', 'Task 3'))
            ->addEdge(new Edge('hub', 't1'))
            ->addEdge(new Edge('hub', 't2'))
            ->addEdge(new Edge('hub', 't3'));

        return $graph;
    }

    private static function fanIn(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('s1', 'Source 1'))
            ->addNode(new Node('s2', 'Source 2'))
            ->addNode(new Node('s3', 'Source 3'))
            ->addNode(new Node('sink', 'Sink'))
            ->addEdge(new Edge('s1', 'sink'))
            ->addEdge(new Edge('s2', 'sink'))
            ->addEdge(new Edge('s3', 'sink'));

        return $graph;
    }

    private static function diamond(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('root', 'Root'))
            ->addNode(new Node('left', 'Left'))
            ->addNode(new Node('right', 'Right'))
            ->addNode(new Node('sink', 'Sink'))
            ->addEdge(new Edge('root', 'left'))
            ->addEdge(new Edge('root', 'right'))
            ->addEdge(new Edge('left', 'sink'))
            ->addEdge(new Edge('right', 'sink'));

        return $graph;
    }

    private static function ciPipeline(): Graph
    {
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

        return $graph;
    }

    private static function skipLayer(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('top', 'Top'))
            ->addNode(new Node('middle', 'Middle'))
            ->addNode(new Node('bottom', 'Bottom'))
            ->addEdge(new Edge('top', 'middle'))
            ->addEdge(new Edge('middle', 'bottom'))
            ->addEdge(new Edge('top', 'bottom'));

        return $graph;
    }

    private static function deepSkip(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'First'))
            ->addNode(new Node('b', 'Second'))
            ->addNode(new Node('c', 'Third'))
            ->addNode(new Node('d', 'Fourth'))
            ->addEdge(new Edge('a', 'b'))
            ->addEdge(new Edge('b', 'c'))
            ->addEdge(new Edge('c', 'd'))
            ->addEdge(new Edge('a', 'd'));

        return $graph;
    }

    private static function mixedHeights(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('root', 'Root'))
            ->addNode(new Node('tall', 'Tall', ['line 1', 'line 2', 'line 3']))
            ->addNode(new Node('short', 'Short'))
            ->addNode(new Node('sink', 'Sink'))
            ->addEdge(new Edge('root', 'tall'))
            ->addEdge(new Edge('root', 'short'))
            ->addEdge(new Edge('tall', 'sink'))
            ->addEdge(new Edge('short', 'sink'));

        return $graph;
    }

    private static function multiLineContent(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('db', 'Database', ['PostgreSQL', 'Port 5432'],
            style: new NodeStyle(borderStyle: BorderStyle::Double, titleBodySeparator: true),
        ));
        $graph->addNode(new Node('api', 'API Server', ['PHP 8.2'],
            style: new NodeStyle(badge: new Badge('v2')),
        ));
        $graph->addEdge(new Edge('db', 'api'));

        return $graph;
    }

    private static function borderStyles(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Rounded', style: new NodeStyle(borderStyle: BorderStyle::Rounded)))
            ->addNode(new Node('b', 'Solid', style: new NodeStyle(borderStyle: BorderStyle::Solid)))
            ->addNode(new Node('c', 'Double', style: new NodeStyle(borderStyle: BorderStyle::Double)))
            ->addNode(new Node('d', 'Dashed', style: new NodeStyle(borderStyle: BorderStyle::Dashed)))
            ->addNode(new Node('e', 'Dotted', style: new NodeStyle(borderStyle: BorderStyle::Dotted)));

        return $graph;
    }

    private static function edgeStyles(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('src', 'Source'))
            ->addNode(new Node('solid', 'Solid'))
            ->addNode(new Node('heavy', 'Heavy'))
            ->addNode(new Node('dashed', 'Dashed'))
            ->addNode(new Node('dotted', 'Dotted'))
            ->addEdge(new Edge('src', 'solid', EdgeStrokeStyle::Solid))
            ->addEdge(new Edge('src', 'heavy', EdgeStrokeStyle::Heavy))
            ->addEdge(new Edge('src', 'dashed', EdgeStrokeStyle::Dashed))
            ->addEdge(new Edge('src', 'dotted', EdgeStrokeStyle::Dotted));

        return $graph;
    }

    private static function edgeLabels(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('review', 'Review'))
            ->addNode(new Node('approve', 'Approve'))
            ->addNode(new Node('reject', 'Reject'))
            ->addEdge(new Edge('review', 'approve', label: new Label('yes')))
            ->addEdge(new Edge('review', 'reject', label: new Label('no')));

        return $graph;
    }

    private static function highlightedPath(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('app', 'my-app'))
            ->addNode(new Node('fw', 'framework'))
            ->addNode(new Node('http', 'http-client'))
            ->addNode(new Node('log', 'logger'))
            ->addEdge(new Edge('app', 'fw'))
            ->addEdge(new Edge('app', 'http'))
            ->addEdge(new Edge('fw', 'log'));
        $graph->highlightPath(['app', 'fw', 'log'], EdgeStrokeStyle::Double);

        return $graph;
    }

    private static function cycle(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'Start'))
            ->addNode(new Node('b', 'Process'))
            ->addNode(new Node('c', 'End'))
            ->addEdge(new Edge('a', 'b'))
            ->addEdge(new Edge('b', 'c'))
            ->addEdge(new Edge('c', 'a'));

        return $graph;
    }

    private static function disconnectedComponents(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('a1', 'Alpha 1'))
            ->addNode(new Node('a2', 'Alpha 2'))
            ->addNode(new Node('b1', 'Beta 1'))
            ->addNode(new Node('b2', 'Beta 2'))
            ->addEdge(new Edge('a1', 'a2'))
            ->addEdge(new Edge('b1', 'b2'));

        return $graph;
    }

    private static function wideCharacters(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('a', 'データベース'))
            ->addNode(new Node('b', 'サーバー', ['ポート 8080']))
            ->addEdge(new Edge('a', 'b'));

        return $graph;
    }

    private static function labeledWorkflow(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node('start', 'Review'))
            ->addNode(new Node('approve', 'Approve'))
            ->addNode(new Node('reject', 'Reject'))
            ->addNode(new Node('done', 'Done'))
            ->addEdge(new Edge('start', 'approve', label: new Label('yes')))
            ->addEdge(new Edge('start', 'reject', label: new Label('no')))
            ->addEdge(new Edge('approve', 'done'))
            ->addEdge(new Edge('reject', 'done'));

        return $graph;
    }
}
