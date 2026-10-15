<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Registry;

use App\Entity\CalendarEvent;
use JBSNewMedia\MailActionBundle\Contract\CalendarActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class CalendarActionRegistry
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
    public function getActions(CalendarEvent $event): array
    {
        $actions = [];

        foreach ($this->entries as $entry) {
            $plugin = $this->pluginLocator->get($entry['serviceId']);
            if (!$plugin instanceof CalendarActionPluginInterface) {
                continue;
            }

            $view = $plugin->build($event);
            if (null !== $view) {
                $actions[] = $view;
            }
        }

        return $actions;
    }
}
