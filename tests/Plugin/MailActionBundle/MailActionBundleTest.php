<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\MailActionBundle;

use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\CalendarActionCompilerPass;
use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\ComposeButtonCompilerPass;
use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\ContactActionCompilerPass;
use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\EmailActionCompilerPass;
use JBSNewMedia\MailActionBundle\MailActionBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MailActionBundleTest extends TestCase
{
    public function testBuildRegistersAllCompilerPasses(): void
    {
        $bundle = new MailActionBundle();
        $container = $this->createMock(ContainerBuilder::class);
        $passes = [];

        $container->expects(self::exactly(4))
            ->method('addCompilerPass')
            ->willReturnCallback(static function (object $pass) use (&$passes, $container): ContainerBuilder {
                $passes[] = $pass;

                return $container;
            });

        $bundle->build($container);

        self::assertContainsOnlyInstancesOf(\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface::class, $passes);
        self::assertTrue(self::containsInstanceOf($passes, ComposeButtonCompilerPass::class));
        self::assertTrue(self::containsInstanceOf($passes, EmailActionCompilerPass::class));
        self::assertTrue(self::containsInstanceOf($passes, ContactActionCompilerPass::class));
        self::assertTrue(self::containsInstanceOf($passes, CalendarActionCompilerPass::class));
    }

    /**
     * @param list<object> $passes
     */
    private static function containsInstanceOf(array $passes, string $class): bool
    {
        foreach ($passes as $pass) {
            if ($pass instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
