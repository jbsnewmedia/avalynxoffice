<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Contract;

use JBSNewMedia\MailActionBundle\Model\TemplateView;

interface ComposeButtonPluginInterface
{
    public function build(): ?TemplateView;
}
