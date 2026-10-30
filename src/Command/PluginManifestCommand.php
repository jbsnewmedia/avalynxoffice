<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Service\PluginManifestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Regenerates plugins/plugins.json from the plugin composer.json files.
 *
 * In the dev environment the manifest is refreshed automatically whenever a
 * plugin changes. Production web requests never write into plugins/, so the
 * manifest has to be generated explicitly - the production image runs this
 * command at build time. Existing "active" flags are preserved.
 */
#[AsCommand(
    name: 'app:plugin:manifest',
    description: 'Regenerates plugins/plugins.json from the plugin composer.json files (keeps the active flags).',
)]
class PluginManifestCommand extends Command
{
    public function __construct(
        private readonly PluginManifestService $pluginManifestService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $manifest = $this->pluginManifestService->refreshManifest();

        $io->success(sprintf(
            'Wrote %s with %d plugin(s).',
            $this->pluginManifestService->getManifestPath(),
            count($manifest['plugins']),
        ));

        return Command::SUCCESS;
    }
}
