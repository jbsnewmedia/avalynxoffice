<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Plugin;

use JBSNewMedia\MailActionBundle\Plugin\DefaultComposeButtonPlugin;
use PHPUnit\Framework\TestCase;

final class DefaultComposeButtonPluginTest extends TestCase
{
    public function testBuildReturnsDefaultComposeButtonTemplate(): void
    {
        $plugin = new DefaultComposeButtonPlugin();
        $view = $plugin->build();

        self::assertSame('@MailAction/mail_action/compose/default_compose_button.html.twig', $view?->getTemplate());
        self::assertSame([], $view?->getContext());
    }
}
