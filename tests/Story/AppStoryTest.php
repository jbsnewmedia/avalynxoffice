<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Story;

use AvalynxOffice\Story\AppStory;
use PHPUnit\Framework\TestCase;
use Zenstruck\Foundry\Attribute\AsFixture;

final class AppStoryTest extends TestCase
{
    public function testBuildIsCallableAndFixtureAttributeIsPresent(): void
    {
        $story = new AppStory();
        $story->build();

        $attributes = (new \ReflectionClass(AppStory::class))->getAttributes(AsFixture::class);

        self::assertCount(1, $attributes);
        self::assertSame('main', $attributes[0]->newInstance()->name);
    }
}
