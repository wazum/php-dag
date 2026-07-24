<?php

declare(strict_types=1);

namespace PhpDag\Render;

use InvalidArgumentException;
use PhpDag\Style\AnsiColor;
use PhpDag\Style\EdgeStrokeStyle;

/**
 * A single canvas cell. Overlapping edges are folded into aggregate state as
 * they are marked — the OR of every direction bit, the highest-priority stroke,
 * and that stroke's colour — rather than one object per edge, so a shared fan
 * trunk crossing thousands of cells costs a handful of scalars per cell instead
 * of an array of layer objects. The winning edge id is kept so a later mark from
 * the same edge can refresh its colour while a crossing edge of equal priority
 * cannot override it.
 */
final class Cell
{
    private string $character = ' ';
    private int $combinedBits = 0;
    private ?EdgeStrokeStyle $strokeStyle = null;
    private ?AnsiColor $edgeColor = null;
    private int $strokeEdgeId = 0;
    private int $zIndex = 0;
    private ?AnsiColor $color = null;

    public function __construct(
        private readonly bool $unicodeGlyphs = true,
    ) {
    }

    public function markEdgePassthrough(int $bits, int $edgeId, EdgeStrokeStyle $strokeStyle, int $zIndex, ?AnsiColor $color = null): void
    {
        if ($bits <= 0 || $bits !== ($bits & Direction::INTERSECTION)) {
            throw new InvalidArgumentException(sprintf('Invalid direction bitmask: %d. Must be a combination of Direction::UP, RIGHT, DOWN, LEFT.', $bits));
        }

        if ($zIndex > $this->zIndex) {
            $this->combinedBits = $bits;
            $this->strokeStyle = $strokeStyle;
            $this->edgeColor = $color;
            $this->strokeEdgeId = $edgeId;
            $this->zIndex = $zIndex;

            /** @infection-ignore-all Early return is an optimization; removing it is semantically equivalent */
            return;
        }

        if ($zIndex === $this->zIndex) {
            $this->combinedBits |= $bits;
            $this->foldStroke($edgeId, $strokeStyle, $color);
        }
    }

    private function foldStroke(int $edgeId, EdgeStrokeStyle $strokeStyle, ?AnsiColor $color): void
    {
        if (null === $this->strokeStyle || $strokeStyle->priority() > $this->strokeStyle->priority()) {
            $this->strokeStyle = $strokeStyle;
            $this->edgeColor = $color;
            $this->strokeEdgeId = $edgeId;

            /** @infection-ignore-all removing this return is equivalent: the fallthrough re-enters the equal-priority/same-edge branch (both now true) and re-assigns edgeColor to the same value */
            return;
        }

        if ($strokeStyle->priority() !== $this->strokeStyle->priority()) {
            return;
        }

        // A colored (highlighted) edge overrides an uncolored default edge, so a
        // highlighted path stays continuous across the trunk and crossings it
        // shares with plain edges instead of breaking wherever a default edge
        // was drawn first.
        if (null === $this->edgeColor && null !== $color) {
            $this->strokeStyle = $strokeStyle;
            $this->edgeColor = $color;
            $this->strokeEdgeId = $edgeId;

            /** @infection-ignore-all Early return is an optimization; the guards below are false once a colour is set */
            return;
        }

        // Same edge re-marking (e.g. entry glyph then passthrough) refreshes its
        // colour; a crossing edge of equal priority keeps the first colour.
        if ($edgeId === $this->strokeEdgeId) {
            $this->edgeColor = $color ?? $this->edgeColor;
        }
    }

    public function putCharacter(string $character, int $zIndex, ?AnsiColor $color = null): void
    {
        if ($zIndex >= $this->zIndex) {
            $this->character = $character;
            /** @infection-ignore-all Resetting to 0/null behaves identically: resolvedCharacter checks for no edges */
            $this->combinedBits = 0;
            $this->strokeStyle = null;
            $this->zIndex = $zIndex;
            $this->color = $color;
        }
    }

    public function resolvedCharacter(): string
    {
        if (null === $this->strokeStyle) {
            return $this->character;
        }

        return $this->strokeStyle->glyphs($this->unicodeGlyphs)->junctionFor($this->combinedBits);
    }

    public function wouldAcceptWrite(int $zIndex): bool
    {
        return $zIndex >= $this->zIndex;
    }

    public function resolvedColor(): ?AnsiColor
    {
        return null === $this->strokeStyle ? $this->color : $this->edgeColor;
    }
}
