<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Registry;

use App\Entity\Email;
use JBSNewMedia\MailActionBundle\Contract\EmailActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class EmailActionRegistry
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
    public function getActions(Email $email): array
    {
        $actions = [];

        foreach ($this->entries as $entry) {
            $plugin = $this->pluginLocator->get($entry['serviceId']);
            if (!$plugin instanceof EmailActionPluginInterface) {
                continue;
            }

            $view = $plugin->build($email);
            if (null !== $view) {
                $actions[] = $view;
            }
        }

        return $actions;
    }
}
