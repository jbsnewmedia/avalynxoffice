<?php

declare(strict_types=1);

namespace JBSNewMedia\BootstrapBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'bootstrap:init',
    description: 'Scaffold Bootstrap SCSS entry files in assets/scss (creates bootstrap5-custom.scss and bootstrap5-custom-dark.scss)'
)]
class InitCommand extends Command
{
    private readonly string $projectDir;

    public function __construct(KernelInterface $kernel)
    {
        parent::__construct();
        $this->projectDir = $kernel->getProjectDir();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite files if they already exist')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without writing files')
        ;

        $this->setAliases(['boostrap:init']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scssDir = $this->projectDir.'/assets/scss';
        $files = [
            $scssDir.'/bootstrap5-custom.scss' => self::getLightContent(),
            $scssDir.'/bootstrap5-custom-dark.scss' => self::getDarkContent(),
        ];

        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_dir($scssDir)) {
            if ($dryRun) {
                $output->writeln('[dry-run] Would create directory: assets/scss');
            } else {
                if (!@mkdir($scssDir, 0777, true) && !is_dir($scssDir)) {
                    $output->writeln('<error>Failed to create directory: assets/scss</error>');

                    return Command::FAILURE;
                }
                $output->writeln('<info>Created directory:</info> assets/scss');
            }
        }

        $created = 0;
        $skipped = 0;
        $overwritten = 0;

        foreach ($files as $path => $content) {
            $rel = 'assets/scss/'.basename($path);
            if (file_exists($path) && !$force) {
                $output->writeln("<comment>Skip existing:</comment> {$rel} (use --force to overwrite)");
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $action = file_exists($path) ? 'Would overwrite' : 'Would create';
                $output->writeln("[dry-run] {$action}: {$rel}");
                continue;
            }

            $result = @file_put_contents($path, $content);
            if (false === $result) {
                $output->writeln("<error>Failed to write file:</error> {$rel}");

                return Command::FAILURE;
            }

            if ($force && file_exists($path)) {
                ++$overwritten;
                $output->writeln("<info>Overwrote:</info> {$rel}");
            } else {
                ++$created;
                $output->writeln("<info>Created:</info> {$rel}");
            }
        }

        if ($dryRun) {
            $output->writeln('<info>Dry-run complete. No files were written.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Done.</info> Created: %d, Overwritten: %d, Skipped: %d', $created, $overwritten, $skipped));
        $output->writeln('Next steps:');
        $output->writeln('  - Compile CSS: php bin/console bootstrap:compile');
        $output->writeln('  - With source map: php bin/console bootstrap:compile --source-map');

        return Command::SUCCESS;
    }

    private static function getLightContent(): string
    {
        return <<<'SCSS'
// Project-wide Bootstrap configuration
// -------------------------------------------------
// Order matters: load functions first, then override variables,
// then import Bootstrap.

// 1) Bootstrap functions (used in variable calculations)
@import "functions";

// 2) Your variable overrides (omit !default so they actually apply)
// Examples (feel free to adjust/extend):
$primary: #ff0000;

// 3) Optional: load Bootstrap base variables so later component imports
//    use consistent maps/variables (not strictly required before full import)
@import "variables";

// 4) Import full Bootstrap
@import "bootstrap";

SCSS;
    }

    private static function getDarkContent(): string
    {
        return <<<'SCSS'
// Dark mode build for Bootstrap
// -------------------------------------------------
// 1) Load Bootstrap functions
@import "functions";

// 2) Set dark-specific variables (adjust examples as needed)
$body-bg: #121212;
$body-color: #e6e6e6;
$primary: #0d6efd; // you can adjust this

// Optional: load additional maps/variables from Bootstrap
@import "variables";

// 3) Import full Bootstrap
@import "bootstrap";

SCSS;
    }
}
