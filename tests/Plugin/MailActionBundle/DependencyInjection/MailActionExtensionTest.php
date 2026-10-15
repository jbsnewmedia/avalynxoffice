<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\DependencyInjection;

use JBSNewMedia\MailActionBundle\DependencyInjection\MailActionExtension;
use JBSNewMedia\MailActionBundle\Registry\ComposeButtonRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class MailActionExtensionTest extends TestCase
{
    public function testPrependDoesNothingWhenTwigExtensionIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new MailActionExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('twig'));
    }

    public function testPrependAddsTwigPathWhenTwigExtensionExists(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'twig';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });

        (new MailActionExtension())->prepend($container);

        $config = $container->getExtensionConfig('twig');

        $path = (string) array_key_first($config[0]['paths']);

        self::assertSame('MailAction', $config[0]['paths'][$path]);
        self::assertStringEndsWith('/plugins/jbsnewmedia/mail-action-bundle/templates', realpath($path) ?: $path);
    }

    public function testLoadRegistersBundleServices(): void
    {
        $container = new ContainerBuilder();

        (new MailActionExtension())->load([], $container);

        self::assertTrue($container->hasDefinition(ComposeButtonRegistry::class));
        self::assertTrue($container->hasDefinition(\JBSNewMedia\MailActionBundle\Controller\MailActionController::class));
    }
}
