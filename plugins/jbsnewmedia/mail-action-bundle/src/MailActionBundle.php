<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\MailActionBundle;

use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\CalendarActionCompilerPass;
use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\ComposeButtonCompilerPass;
use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\ContactActionCompilerPass;
use JBSNewMedia\MailActionBundle\DependencyInjection\Compiler\EmailActionCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MailActionBundle extends Bundle
{
    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ComposeButtonCompilerPass());
        $container->addCompilerPass(new EmailActionCompilerPass());
        $container->addCompilerPass(new ContactActionCompilerPass());
        $container->addCompilerPass(new CalendarActionCompilerPass());
    }
}
