<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Plugin\ContactAction;

use App\Entity\Address;
use JBSNewMedia\MailActionBundle\Contract\ContactActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

final class ContactEditActionPlugin implements ContactActionPluginInterface
{
    #[\Override]
    public function build(Address $contact): TemplateView
    {
        return new TemplateView('@MailAction/mail_action/contact/actions/edit.html.twig', [
            'contact' => $contact,
        ]);
    }
}
