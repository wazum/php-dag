<?php

declare(strict_types=1);

namespace PhpDag\Tests\Graph;

use InvalidArgumentException;
use PhpDag\Graph\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupTest extends TestCase
{
    #[Test]
    public function exposesIdLabelAndMembers(): void
    {
        $group = new Group('quality', 'Quality', ['lint', 'test']);

        self::assertSame('quality', $group->id);
        self::assertSame('Quality', $group->label);
        self::assertSame(['lint', 'test'], $group->nodeIds);
    }

    #[Test]
    public function rejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Group('', 'Quality', ['lint']);
    }

    #[Test]
    public function rejectsEmptyMembership(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Group('quality', 'Quality', []);
    }

    #[Test]
    public function rejectsControlCharactersInId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Group("quality\0", 'Quality', ['lint']);
    }

    #[Test]
    public function rejectsControlCharactersInLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Group('quality', "Quality\x1B", ['lint']);
    }

    #[Test]
    public function rejectsControlCharactersInMemberIds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Group('quality', 'Quality', ["lint\u{009B}"]);
    }

    #[Test]
    public function rejectsDuplicateMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Group('quality', 'Quality', ['lint', 'lint']);
    }
}
