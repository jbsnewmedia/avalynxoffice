<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\MailActionBundle\Plugin\ContactAction;

use AvalynxOffice\Entity\Address;
use JBSNewMedia\MailActionBundle\Contract\ContactActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

final class ContactDeleteActionPlugin implements ContactActionPluginInterface
{
    #[\Override]
    public function build(Address $contact): TemplateView
    {
        return new TemplateView('@MailAction/mail_action/contact/actions/delete.html.twig', [
            'contact' => $contact,
        ]);
    }
}
