<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use PhpDag\Graph\Group;

/**
 * Repairs the layer order produced by crossing minimization so that each
 * group's members stay contiguous within every layer, and groups keep a
 * consistent left-to-right order across layers (so their column bands never
 * cross). Runs after CrossingMinimizer.
 *
 * Members of one group share a single sort key (the group's average normalized
 * position across all its members), so they always sort into one adjacent run;
 * ungrouped nodes sort by their own position and slot between the group blocks.
 * This is the ordering half of cluster layout; GroupSpacer reserves the ring.
 */
final readonly class GroupOrdering implements Processor
{
    public function process(LayoutGraph $graph): void
    {
        $groups = $graph->groups();
        if ([] === $groups) {
            /* @infection-ignore-all without groups every node is its own item keyed by its existing position, so reordering is the identity — the early return is only an optimization */
            return;
        }

        $groupOfNode = $this->groupOfNode($groups);
        $positions = $this->normalizedPositions($graph);
        $groupKey = $this->groupKeys($groupOfNode, $positions);

        foreach ($graph->layerIndex() as $layer => $nodeIds) {
            $graph->setLayerOrder($layer, $this->reorderLayer($nodeIds, $groupOfNode, $positions, $groupKey));
        }
    }

    /**
     * Rebuilds one layer as a list of indivisible items — each ungrouped node
     * is its own item, each group is a single block of its members — sorted by
     * their key. A group keeps its global key (consistent across layers); an
     * ungrouped node uses its local position. Ties put groups before singletons
     * and break by id, so the order is deterministic and bands never cross.
     *
     * @param list<string>          $nodeIds
     * @param array<string, string> $groupOfNode
     * @param array<string, float>  $positions
     * @param array<string, float>  $groupKey
     *
     * @return list<string>
     */
    private function reorderLayer(array $nodeIds, array $groupOfNode, array $positions, array $groupKey): array
    {
        /** @var array<string, list<string>> */
        $blockMembers = [];
        foreach ($nodeIds as $nodeId) {
            $group = $groupOfNode[$nodeId] ?? null;
            if (null !== $group) {
                $blockMembers[$group][] = $nodeId;
            }
        }

        // Each item is one indivisible run; rank 0 keeps groups before ungrouped
        // nodes on a key tie, and id makes ties between same-rank items stable.
        /** @var list<array{key: float, rank: int, id: string, members: list<string>}> */
        $items = [];
        $emittedGroups = [];
        foreach ($nodeIds as $nodeId) {
            $group = $groupOfNode[$nodeId] ?? null;
            if (null === $group) {
                /** @infection-ignore-all the rank only has to exceed the group rank (0) so groups win key ties; any positive value behaves identically */
                $items[] = ['key' => $positions[$nodeId], 'rank' => 1, 'id' => $nodeId, 'members' => [$nodeId]];
                continue;
            }
            if (!isset($emittedGroups[$group])) {
                /** @infection-ignore-all the flag is only read through isset(), so true vs false is equivalent */
                $emittedGroups[$group] = true;
                /** @infection-ignore-all the rank only has to stay below the singleton rank (1) so groups win key ties; 0 vs -1 behaves identically */
                $items[] = ['key' => $groupKey[$group], 'rank' => 0, 'id' => $group, 'members' => $blockMembers[$group]];
            }
        }

        usort($items, static function (array $leftItem, array $rightItem): int {
            return [$leftItem['key'], $leftItem['rank'], $leftItem['id']] <=> [$rightItem['key'], $rightItem['rank'], $rightItem['id']];
        });

        return array_merge(...array_column($items, 'members'));
    }

    /**
     * @param list<Group> $groups
     *
     * @return array<string, string> node id => group id (first group wins)
     */
    private function groupOfNode(array $groups): array
    {
        $groupOfNode = [];
        foreach ($groups as $group) {
            foreach ($group->nodeIds as $nodeId) {
                /** @infection-ignore-all groups are disjoint in supported usage; first-wins is a defensive choice that ??= and = render identically */
                $groupOfNode[$nodeId] ??= $group->id;
            }
        }

        return $groupOfNode;
    }

    /**
     * Each node's position within its layer as a fraction in (0, 1), comparable
     * across layers of different widths.
     *
     * @return array<string, float>
     */
    private function normalizedPositions(LayoutGraph $graph): array
    {
        $positions = [];
        foreach ($graph->layerIndex() as $nodeIds) {
            $count = count($nodeIds);
            foreach ($nodeIds as $index => $nodeId) {
                /** @infection-ignore-all the casts only satisfy strict-operand typing (PHP coerces to the same value) and the divisor is constant within a layer, so neither casts nor operator change a node's order within its layer */
                $positions[$nodeId] = ((float) $index + 0.5) / (float) $count;
            }
        }

        return $positions;
    }

    /**
     * @param array<string, string> $groupOfNode
     * @param array<string, float>  $positions
     *
     * @return array<string, float> group id => average normalized position of its members
     */
    private function groupKeys(array $groupOfNode, array $positions): array
    {
        $sum = [];
        $count = [];
        foreach ($groupOfNode as $nodeId => $group) {
            if (!isset($positions[$nodeId])) {
                continue;
            }
            $sum[$group] = ($sum[$group] ?? 0.0) + $positions[$nodeId];
            $count[$group] = ($count[$group] ?? 0) + 1;
        }

        $keys = [];
        foreach ($sum as $group => $total) {
            /** @infection-ignore-all the cast only satisfies strict-operand typing; PHP divides float by int to the same value */
            $keys[$group] = $total / (float) $count[$group];
        }

        return $keys;
    }
}
