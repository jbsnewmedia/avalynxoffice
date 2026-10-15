<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Plugin\EmailAction;

use App\Entity\Email;
use JBSNewMedia\MailActionBundle\Contract\EmailActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

final class EmailReplyOrEditActionPlugin implements EmailActionPluginInterface
{
    #[\Override]
    public function build(Email $email): ?TemplateView
    {
        if (!$email->isOutgoing()) {
            return new TemplateView('@MailAction/mail_action/email/actions/reply.html.twig', [
                'email' => $email,
            ]);
        }

        if ($email->isDraft() || !$email->isSent()) {
            return new TemplateView('@MailAction/mail_action/email/actions/edit.html.twig', [
                'email' => $email,
            ]);
        }

        return null;
    }
}
