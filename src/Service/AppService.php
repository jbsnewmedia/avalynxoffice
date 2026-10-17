<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AppService
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function getVersion(): string
    {
        $projectDirParam = $this->parameterBag->get('kernel.project_dir');
        $projectDir = is_string($projectDirParam) ? $projectDirParam : '';
        $version = 'dev';
        try {
            $composerPath = $projectDir.'/composer.json';
            if (is_file($composerPath)) {
                $content = file_get_contents($composerPath);
                if (false !== $content) {
                    $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($data) && isset($data['version']) && is_string($data['version']) && '' !== $data['version']) {
                        $version = $data['version'];
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $version;
    }
}
