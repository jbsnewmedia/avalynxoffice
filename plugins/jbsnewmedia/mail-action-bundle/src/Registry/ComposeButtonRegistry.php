<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\MailActionBundle\Registry;

use JBSNewMedia\MailActionBundle\Contract\ComposeButtonPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class ComposeButtonRegistry
{
    /**
     * @param list<array{id: string, serviceId: string, priority: int, position: int}> $entries
     */
    public function __construct(
        private ContainerInterface $pluginLocator = new ServiceLocator([]),
        private array $entries = [],
    ) {
    }

    /**
     * @return list<TemplateView>
     */
    public function getButtons(): array
    {
        $buttons = [];

        foreach ($this->entries as $entry) {
            $plugin = $this->pluginLocator->get($entry['serviceId']);
            if (!$plugin instanceof ComposeButtonPluginInterface) {
                continue;
            }

            $view = $plugin->build();
            if (null !== $view) {
                $buttons[] = $view;
            }
        }

        return $buttons;
    }
}
