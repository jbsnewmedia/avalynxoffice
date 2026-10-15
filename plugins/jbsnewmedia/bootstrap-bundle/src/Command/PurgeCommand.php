<?php

declare(strict_types=1);

namespace JBSNewMedia\BootstrapBundle\Command;

use JBSNewMedia\BootstrapBundle\Service\PurgeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'bootstrap:purge',
    description: 'Purge Bootstrap CSS by scanning templates and keeping only used selectors.'
)]
class PurgeCommand extends Command
{
    private readonly string $projectDir;

    public function __construct(
        KernelInterface $kernel,
        private readonly PurgeService $purgeService,
    ) {
        $this->projectDir = $kernel->getProjectDir();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Path to input CSS file', $this->projectDir.'/assets/css/bootstrap.css')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Path to write the purged CSS file', $this->projectDir.'/assets/css/bootstrap-purged.css')
            ->addOption('templates-dir', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Template directories to scan (multiple allowed)', [$this->projectDir.'/templates'])
            ->addOption('include-dir', 'D', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional directories to scan for selectors (multiple allowed)')
            ->addOption('include-file', 'F', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional files to scan for selectors (multiple allowed)')
            ->addOption('selector', 'S', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Selectors to always keep (multiple allowed)')
            ->addOption('readable', 'r', InputOption::VALUE_NONE, 'Generate human-readable (pretty) CSS output')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write output, only show statistics');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputRaw = $input->getOption('input');
        $cssInput = is_scalar($inputRaw) ? (string) $inputRaw : '';
        $outputRaw = $input->getOption('output');
        $cssOutput = is_scalar($outputRaw) ? (string) $outputRaw : '';
        /** @var string[] $extraSelectors */
        $extraSelectors = (array) $input->getOption('selector');
        $readable = (bool) $input->getOption('readable');
        $dryRun = (bool) $input->getOption('dry-run');

        $pathsToScan = [];
        /** @var string[] $templatesDirs */
        $templatesDirs = (array) $input->getOption('templates-dir');
        /** @var string[] $includeDirs */
        $includeDirs = (array) $input->getOption('include-dir');
        /** @var string[] $includeFiles */
        $includeFiles = (array) $input->getOption('include-file');

        foreach ($templatesDirs as $dir) {
            if ('' !== $dir && is_dir($dir)) {
                $pathsToScan[] = $dir;
            }
        }
        foreach ($includeDirs as $dir) {
            if ('' !== $dir && is_dir($dir)) {
                $pathsToScan[] = $dir;
            }
        }
        foreach ($includeFiles as $file) {
            if ('' !== $file && is_file($file)) {
                $pathsToScan[] = $file;
            }
        }

        /** @var array{0: string[], 1: string, 2: array{found: int, normalized: int, scanned_files: string[]}} $purgeResult */
        $purgeResult = $this->purgeService->purge(
            cssPath: $cssInput,
            pathsToScan: $pathsToScan,
            extraSelectors: $extraSelectors,
            readable: $readable,
        );
        [$keptSelectors, $purgedCss, $stats] = $purgeResult;

        $output->writeln(sprintf('Found %d selectors in sources; keeping %d after normalization.', $stats['found'], count($keptSelectors)));
        $output->writeln(sprintf('Input CSS: %s (%s)', $cssInput, is_file($cssInput) ? (string) filesize($cssInput).' bytes' : 'missing'));
        $output->writeln(sprintf('Output CSS: %s%s', $cssOutput, $dryRun ? ' (dry-run, not written)' : ''));

        if (!empty($keptSelectors)) {
            $output->writeln('Selectors found (normalized):');
            foreach ($keptSelectors as $sel) {
                $output->writeln('  - '.(string) $sel);
            }

            $tags = array_values(array_unique(array_filter($keptSelectors, static fn (string $s): bool => (bool) preg_match('/^[a-z][a-z0-9-]*$/', $s))));
            if (!empty($tags)) {
                $output->writeln('HTML tags found:');
                foreach ($tags as $tag) {
                    $output->writeln('  - '.(string) $tag);
                }
            } else {
                $output->writeln('HTML tags found: none');
            }
        } else {
            $output->writeln('Selectors found (normalized): none');
        }

        if (!$dryRun) {
            $outDir = dirname($cssOutput);
            if (!is_dir($outDir)) {
                if (!@mkdir($outDir, 0777, true) && !is_dir($outDir)) {
                    $output->writeln('<error>Failed to create output directory: '.$outDir.'</error>');

                    return Command::FAILURE;
                }
            }
            if (false === @file_put_contents($cssOutput, $purgedCss)) {
                $output->writeln('<error>Failed to write output file: '.$cssOutput.'</error>');

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
