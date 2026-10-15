<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Plugin\CalendarAction;

use App\Entity\CalendarEvent;
use JBSNewMedia\MailActionBundle\Contract\CalendarActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

final class CalendarDeleteActionPlugin implements CalendarActionPluginInterface
{
    #[\Override]
    public function build(CalendarEvent $event): TemplateView
    {
        return new TemplateView('@MailAction/mail_action/calendar/actions/delete.html.twig', [
            'event' => $event,
            'monthKey' => $event->getStartAt()->format('Y-m'),
        ]);
    }
}
