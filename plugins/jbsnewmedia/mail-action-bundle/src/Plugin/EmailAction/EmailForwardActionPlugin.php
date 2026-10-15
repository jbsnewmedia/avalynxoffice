<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Plugin\EmailAction;

use App\Entity\Email;
use JBSNewMedia\MailActionBundle\Contract\EmailActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

final class EmailForwardActionPlugin implements EmailActionPluginInterface
{
    #[\Override]
    public function build(Email $email): TemplateView
    {
        return new TemplateView('@MailAction/mail_action/email/actions/forward.html.twig', [
            'email' => $email,
        ]);
    }
}
