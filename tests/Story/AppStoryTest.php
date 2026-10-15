<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Story\AppStory;
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
