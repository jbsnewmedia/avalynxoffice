<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\MailActionBundle\DependencyInjection\Compiler;

use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\CalendarActionCompilerPass;
use JBSNewMedia\MailActionBundle\DependencyInjection\MailActionTags;
use JBSNewMedia\MailActionBundle\Registry\CalendarActionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class CalendarActionCompilerPassTest extends TestCase
{
    public function testProcessWiresTaggedPluginsIntoRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(CalendarActionRegistry::class, new Definition(CalendarActionRegistry::class));
        $container->setDefinition('action.low', (new Definition(\stdClass::class))->addTag(MailActionTags::CALENDAR_ACTION, ['id' => 'mail.calendar.action.delete', 'priority' => 0, 'position' => 10]));
        $container->setDefinition('action.high', (new Definition(\stdClass::class))->addTag(MailActionTags::CALENDAR_ACTION, ['id' => 'mail.calendar.action.delete', 'priority' => 1, 'position' => 20]));
        $container->setDefinition('action.other', (new Definition(\stdClass::class))->addTag(MailActionTags::CALENDAR_ACTION, ['id' => 'mail.calendar.action.edit', 'priority' => 0, 'position' => 30]));

        (new CalendarActionCompilerPass())->process($container);

        $definition = $container->getDefinition(CalendarActionRegistry::class);
        self::assertNotNull($definition->getArgument('$pluginLocator'));
        self::assertSame([
            ['id' => 'mail.calendar.action.edit', 'serviceId' => 'action.other', 'priority' => 0, 'position' => 30],
            ['id' => 'mail.calendar.action.delete', 'serviceId' => 'action.high', 'priority' => 1, 'position' => 20],
        ], $definition->getArgument('$entries'));
    }

    public function testProcessSkipsMalformedTags(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(CalendarActionRegistry::class, new Definition(CalendarActionRegistry::class));
        $container->setDefinition('action.ok', (new Definition(\stdClass::class))->addTag(MailActionTags::CALENDAR_ACTION, ['id' => 'id.ok', 'priority' => 1, 'position' => 10]));
        $container->setDefinition('action.broken', (new Definition(\stdClass::class))->setTags([MailActionTags::CALENDAR_ACTION => ['not-an-array']]));

        (new CalendarActionCompilerPass())->process($container);

        $definition = $container->getDefinition(CalendarActionRegistry::class);
        self::assertSame([
            ['id' => 'id.ok', 'serviceId' => 'action.ok', 'priority' => 1, 'position' => 10],
        ], $definition->getArgument('$entries'));
    }

    public function testProcessSkipsWhenRegistryServiceIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new CalendarActionCompilerPass())->process($container);

        self::assertFalse($container->hasDefinition(CalendarActionRegistry::class));
    }

    public function testProcessKeepsHighestPriorityAndBreaksPositionTiesById(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(CalendarActionRegistry::class, new Definition(CalendarActionRegistry::class));
        $container->setDefinition('action.x.high', (new Definition(\stdClass::class))->addTag(MailActionTags::CALENDAR_ACTION, ['id' => 'id.x', 'priority' => 5, 'position' => 10]));
        $container->setDefinition('action.x.low', (new Definition(\stdClass::class))->addTag(MailActionTags::CALENDAR_ACTION, ['id' => 'id.x', 'priority' => 3, 'position' => 10]));
        $container->setDefinition('action.y.first', (new Definition(\stdClass::class))->addTag(MailActionTags::CALENDAR_ACTION, ['id' => 'id.y', 'priority' => 2, 'position' => 10]));
        $container->setDefinition('action.y.equal', (new Definition(\stdClass::class))->addTag(MailActionTags::CALENDAR_ACTION, ['id' => 'id.y', 'priority' => 2, 'position' => 10]));

        (new CalendarActionCompilerPass())->process($container);

        $definition = $container->getDefinition(CalendarActionRegistry::class);
        self::assertSame([
            ['id' => 'id.x', 'serviceId' => 'action.x.high', 'priority' => 5, 'position' => 10],
            ['id' => 'id.y', 'serviceId' => 'action.y.first', 'priority' => 2, 'position' => 10],
        ], $definition->getArgument('$entries'));
    }
}
