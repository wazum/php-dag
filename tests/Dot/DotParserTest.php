<?php

declare(strict_types=1);

namespace PhpDag\Tests\Dot;

use InvalidArgumentException;
use PhpDag\Dot\DotParser;
use PhpDag\Dot\DotSyntaxException;
use PhpDag\Layout\FlowDirection;
use PhpDag\Style\EdgeStrokeStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DotParserTest extends TestCase
{
    #[Test]
    public function stripsControlCharactersFromNodeLabels(): void
    {
        $graph = (new DotParser())->parse("digraph { a [label=\"x\x1by\"]; }");

        self::assertSame('xy', $graph->getNode('a')->title);
    }

    #[Test]
    public function stripsControlCharactersFromEdgeLabels(): void
    {
        $graph = (new DotParser())->parse("digraph { a -> b [label=\"go\x1b[31m\"]; }");

        self::assertSame('go[31m', $graph->edges()[0]->label?->text);
    }

    #[Test]
    public function stripsControlCharactersFromGroupLabels(): void
    {
        $graph = (new DotParser())->parse("digraph { subgraph cluster_x { label=\"safe\x1b[31m\"; a; } }");

        self::assertSame('safe[31m', $graph->groups()[0]->label);
    }

    #[Test]
    public function escapesControlCharactersInSyntaxErrors(): void
    {
        try {
            (new DotParser())->parse("\"bad\x1b\" { a; }");
            self::fail('Expected DotSyntaxException');
        } catch (DotSyntaxException $exception) {
            self::assertStringContainsString('bad\\u{001B}', $exception->getMessage());
            self::assertStringNotContainsString("\x1b", $exception->getMessage());
        }
    }

    #[Test]
    public function parsesNegativeNumericAttributeValues(): void
    {
        $graph = (new DotParser())->parse('digraph { graph [margin=-0.1]; a -> b; }');

        self::assertSame(1, $graph->edgeCount());
    }

    #[Test]
    public function parsesDigraphWithDeclaredNodesAndAnEdge(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph G {
                a;
                b;
                a -> b;
            }
            DOT);

        self::assertSame(2, $graph->nodeCount());
        self::assertSame(1, $graph->edgeCount());
        self::assertSame('a', $graph->getNode('a')->title);
        self::assertSame(['b'], $graph->successors('a'));
    }

    #[Test]
    public function nodeLabelAttributeBecomesTitle(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                a [label="Start Here"];
            }
            DOT);

        self::assertSame('Start Here', $graph->getNode('a')->title);
    }

    #[Test]
    public function edgeLabelAndStyleAttributesAreApplied(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                a -> b [label="yes", style=dashed];
            }
            DOT);

        $edge = $graph->edges()[0];
        self::assertNotNull($edge->label);
        self::assertSame('yes', $edge->label->text);
        self::assertSame(EdgeStrokeStyle::Dashed, $edge->edgeStrokeStyle);
    }

    #[Test]
    public function mergesRepeatedAttributeLists(): void
    {
        $graph = (new DotParser())->parse('digraph { a -> b [label=old][label=new][style=dashed]; }');

        self::assertSame('new', $graph->edges()[0]->label?->text);
        self::assertSame(EdgeStrokeStyle::Dashed, $graph->edges()[0]->edgeStrokeStyle);
    }

    #[Test]
    public function ignoresLineAndBlockComments(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                // line comment
                a -> b; # hash comment
                /* block
                   comment */
                b -> c;
            }
            DOT);

        self::assertSame(3, $graph->nodeCount());
        self::assertSame(2, $graph->edgeCount());
    }

    #[Test]
    public function multiLineLabelBecomesTitleAndBody(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                db [label="Database\nPostgreSQL\nPort 5432"];
            }
            DOT);

        $node = $graph->getNode('db');
        self::assertSame('Database', $node->title);
        self::assertSame(['PostgreSQL', 'Port 5432'], $node->body);
    }

    #[Test]
    public function acceptsStrictDigraphWithName(): void
    {
        $graph = (new DotParser())->parse('strict digraph dependencies { a -> b; }');

        self::assertSame(2, $graph->nodeCount());
        self::assertSame(1, $graph->edgeCount());
    }

    #[Test]
    public function defaultAttributeStatementsDoNotCreatePhantomNodes(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                compound = "true";
                newrank = "true";
                graph [fontname="Helvetica"];
                node [shape=box, style=filled];
                edge [color=red];
                a -> b;
            }
            DOT);

        self::assertSame(2, $graph->nodeCount());
        self::assertFalse(isset($graph->nodes()['node']));
        self::assertFalse(isset($graph->nodes()['compound']));
    }

    #[Test]
    public function nodeDefaultsApplyToSubsequentlyDefinedNodes(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                a;
                node [label="Default Title"];
                b;
            }
            DOT);

        self::assertSame('a', $graph->getNode('a')->title);
        self::assertSame('Default Title', $graph->getNode('b')->title);
    }

    #[Test]
    public function flattensSubgraphsIntoParentGraph(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                subgraph "root" {
                    "[root] a" [label = "module.a", shape = "box"];
                    "[root] b" [label = "module.b", shape = "box"];
                    "[root] a" -> "[root] b";
                    subgraph cluster_inner {
                        c -> d;
                    }
                }
                { e; }
            }
            DOT);

        self::assertSame(5, $graph->nodeCount());
        self::assertSame(2, $graph->edgeCount());
        self::assertSame('module.a', $graph->getNode('[root] a')->title);
        self::assertSame(['[root] b'], $graph->successors('[root] a'));
    }

    #[Test]
    public function discardsPortsAndAcceptsNumericIds(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                a:out -> b:in:ne;
                1.5 -> 2;
            }
            DOT);

        self::assertSame(['b'], $graph->successors('a'));
        self::assertSame(['2'], $graph->successors('1.5'));
    }

    #[Test]
    public function htmlLikeLabelsAreStrippedToPlainText(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                a [label=<<B>Place One</B><BR/>token &amp; more>];
            }
            DOT);

        $node = $graph->getNode('a');
        self::assertSame('Place One', $node->title);
        self::assertSame(['token & more'], $node->body);
    }

    #[Test]
    public function nonStrictDigraphPreservesParallelEdgesAndSelfLoops(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                a -> b;
                a -> b;
                c -> c;
            }
            DOT);

        self::assertSame(3, $graph->edgeCount());
        self::assertCount(1, $graph->selfLoops());
        self::assertSame('c', $graph->selfLoops()[0]->sourceId);
        self::assertSame(3, $graph->nodeCount());
    }

    #[Test]
    public function strictDigraphCoalescesParallelEdges(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            strict digraph {
                a -> b;
                a -> b;
            }
            DOT);

        self::assertSame(1, $graph->edgeCount());
    }

    #[Test]
    public function strictDedupDistinguishesEndpointsRegardlessOfConcatenationBoundary(): void
    {
        // Keys must separate source from target: "a\0bc" and "ab\0c" only stay
        // distinct because of the separator, and dropping either endpoint would
        // collide the same-target and same-source pairs below.
        $graph = (new DotParser())->parse(<<<'DOT'
            strict digraph {
                a -> bc;
                ab -> c;
                x -> m;
                y -> m;
                n -> p;
                n -> q;
            }
            DOT);

        self::assertSame(6, $graph->edgeCount());
    }

    #[Test]
    public function strictDedupKeepsLaterDistinctEdgesAfterCoalescingADuplicate(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            strict digraph {
                a -> b;
                a -> b;
                c -> d;
            }
            DOT);

        self::assertSame(2, $graph->edgeCount());
    }

    #[Test]
    public function laterStrictEdgeStatementUpdatesTheExistingEdgeAttributes(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            strict digraph {
                a -> b [style=dashed];
                a -> b [label=updated];
            }
            DOT);

        self::assertSame(1, $graph->edgeCount());
        self::assertSame(EdgeStrokeStyle::Dashed, $graph->edges()[0]->edgeStrokeStyle);
        self::assertSame('updated', $graph->edges()[0]->label?->text);
    }

    #[Test]
    public function exposesRankdirAsFlowDirection(): void
    {
        $parser = new DotParser();

        $parser->parse('digraph { rankdir=LR; a -> b; }');
        self::assertSame('LR', $parser->graphAttributes()['rankdir']);
        self::assertSame(FlowDirection::LeftToRight, $parser->flowDirection());

        $parser->parse('digraph { a -> b; }');
        self::assertSame(FlowDirection::TopToBottom, $parser->flowDirection());
    }

    #[Test]
    public function rejectsUndirectedGraph(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only directed graphs (digraph) are supported');

        (new DotParser())->parse('graph { a -- b; }');
    }

    #[Test]
    public function strictAndDigraphKeywordsAreCaseInsensitive(): void
    {
        $graph = (new DotParser())->parse('STRICT DIGRAPH dependencies { a -> b; }');

        self::assertSame(2, $graph->nodeCount());
        self::assertSame(1, $graph->edgeCount());
    }

    #[Test]
    public function rankdirRightToLeftFlowsLeftToRight(): void
    {
        $parser = new DotParser();
        $parser->parse('digraph { rankdir=RL; a -> b; }');

        self::assertSame(FlowDirection::LeftToRight, $parser->flowDirection());
    }

    #[Test]
    public function rankdirValueIsCaseInsensitive(): void
    {
        $parser = new DotParser();
        $parser->parse('digraph { rankdir=lr; a -> b; }');

        self::assertSame(FlowDirection::LeftToRight, $parser->flowDirection());
    }

    #[Test]
    public function graphAttributeNamesAreLowercased(): void
    {
        $parser = new DotParser();
        $parser->parse('digraph { RANKDIR=LR; a -> b; }');

        self::assertSame('LR', $parser->graphAttributes()['rankdir']);
        self::assertSame(FlowDirection::LeftToRight, $parser->flowDirection());
    }

    #[Test]
    public function defaultAttributeKeywordsAreCaseInsensitive(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                NODE [label="Styled"];
                b;
            }
            DOT);

        self::assertSame(1, $graph->nodeCount());
        self::assertSame('Styled', $graph->getNode('b')->title);
    }

    #[Test]
    public function subgraphKeywordIsCaseInsensitive(): void
    {
        $graph = (new DotParser())->parse('digraph { SUBGRAPH inner { a -> b; } }');

        self::assertSame(2, $graph->nodeCount());
        self::assertSame(['b'], $graph->successors('a'));
    }

    #[Test]
    public function trailingSemicolonsAfterSubgraphsAreSkipped(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                subgraph cluster { a; };
                { b; };
                c;
            }
            DOT);

        self::assertSame(3, $graph->nodeCount());
    }

    #[Test]
    public function rejectsSubgraphKeywordWithoutBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected "{"');

        (new DotParser())->parse('digraph { subgraph x; }');
    }

    #[Test]
    public function graphAttributeListMergesWithExistingGraphAttributes(): void
    {
        $parser = new DotParser();
        $parser->parse('digraph { rankdir=LR; graph [fontname="Helvetica"]; a; }');

        self::assertSame(
            ['rankdir' => 'LR', 'fontname' => 'Helvetica'],
            $parser->graphAttributes(),
        );
    }

    #[Test]
    public function nodeDefaultStatementsMerge(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                node [label="Default Title"];
                node [shape=box];
                b;
            }
            DOT);

        self::assertSame('Default Title', $graph->getNode('b')->title);
    }

    #[Test]
    public function edgeDefaultStatementsMergeAndApplyToEdges(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                edge [style=dashed];
                edge [color=red];
                a -> b;
            }
            DOT);

        self::assertSame(EdgeStrokeStyle::Dashed, $graph->edges()[0]->edgeStrokeStyle);
    }

    #[Test]
    public function duplicateEdgeDetectionDistinguishesSourceAndTarget(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                c -> c;
                a -> bc;
                ab -> c;
                a -> b;
                a -> c;
                b -> c;
            }
            DOT);

        self::assertSame(5, $graph->nodeCount());
        self::assertSame(6, $graph->edgeCount());
    }

    #[Test]
    public function quotedIdentifierLookingLikeASymbolIsTreatedAsNodeId(): void
    {
        $graph = (new DotParser())->parse('digraph { "{"; a -> b; }');

        self::assertSame(3, $graph->nodeCount());
        self::assertSame('{', $graph->getNode('{')->title);
    }

    #[Test]
    public function htmlLabelDecodesQuoteEntities(): void
    {
        $graph = (new DotParser())->parse('digraph { q [label=<say &quot;hi&quot; &amp; &apos;bye&apos;>]; }');

        self::assertSame('say "hi" & \'bye\'', $graph->getNode('q')->title);
    }

    #[Test]
    public function parsesDottedAndBoldEdgeStyles(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                a -> b [style=dotted];
                b -> c [style=bold];
            }
            DOT);

        self::assertSame(EdgeStrokeStyle::Dotted, $graph->edges()[0]->edgeStrokeStyle);
        self::assertSame(EdgeStrokeStyle::Heavy, $graph->edges()[1]->edgeStrokeStyle);
    }

    #[Test]
    public function rejectsMissingOpeningBrace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected "{"');

        (new DotParser())->parse('digraph name } x');
    }

    #[Test]
    public function rejectsUnexpectedCharacter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected character "*"');

        (new DotParser())->parse('digraph { a*b; }');
    }

    #[Test]
    public function rejectsDashThatIsNotAnArrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected character "-"');

        (new DotParser())->parse('digraph { a - b; }');
    }

    #[Test]
    public function parsesArrowWithoutSurroundingWhitespace(): void
    {
        $graph = (new DotParser())->parse('digraph {a->b;}');

        self::assertSame(['b'], $graph->successors('a'));
    }

    #[Test]
    public function lineCommentEndsExactlyAtNewline(): void
    {
        $graph = (new DotParser())->parse("digraph {\n# note\na -> b;\n}");

        self::assertSame(['b'], $graph->successors('a'));
    }

    #[Test]
    public function emptyBlockCommentIsSkipped(): void
    {
        $graph = (new DotParser())->parse('digraph { a /**/ -> b; }');

        self::assertSame(['b'], $graph->successors('a'));
    }

    #[Test]
    public function blockCommentMayStartWithASlash(): void
    {
        $graph = (new DotParser())->parse('digraph { a /*/ note */ -> b; }');

        self::assertSame(['b'], $graph->successors('a'));
    }

    #[Test]
    public function adjacentBlockCommentsAreSkippedSeparately(): void
    {
        $graph = (new DotParser())->parse('digraph { a /*x*//*y*/ -> b; }');

        self::assertSame(['b'], $graph->successors('a'));
    }

    #[Test]
    public function rejectsUnterminatedQuotedString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unterminated quoted string');

        (new DotParser())->parse('digraph { "abc');
    }

    #[Test]
    public function rejectsUnterminatedHtmlLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unterminated HTML-like label');

        (new DotParser())->parse('digraph { a [label=<<b>x');
    }

    #[Test]
    public function syntaxErrorsReportLineAndColumn(): void
    {
        try {
            (new DotParser())->parse("digraph {\n    a -> b;\n    c -> ;\n}");
            self::fail('Expected DotSyntaxException');
        } catch (DotSyntaxException $exception) {
            self::assertSame(3, $exception->sourceLine);
            self::assertStringContainsString('line 3', $exception->getMessage());
        }
    }

    #[Test]
    public function unexpectedCharacterReportsLineAndColumn(): void
    {
        try {
            (new DotParser())->parse("digraph {\n    a -> b %\n}");
            self::fail('Expected DotSyntaxException');
        } catch (DotSyntaxException $exception) {
            self::assertSame(2, $exception->sourceLine);
            self::assertSame(12, $exception->sourceColumn);
        }
    }

    #[Test]
    public function dotSyntaxExceptionRemainsAnInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DotParser())->parse('graph { a -- b; }');
    }

    #[Test]
    public function clusterSubgraphBecomesAGroup(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                push -> lint;
                subgraph cluster_quality {
                    label = "Quality";
                    lint -> test;
                }
                test -> deploy;
            }
            DOT);

        self::assertCount(1, $graph->groups());
        $group = $graph->groups()[0];
        self::assertSame('Quality', $group->label);
        self::assertSame(['lint', 'test'], $group->nodeIds);
        self::assertSame(4, $graph->nodeCount(), 'Clustered nodes still flatten into the graph');
        self::assertSame(3, $graph->edgeCount());
    }

    #[Test]
    public function nonClusterSubgraphProducesNoGroup(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                subgraph "root" {
                    a -> b;
                }
            }
            DOT);

        self::assertSame([], $graph->groups());
        self::assertSame(2, $graph->nodeCount());
    }

    #[Test]
    public function clusterLabelDefaultsToNameWithoutClusterPrefix(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                subgraph cluster_backend {
                    api -> db;
                }
            }
            DOT);

        self::assertSame('backend', $graph->groups()[0]->label);
    }

    #[Test]
    public function inClusterLabelAttributeIsCaseInsensitive(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                subgraph cluster_x {
                    LABEL = "Shiny";
                    a -> b;
                }
            }
            DOT);

        self::assertSame('Shiny', $graph->groups()[0]->label);
    }

    #[Test]
    public function topLevelLabelIsAGraphAttributeNotAGroup(): void
    {
        $parser = new DotParser();
        $graph = $parser->parse('digraph { label = "My Graph"; a -> b; }');

        self::assertSame('My Graph', $parser->graphAttributes()['label']);
        self::assertSame([], $graph->groups());
    }

    #[Test]
    public function undirectedGraphErrorReportsFirstColumn(): void
    {
        try {
            (new DotParser())->parse('graph { a -- b; }');
            self::fail('Expected DotSyntaxException');
        } catch (DotSyntaxException $exception) {
            self::assertSame(1, $exception->sourceLine);
            self::assertSame(1, $exception->sourceColumn);
        }
    }

    #[Test]
    public function singleLineSyntaxErrorReportsColumn(): void
    {
        try {
            (new DotParser())->parse('digraph { a -> }');
            self::fail('Expected DotSyntaxException');
        } catch (DotSyntaxException $exception) {
            self::assertSame(1, $exception->sourceLine);
            self::assertSame(16, $exception->sourceColumn, 'The "}" sits at column 16 on the only line');
        }
    }

    #[Test]
    public function rejectsTokensAfterClosingBrace(): void
    {
        $this->expectException(DotSyntaxException::class);
        $this->expectExceptionMessage('Unexpected token "trailing" after end of graph');

        (new DotParser())->parse('digraph { a; } trailing');
    }

    #[Test]
    public function rejectsASecondGraphAfterTheFirst(): void
    {
        $this->expectException(DotSyntaxException::class);

        (new DotParser())->parse('digraph { a; } digraph { b; }');
    }

    #[Test]
    public function nodeBelongsToTheClusterThatFirstDefinedIt(): void
    {
        $graph = (new DotParser())->parse(<<<'DOT'
            digraph {
                subgraph cluster_a {
                    x -> y;
                }
                subgraph cluster_b {
                    x -> z;
                }
            }
            DOT);

        self::assertSame(['x', 'y'], $graph->groups()[0]->nodeIds);
        self::assertSame(['z'], $graph->groups()[1]->nodeIds, 'x already belongs to cluster_a; cluster_b only claims z');
    }
}
