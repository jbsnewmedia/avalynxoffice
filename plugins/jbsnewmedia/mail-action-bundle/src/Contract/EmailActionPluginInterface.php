<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Contract;

use App\Entity\Email;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

interface EmailActionPluginInterface
{
    public function build(Email $email): ?TemplateView;
}
