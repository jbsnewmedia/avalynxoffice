<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Registry;

use App\Entity\CalendarEvent;
use JBSNewMedia\MailActionBundle\Contract\CalendarActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;
use JBSNewMedia\MailActionBundle\Registry\CalendarActionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class CalendarActionRegistryTest extends TestCase
{
    public function testGetActionsSkipsNonMatchingPluginsAndNullViews(): void
    {
        $validPlugin = new class implements CalendarActionPluginInterface {
            public function build(CalendarEvent $event): ?TemplateView
            {
                return new TemplateView('calendar_action.html.twig', ['event' => $event]);
            }
        };

        $nullPlugin = new class implements CalendarActionPluginInterface {
            public function build(CalendarEvent $event): ?TemplateView
            {
                return null;
            }
        };

        $event = new CalendarEvent();
        $registry = new CalendarActionRegistry(
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

        $actions = $registry->getActions($event);

        self::assertCount(1, $actions);
        self::assertSame('calendar_action.html.twig', $actions[0]->getTemplate());
        self::assertSame(['event' => $event], $actions[0]->getContext());
    }
}
