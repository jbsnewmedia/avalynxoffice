<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Registry;

use JBSNewMedia\MailActionBundle\Contract\ComposeButtonPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;
use JBSNewMedia\MailActionBundle\Registry\ComposeButtonRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ComposeButtonRegistryTest extends TestCase
{
    public function testGetButtonsSkipsNonMatchingPluginsAndNullViews(): void
    {
        $validPlugin = new class implements ComposeButtonPluginInterface {
            public function build(): ?TemplateView
            {
                return new TemplateView('valid.html.twig', ['name' => 'valid']);
            }
        };

        $nullPlugin = new class implements ComposeButtonPluginInterface {
            public function build(): ?TemplateView
            {
                return null;
            }
        };

        $registry = new ComposeButtonRegistry(
            new ServiceLocator([
                'valid' => static fn () => $validPlugin,
                'null' => static fn () => $nullPlugin,
                'other' => static fn () => new \stdClass(),
            ]),
            [
                ['id' => 'valid', 'serviceId' => 'valid', 'priority' => 0, 'position' => 10],
                ['id' => 'null', 'serviceId' => 'null', 'priority' => 0, 'position' => 5],
                ['id' => 'other', 'serviceId' => 'other', 'priority' => 0, 'position' => 1],
            ]
        );

        $buttons = $registry->getButtons();

        self::assertCount(1, $buttons);
        self::assertSame('valid.html.twig', $buttons[0]->getTemplate());
        self::assertSame(['name' => 'valid'], $buttons[0]->getContext());
    }
}
