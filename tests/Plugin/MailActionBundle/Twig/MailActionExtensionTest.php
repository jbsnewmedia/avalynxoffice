<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Twig;

use App\Entity\Address;
use App\Entity\CalendarEvent;
use App\Entity\Email;
use JBSNewMedia\MailActionBundle\Contract\CalendarActionPluginInterface;
use JBSNewMedia\MailActionBundle\Contract\ComposeButtonPluginInterface;
use JBSNewMedia\MailActionBundle\Contract\ContactActionPluginInterface;
use JBSNewMedia\MailActionBundle\Contract\EmailActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;
use JBSNewMedia\MailActionBundle\Registry\CalendarActionRegistry;
use JBSNewMedia\MailActionBundle\Registry\ComposeButtonRegistry;
use JBSNewMedia\MailActionBundle\Registry\ContactActionRegistry;
use JBSNewMedia\MailActionBundle\Registry\EmailActionRegistry;
use JBSNewMedia\MailActionBundle\Twig\MailActionExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MailActionExtensionTest extends TestCase
{
    public function testGetFunctionsExposesAllTwigFunctions(): void
    {
        $extension = new MailActionExtension(
            new ComposeButtonRegistry(new ServiceLocator([]), []),
            new EmailActionRegistry(new ServiceLocator([]), []),
            new ContactActionRegistry(new ServiceLocator([]), []),
            new CalendarActionRegistry(new ServiceLocator([]), []),
        );

        $functions = $extension->getFunctions();

        self::assertCount(4, $functions);
        self::assertSame('mail_compose_button_views', $functions[0]->getName());
        self::assertSame('mail_email_action_views', $functions[1]->getName());
        self::assertSame('mail_contact_action_views', $functions[2]->getName());
        self::assertSame('mail_calendar_action_views', $functions[3]->getName());
    }

    public function testDelegatesToRegistries(): void
    {
        $composeButtons = [new TemplateView('compose.html.twig')];
        $email = new Email();
        $contact = new Address();
        $actions = [new TemplateView('action.html.twig', ['email' => $email])];
        $contactActions = [new TemplateView('contact_action.html.twig', ['contact' => $contact])];
        $composeView = $composeButtons[0];
        $actionView = $actions[0];
        $contactActionView = $contactActions[0];

        $composeRegistry = new ComposeButtonRegistry(
            new ServiceLocator([
                'compose' => static fn () => new class($composeView) implements ComposeButtonPluginInterface {
                    public function __construct(private TemplateView $view)
                    {
                    }

                    public function build(): ?TemplateView
                    {
                        return $this->view;
                    }
                },
            ]),
            [
                ['id' => 'compose', 'serviceId' => 'compose', 'priority' => 0, 'position' => 10],
            ],
        );

        $actionRegistry = new EmailActionRegistry(
            new ServiceLocator([
                'action' => static fn () => new class($actionView) implements EmailActionPluginInterface {
                    public function __construct(
                        private TemplateView $view,
                    ) {
                    }

                    public function build(Email $email): ?TemplateView
                    {
                        return $this->view;
                    }
                },
            ]),
            [
                ['id' => 'action', 'serviceId' => 'action', 'priority' => 0, 'position' => 10],
            ],
        );

        $contactRegistry = new ContactActionRegistry(
            new ServiceLocator([
                'contact_action' => static fn () => new class($contactActionView) implements ContactActionPluginInterface {
                    public function __construct(
                        private TemplateView $view,
                    ) {
                    }

                    public function build(Address $contact): ?TemplateView
                    {
                        return $this->view;
                    }
                },
            ]),
            [
                ['id' => 'contact_action', 'serviceId' => 'contact_action', 'priority' => 0, 'position' => 10],
            ],
        );

        $event = new CalendarEvent();
        $calendarActions = [new TemplateView('calendar_action.html.twig', ['event' => $event])];
        $calendarActionView = $calendarActions[0];

        $calendarRegistry = new CalendarActionRegistry(
            new ServiceLocator([
                'calendar_action' => static fn () => new class($calendarActionView) implements CalendarActionPluginInterface {
                    public function __construct(
                        private TemplateView $view,
                    ) {
                    }

                    public function build(CalendarEvent $event): ?TemplateView
                    {
                        return $this->view;
                    }
                },
            ]),
            [
                ['id' => 'calendar_action', 'serviceId' => 'calendar_action', 'priority' => 0, 'position' => 10],
            ],
        );

        $extension = new MailActionExtension($composeRegistry, $actionRegistry, $contactRegistry, $calendarRegistry);

        self::assertSame($composeButtons, $extension->getComposeButtonViews());
        self::assertSame($actions, $extension->getEmailActionViews($email));
        self::assertSame($contactActions, $extension->getContactActionViews($contact));
        self::assertSame($calendarActions, $extension->getCalendarActionViews($event));
    }
}
