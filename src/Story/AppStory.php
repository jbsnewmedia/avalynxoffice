<?php

declare(strict_types=1);

namespace App\Story;

use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    #[\Override]
    public function build(): void
    {
    }
}
