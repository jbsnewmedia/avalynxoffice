<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Plugin\ContactAction;

use App\Entity\Address;
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
