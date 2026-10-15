<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\DependencyInjection\Compiler;

use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\ComposeButtonCompilerPass;
use JBSNewMedia\MailActionBundle\DependencyInjection\MailActionTags;
use JBSNewMedia\MailActionBundle\Registry\ComposeButtonRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ComposeButtonCompilerPassTest extends TestCase
{
    public function testProcessWiresTaggedPluginsIntoRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ComposeButtonRegistry::class, new Definition(ComposeButtonRegistry::class));
        $container->setDefinition('button.low', (new Definition(\stdClass::class))->addTag(MailActionTags::COMPOSE_BUTTON, ['id' => 'mail.compose.default_new_email', 'priority' => 0, 'position' => 50]));
        $container->setDefinition('button.high', (new Definition(\stdClass::class))->addTag(MailActionTags::COMPOSE_BUTTON, ['id' => 'mail.compose.default_new_email', 'priority' => 1, 'position' => 40]));
        $container->setDefinition('button.other', (new Definition(\stdClass::class))->addTag(MailActionTags::COMPOSE_BUTTON, ['id' => 'mail.compose.secondary', 'priority' => 0, 'position' => 60]));

        (new ComposeButtonCompilerPass())->process($container);

        $definition = $container->getDefinition(ComposeButtonRegistry::class);
        self::assertNotNull($definition->getArgument('$pluginLocator'));
        self::assertSame([
            ['id' => 'mail.compose.secondary', 'serviceId' => 'button.other', 'priority' => 0, 'position' => 60],
            ['id' => 'mail.compose.default_new_email', 'serviceId' => 'button.high', 'priority' => 1, 'position' => 40],
        ], $definition->getArgument('$entries'));
    }

    public function testProcessSkipsMalformedTags(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ComposeButtonRegistry::class, new Definition(ComposeButtonRegistry::class));
        $container->setDefinition('button.ok', (new Definition(\stdClass::class))->addTag(MailActionTags::COMPOSE_BUTTON, ['id' => 'id.ok', 'priority' => 1, 'position' => 10]));
        $container->setDefinition('button.broken', (new Definition(\stdClass::class))->setTags([MailActionTags::COMPOSE_BUTTON => ['not-an-array']]));

        (new ComposeButtonCompilerPass())->process($container);

        $definition = $container->getDefinition(ComposeButtonRegistry::class);
        self::assertSame([
            ['id' => 'id.ok', 'serviceId' => 'button.ok', 'priority' => 1, 'position' => 10],
        ], $definition->getArgument('$entries'));
    }

    public function testProcessSkipsWhenRegistryServiceIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new ComposeButtonCompilerPass())->process($container);

        self::assertFalse($container->hasDefinition(ComposeButtonRegistry::class));
    }

    public function testProcessKeepsHighestPriorityAndBreaksPositionTiesById(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ComposeButtonRegistry::class, new Definition(ComposeButtonRegistry::class));
        $container->setDefinition('button.x.high', (new Definition(\stdClass::class))->addTag(MailActionTags::COMPOSE_BUTTON, ['id' => 'id.x', 'priority' => 5, 'position' => 10]));
        $container->setDefinition('button.x.low', (new Definition(\stdClass::class))->addTag(MailActionTags::COMPOSE_BUTTON, ['id' => 'id.x', 'priority' => 3, 'position' => 10]));
        $container->setDefinition('button.y.first', (new Definition(\stdClass::class))->addTag(MailActionTags::COMPOSE_BUTTON, ['id' => 'id.y', 'priority' => 2, 'position' => 10]));
        $container->setDefinition('button.y.equal', (new Definition(\stdClass::class))->addTag(MailActionTags::COMPOSE_BUTTON, ['id' => 'id.y', 'priority' => 2, 'position' => 10]));

        (new ComposeButtonCompilerPass())->process($container);

        $definition = $container->getDefinition(ComposeButtonRegistry::class);
        self::assertSame([
            ['id' => 'id.x', 'serviceId' => 'button.x.high', 'priority' => 5, 'position' => 10],
            ['id' => 'id.y', 'serviceId' => 'button.y.first', 'priority' => 2, 'position' => 10],
        ], $definition->getArgument('$entries'));
    }
}
