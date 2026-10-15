<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Plugin\EmailAction;

use App\Entity\Email;
use JBSNewMedia\MailActionBundle\Contract\EmailActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

final class EmailReplyAllActionPlugin implements EmailActionPluginInterface
{
    #[\Override]
    public function build(Email $email): ?TemplateView
    {
        if ($email->isOutgoing()) {
            return null;
        }

        return new TemplateView('@MailAction/mail_action/email/actions/reply_all.html.twig', [
            'email' => $email,
        ]);
    }
}
