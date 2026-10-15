<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Contract;

use App\Entity\Address;
use JBSNewMedia\MailActionBundle\Model\TemplateView;

interface ContactActionPluginInterface
{
    public function build(Address $contact): ?TemplateView;
}
