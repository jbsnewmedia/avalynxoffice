<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\MailActionBundle\DependencyInjection\Compiler;

use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\ContactActionCompilerPass;
use JBSNewMedia\MailActionBundle\DependencyInjection\MailActionTags;
use JBSNewMedia\MailActionBundle\Registry\ContactActionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ContactActionCompilerPassTest extends TestCase
{
    public function testProcessWiresTaggedPluginsIntoRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ContactActionRegistry::class, new Definition(ContactActionRegistry::class));
        $container->setDefinition('action.low', (new Definition(\stdClass::class))->addTag(MailActionTags::CONTACT_ACTION, ['id' => 'mail.contact.action.delete', 'priority' => 0, 'position' => 10]));
        $container->setDefinition('action.high', (new Definition(\stdClass::class))->addTag(MailActionTags::CONTACT_ACTION, ['id' => 'mail.contact.action.delete', 'priority' => 1, 'position' => 20]));
        $container->setDefinition('action.other', (new Definition(\stdClass::class))->addTag(MailActionTags::CONTACT_ACTION, ['id' => 'mail.contact.action.edit', 'priority' => 0, 'position' => 30]));

        (new ContactActionCompilerPass())->process($container);

        $definition = $container->getDefinition(ContactActionRegistry::class);
        self::assertNotNull($definition->getArgument('$pluginLocator'));
        self::assertSame([
            ['id' => 'mail.contact.action.edit', 'serviceId' => 'action.other', 'priority' => 0, 'position' => 30],
            ['id' => 'mail.contact.action.delete', 'serviceId' => 'action.high', 'priority' => 1, 'position' => 20],
        ], $definition->getArgument('$entries'));
    }

    public function testProcessSkipsMalformedTags(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ContactActionRegistry::class, new Definition(ContactActionRegistry::class));
        $container->setDefinition('action.ok', (new Definition(\stdClass::class))->addTag(MailActionTags::CONTACT_ACTION, ['id' => 'id.ok', 'priority' => 1, 'position' => 10]));
        $container->setDefinition('action.broken', (new Definition(\stdClass::class))->setTags([MailActionTags::CONTACT_ACTION => ['not-an-array']]));

        (new ContactActionCompilerPass())->process($container);

        $definition = $container->getDefinition(ContactActionRegistry::class);
        self::assertSame([
            ['id' => 'id.ok', 'serviceId' => 'action.ok', 'priority' => 1, 'position' => 10],
        ], $definition->getArgument('$entries'));
    }

    public function testProcessSkipsWhenRegistryServiceIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new ContactActionCompilerPass())->process($container);

        self::assertFalse($container->hasDefinition(ContactActionRegistry::class));
    }

    public function testProcessKeepsHighestPriorityAndBreaksPositionTiesById(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ContactActionRegistry::class, new Definition(ContactActionRegistry::class));
        $container->setDefinition('action.x.high', (new Definition(\stdClass::class))->addTag(MailActionTags::CONTACT_ACTION, ['id' => 'id.x', 'priority' => 5, 'position' => 10]));
        $container->setDefinition('action.x.low', (new Definition(\stdClass::class))->addTag(MailActionTags::CONTACT_ACTION, ['id' => 'id.x', 'priority' => 3, 'position' => 10]));
        $container->setDefinition('action.y.first', (new Definition(\stdClass::class))->addTag(MailActionTags::CONTACT_ACTION, ['id' => 'id.y', 'priority' => 2, 'position' => 10]));
        $container->setDefinition('action.y.equal', (new Definition(\stdClass::class))->addTag(MailActionTags::CONTACT_ACTION, ['id' => 'id.y', 'priority' => 2, 'position' => 10]));

        (new ContactActionCompilerPass())->process($container);

        $definition = $container->getDefinition(ContactActionRegistry::class);
        self::assertSame([
            ['id' => 'id.x', 'serviceId' => 'action.x.high', 'priority' => 5, 'position' => 10],
            ['id' => 'id.y', 'serviceId' => 'action.y.first', 'priority' => 2, 'position' => 10],
        ], $definition->getArgument('$entries'));
    }
}
