<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use InvalidArgumentException;
use PhpDag\Graph\Label;
use PhpDag\Graph\LabelPosition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LabelTest extends TestCase
{
    #[Test]
    public function rejectsControlCharactersInText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Label("yes\nno");
    }

    #[Test]
    public function rejectsC1ControlCharactersInText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Label("yes\u{009B}no");
    }

    #[Test]
    public function defaultsToMiddlePosition(): void
    {
        $label = new Label('yes');
        self::assertSame('yes', $label->text);
        self::assertSame(LabelPosition::Middle, $label->position);
    }

    #[Test]
    public function acceptsExplicitPosition(): void
    {
        $label = new Label('no', LabelPosition::Source);
        self::assertSame('no', $label->text);
        self::assertSame(LabelPosition::Source, $label->position);
    }

    #[Test]
    public function calculatesAsciiWidth(): void
    {
        self::assertSame(3, (new Label('yes'))->width());
    }

    #[Test]
    public function calculatesMultibyteWidth(): void
    {
        self::assertSame(4, (new Label('日本'))->width());
    }
}
