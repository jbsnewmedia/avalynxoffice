<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Contract;

use App\Entity\CalendarEvent;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

interface CalendarActionPluginInterface
{
    public function build(CalendarEvent $event): ?TemplateView;
}
