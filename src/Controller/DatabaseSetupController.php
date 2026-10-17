<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class DatabaseSetupController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/database/setup', name: 'app_database_setup')]
    public function setup(KernelInterface $kernel): Response
    {
        set_time_limit(300);
        $logs = [];

        $projectDir = $kernel->getProjectDir();
        $migrationsDir = $projectDir.'/migrations';
        $prepOutput = '';
        $prepStatus = 'success';
        $env = $kernel->getEnvironment();

        try {
            if (!is_dir($migrationsDir)) {
                if (!mkdir($migrationsDir, 0777, true) && !is_dir($migrationsDir)) {
                    throw new \RuntimeException($this->translator->trans('database_setup.directory_not_created', ['%path%' => $migrationsDir]));
                }
                $prepOutput .= $this->translator->trans('database_setup.migrations_directory_created')."\n";
            } elseif ('prod' !== $env) {
                // Clear existing migrations to ensure a fresh start
                $files = glob($migrationsDir.'/*.php');
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (is_file($file) && !@unlink($file)) {
                            throw new \RuntimeException(sprintf('Migration file could not be deleted: %s', $file));
                        }
                    }
                }
                $prepOutput .= $this->translator->trans('database_setup.migrations_cleared')."\n";
            } else {
                $prepOutput .= $this->translator->trans('database_setup.migrations_kept')."\n";
            }

            if (!is_writable($migrationsDir)) {
                throw new \RuntimeException($this->translator->trans('database_setup.directory_not_writable', ['%path%' => $migrationsDir]));
            }
            $prepOutput .= $this->translator->trans('database_setup.migrations_writable');
        } catch (\Throwable $e) {
            $prepOutput .= $this->translator->trans('database_setup.error_prefix', ['%message%' => $e->getMessage()]);
            $prepStatus = 'error';
        }

        $logs[] = [
            'label' => $this->translator->trans('database_setup.label.prepare_migrations_directory'),
            'command' => 'fs-operations',
            'output' => $prepOutput,
            'exitCode' => 'success' === $prepStatus ? 0 : 1,
            'status' => $prepStatus,
        ];

        if ('error' === $prepStatus) {
            return $this->render('database_setup/index.html.twig', [
                'logs' => $logs,
            ]);
        }

        $phpBinary = new PhpExecutableFinder()->find() ?: 'php';
        $consolePath = $kernel->getProjectDir().'/bin/console';

        $commands = [
            $this->translator->trans('database_setup.label.drop_database') => [
                'command' => 'doctrine:database:drop',
                '--force' => true,
                '--if-exists' => true,
                '--no-interaction' => true,
            ],
            $this->translator->trans('database_setup.label.create_database') => [
                'command' => 'doctrine:database:create',
                '--no-interaction' => true,
            ],
        ];

        if ('prod' !== $env) {
            $commands[$this->translator->trans('database_setup.label.make_migration')] = [
                'command' => 'make:migration',
                '--no-interaction' => true,
            ];
        }

        $commands[$this->translator->trans('database_setup.label.execute_migrations')] = [
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
        ];

        $commands[$this->translator->trans('database_setup.label.load_fixtures')] = [
            'command' => 'doctrine:fixtures:load',
            '--no-interaction' => true,
        ];

        foreach ($commands as $label => $commandData) {
            $command = $commandData['command'];
            unset($commandData['command']);

            $args = [$phpBinary, $consolePath, $command];
            foreach ($commandData as $key => $_enabled) {
                $args[] = $key;
            }

            try {
                [$exitCode, $commandOutput] = $this->runConsoleProcess($args);

                // Remove ANSI escape sequences
                $commandOutput = preg_replace('/\x1b\[[0-9;]*[mGKHJKfme]/', '', $commandOutput) ?? $commandOutput;
                // Also remove OSC sequences (like the file links in the output)
                $commandOutput = preg_replace('/\x1b\].*?\x1b\\\\/', '', $commandOutput) ?? $commandOutput;

                // Remove deprecation warnings
                $lines = explode("\n", $commandOutput);
                $filteredLines = array_filter($lines, function ($line) {
                    $trimmedLine = trim($line);
                    if ('' === $trimmedLine) {
                        return true;
                    }

                    // Filter out JSON-formatted deprecation logs
                    if (str_starts_with($trimmedLine, '{') && str_contains($trimmedLine, '"channel":"deprecation"')) {
                        return false;
                    }

                    // Filter out plain text deprecation warnings
                    if (str_contains($trimmedLine, '[deprecation]') || str_contains($trimmedLine, 'User Deprecated:')) {
                        return false;
                    }

                    return true;
                });
                $commandOutput = trim(implode("\n", $filteredLines));

                $status = 0 === $exitCode ? 'success' : 'error';

                if ('make:migration' === $command && 0 !== $exitCode) {
                    if (str_contains($commandOutput, 'No changes detected')) {
                        $status = 'success';
                    }
                }

                $logs[] = [
                    'label' => $label,
                    'command' => $command,
                    'output' => $commandOutput,
                    'exitCode' => $exitCode,
                    'status' => $status,
                ];
            } catch (\Exception $e) {
                $logs[] = [
                    'label' => $label,
                    'command' => $command,
                    'output' => $e->getMessage(),
                    'exitCode' => $e->getCode(),
                    'status' => 'error',
                ];
            }
        }

        return $this->render('database_setup/index.html.twig', [
            'logs' => $logs,
        ]);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int|null, 1: string}
     */
    protected function runConsoleProcess(array $args): array
    {
        $process = new Process($args);
        $process->setTimeout(300);
        $process->run();

        return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }
}
