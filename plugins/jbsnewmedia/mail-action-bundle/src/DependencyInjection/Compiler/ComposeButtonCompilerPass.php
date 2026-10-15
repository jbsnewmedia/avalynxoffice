<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\DependencyInjection\Compiler;

use JBSNewMedia\MailActionBundle\DependencyInjection\MailActionTags;
use JBSNewMedia\MailActionBundle\Registry\ComposeButtonRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ComposeButtonCompilerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ComposeButtonRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(ComposeButtonRegistry::class);
        $taggedServices = $container->findTaggedServiceIds(MailActionTags::COMPOSE_BUTTON);

        /** @var array<string, array{id: string, serviceId: string, priority: int, position: int}> $selected */
        $selected = [];

        foreach ($taggedServices as $serviceId => $tags) {
            foreach ($tags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                $idValue = $tag['id'] ?? null;
                $pluginId = is_string($idValue) && '' !== $idValue ? $idValue : $serviceId;
                $priorityValue = $tag['priority'] ?? null;
                $priority = is_numeric($priorityValue) ? (int) $priorityValue : 0;
                $positionValue = $tag['position'] ?? null;
                $position = is_numeric($positionValue) ? (int) $positionValue : 0;

                $current = $selected[$pluginId] ?? null;
                if (null !== $current) {
                    if ($priority < $current['priority']) {
                        continue;
                    }

                    if ($priority === $current['priority']) {
                        continue;
                    }
                }

                $selected[$pluginId] = [
                    'id' => $pluginId,
                    'serviceId' => $serviceId,
                    'priority' => $priority,
                    'position' => $position,
                ];
            }
        }

        $entries = array_values($selected);
        usort(
            $entries,
            static function (array $left, array $right): int {
                $byPosition = $right['position'] <=> $left['position'];
                if (0 !== $byPosition) {
                    return $byPosition;
                }

                return $left['id'] <=> $right['id'];
            }
        );

        $locatorServices = [];
        foreach ($entries as $entry) {
            $locatorServices[$entry['serviceId']] = new Reference($entry['serviceId']);
        }

        $registry->setArgument('$pluginLocator', ServiceLocatorTagPass::register($container, $locatorServices));
        $registry->setArgument('$entries', $entries);
    }
}
