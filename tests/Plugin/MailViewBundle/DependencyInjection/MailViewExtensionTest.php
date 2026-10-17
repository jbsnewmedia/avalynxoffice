<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\MailViewBundle\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class MailViewExtensionTest extends TestCase
{
    public function testPrependDoesNothingWhenTwigExtensionIsMissing(): void
    {
        self::requirePluginClasses();
        $container = new ContainerBuilder();

        (new \JBSNewMedia\MailViewBundle\DependencyInjection\MailViewExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('twig'));
    }

    public function testPrependAddsTwigPathWhenTwigExtensionExists(): void
    {
        self::requirePluginClasses();
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

        (new \JBSNewMedia\MailViewBundle\DependencyInjection\MailViewExtension())->prepend($container);

        $config = $container->getExtensionConfig('twig');
        $path = (string) array_key_first($config[0]['paths']);

        self::assertSame('MailView', $config[0]['paths'][$path]);
        self::assertStringEndsWith('/plugins/jbsnewmedia/mail-view-bundle/templates', realpath($path) ?: $path);
    }

    public function testLoadRegistersBundleServices(): void
    {
        self::requirePluginClasses();
        $container = new ContainerBuilder();

        (new \JBSNewMedia\MailViewBundle\DependencyInjection\MailViewExtension())->load([], $container);

        self::assertTrue($container->hasDefinition(\JBSNewMedia\MailViewBundle\View\EmailDetailTemplateProvider::class));
    }

    private static function requirePluginClasses(): void
    {
        require_once \dirname(__DIR__, 4).'/plugins/jbsnewmedia/mail-view-bundle/src/DependencyInjection/MailViewExtension.php';
        require_once \dirname(__DIR__, 4).'/plugins/jbsnewmedia/mail-view-bundle/src/View/EmailDetailTemplateProvider.php';
    }
}
