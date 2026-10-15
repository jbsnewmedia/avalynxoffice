<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailViewBundle;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MailViewBundleTest extends TestCase
{
    public function testBundleExposesExpectedExtensionAndServices(): void
    {
        self::requirePluginClasses();
        $bundle = new \JBSNewMedia\MailViewBundle\MailViewBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(Bundle::class, $bundle);
        self::assertInstanceOf(\JBSNewMedia\MailViewBundle\DependencyInjection\MailViewExtension::class, $extension);
        self::assertSame('@MailView/mail/_email_detail.html.twig', (new \JBSNewMedia\MailViewBundle\View\EmailDetailTemplateProvider())->getTemplate());
    }

    private static function requirePluginClasses(): void
    {
        require_once \dirname(__DIR__, 3).'/plugins/jbsnewmedia/mail-view-bundle/src/View/EmailDetailTemplateProvider.php';
        require_once \dirname(__DIR__, 3).'/plugins/jbsnewmedia/mail-view-bundle/src/DependencyInjection/MailViewExtension.php';
        require_once \dirname(__DIR__, 3).'/plugins/jbsnewmedia/mail-view-bundle/src/MailViewBundle.php';
    }
}
