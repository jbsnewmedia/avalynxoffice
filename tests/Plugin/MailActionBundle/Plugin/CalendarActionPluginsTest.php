<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\MailActionBundle\Plugin;

use AvalynxOffice\Entity\CalendarEvent;
use JBSNewMedia\MailActionBundle\Plugin\CalendarAction\CalendarDeleteActionPlugin;
use JBSNewMedia\MailActionBundle\Plugin\CalendarAction\CalendarEditActionPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarActionPluginsTest extends TestCase
{
    public static function provideCalendarActionPlugins(): iterable
    {
        yield [CalendarEditActionPlugin::class, '@MailAction/mail_action/calendar/actions/edit.html.twig'];
        yield [CalendarDeleteActionPlugin::class, '@MailAction/mail_action/calendar/actions/delete.html.twig'];
    }

    #[DataProvider('provideCalendarActionPlugins')]
    public function testCalendarActionPluginsRenderTheirTemplate(string $pluginClass, string $template): void
    {
        $event = (new CalendarEvent())
            ->setTitle('Team-Meeting')
            ->setStartAt(new \DateTimeImmutable('2026-03-10 10:00:00'));
        $view = (new $pluginClass())->build($event);

        self::assertSame($template, $view?->getTemplate());
        self::assertSame(['event' => $event, 'monthKey' => '2026-03'], $view?->getContext());
    }
}
