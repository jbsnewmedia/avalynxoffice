<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\MailActionBundle\Contract;

use AvalynxOffice\Entity\CalendarEvent;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

interface CalendarActionPluginInterface
{
    public function build(CalendarEvent $event): ?TemplateView;
}
