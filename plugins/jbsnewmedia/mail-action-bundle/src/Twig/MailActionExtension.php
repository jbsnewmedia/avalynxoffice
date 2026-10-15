<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Twig;

use App\Entity\Address;
use App\Entity\CalendarEvent;
use App\Entity\Email;
use JBSNewMedia\MailActionBundle\Model\TemplateView;
use JBSNewMedia\MailActionBundle\Registry\CalendarActionRegistry;
use JBSNewMedia\MailActionBundle\Registry\ComposeButtonRegistry;
use JBSNewMedia\MailActionBundle\Registry\ContactActionRegistry;
use JBSNewMedia\MailActionBundle\Registry\EmailActionRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MailActionExtension extends AbstractExtension
{
    public function __construct(
        private readonly ComposeButtonRegistry $composeButtonRegistry,
        private readonly EmailActionRegistry $emailActionRegistry,
        private readonly ContactActionRegistry $contactActionRegistry,
        private readonly CalendarActionRegistry $calendarActionRegistry,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('mail_compose_button_views', $this->getComposeButtonViews(...)),
            new TwigFunction('mail_email_action_views', $this->getEmailActionViews(...)),
            new TwigFunction('mail_contact_action_views', $this->getContactActionViews(...)),
            new TwigFunction('mail_calendar_action_views', $this->getCalendarActionViews(...)),
        ];
    }

    /**
     * @return list<TemplateView>
     */
    public function getComposeButtonViews(): array
    {
        return $this->composeButtonRegistry->getButtons();
    }

    /**
     * @return list<TemplateView>
     */
    public function getEmailActionViews(Email $email): array
    {
        return $this->emailActionRegistry->getActions($email);
    }

    /**
     * @return list<TemplateView>
     */
    public function getContactActionViews(Address $contact): array
    {
        return $this->contactActionRegistry->getActions($contact);
    }

    /**
     * @return list<TemplateView>
     */
    public function getCalendarActionViews(CalendarEvent $event): array
    {
        return $this->calendarActionRegistry->getActions($event);
    }
}
