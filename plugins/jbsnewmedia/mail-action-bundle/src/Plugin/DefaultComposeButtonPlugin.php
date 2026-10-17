<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\MailActionBundle\Plugin;

use JBSNewMedia\MailActionBundle\Contract\ComposeButtonPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

final class DefaultComposeButtonPlugin implements ComposeButtonPluginInterface
{
    #[\Override]
    public function build(): TemplateView
    {
        return new TemplateView('@MailAction/mail_action/compose/default_compose_button.html.twig');
    }
}
