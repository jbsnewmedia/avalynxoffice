<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Email;
use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class PluginFallbackExtensionTest extends TestCase
{
    public function testComposeButtonFunctionRendersViews(): void
    {
        $twig = new Environment(new ArrayLoader([
            'template' => '{% set composeButtonViews = mail_compose_button_views() %}{% for buttonView in composeButtonViews %}{% include buttonView.template with buttonView.context only %}{% endfor %}',
            'compose' => 'compose-button',
        ]));
        $twig->addExtension(new AppExtension(new ServiceLocator([
            'JBSNewMedia\\MailActionBundle\\Registry\\ComposeButtonRegistry' => static fn (): object => new class {
                public function getButtons(): array
                {
                    return [
                        new \JBSNewMedia\MailActionBundle\Model\TemplateView('compose'),
                    ];
                }
            },
        ])));

        self::assertSame('compose-button', $twig->render('template'));
    }

    public function testEmailActionFunctionRendersViews(): void
    {
        $twig = new Environment(new ArrayLoader([
            'template' => '{% set emailActionViews = mail_email_action_views(email) %}{% for actionView in emailActionViews %}{% include actionView.template with actionView.context only %}{% endfor %}',
            'action' => 'action-button',
        ]));
        $twig->addExtension(new AppExtension(new ServiceLocator([
            'JBSNewMedia\\MailActionBundle\\Registry\\EmailActionRegistry' => static fn (): object => new class {
                public function getActions(Email $email): array
                {
                    return [
                        new \JBSNewMedia\MailActionBundle\Model\TemplateView('action', ['email' => $email]),
                    ];
                }
            },
        ])));

        self::assertSame('action-button', $twig->render('template', ['email' => new Email()]));
    }

    public function testContactActionFunctionRendersViews(): void
    {
        $twig = new Environment(new ArrayLoader([
            'template' => '{% set contactActionViews = mail_contact_action_views(contact) %}{% for actionView in contactActionViews %}{% include actionView.template with actionView.context only %}{% endfor %}',
            'contact_action' => 'contact-action-button',
        ]));
        $twig->addExtension(new AppExtension(new ServiceLocator([
            'JBSNewMedia\\MailActionBundle\\Registry\\ContactActionRegistry' => static fn (): object => new class {
                public function getActions(\App\Entity\Address $contact): array
                {
                    return [
                        new \JBSNewMedia\MailActionBundle\Model\TemplateView('contact_action', ['contact' => $contact]),
                    ];
                }
            },
        ])));

        self::assertSame('contact-action-button', $twig->render('template', ['contact' => new \App\Entity\Address()]));
    }
}
