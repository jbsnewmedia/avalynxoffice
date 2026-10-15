<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Plugin;

use App\Entity\Address;
use JBSNewMedia\MailActionBundle\Plugin\ContactAction\ContactDeleteActionPlugin;
use JBSNewMedia\MailActionBundle\Plugin\ContactAction\ContactEditActionPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactActionPluginsTest extends TestCase
{
    public static function provideContactActionPlugins(): iterable
    {
        yield [ContactEditActionPlugin::class, '@MailAction/mail_action/contact/actions/edit.html.twig'];
        yield [ContactDeleteActionPlugin::class, '@MailAction/mail_action/contact/actions/delete.html.twig'];
    }

    #[DataProvider('provideContactActionPlugins')]
    public function testContactActionPluginsRenderTheirTemplate(string $pluginClass, string $template): void
    {
        $contact = (new Address())->setEmail('jane@example.com');
        $view = (new $pluginClass())->build($contact);

        self::assertSame($template, $view?->getTemplate());
        self::assertSame(['contact' => $contact], $view?->getContext());
    }
}
