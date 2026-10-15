<?php

declare(strict_types=1);

namespace JBSNewMedia\MailViewBundle\View;

final class EmailDetailTemplateProvider
{
    public function getTemplate(): string
    {
        return '@MailView/mail/_email_detail.html.twig';
    }
}
