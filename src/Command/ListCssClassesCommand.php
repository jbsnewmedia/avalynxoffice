<?php

declare(strict_types=1);

namespace App\Command;

use App\Util\CssClassCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'templates:list-css-classes',
    description: 'Scans all Twig templates (app and bundles) and lists all CSS classes found.'
)]
class ListCssClassesCommand extends Command
{
    public function __construct(
        private readonly CssClassCollector $collector,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('grouped', null, InputOption::VALUE_NONE, 'Output grouped by template (text output only)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $grouped = (bool) $input->getOption('grouped');
        $asJson = (bool) $input->getOption('json');
        if ($grouped && !$asJson) {
            $byTemplate = $this->collector->collectGrouped();
            foreach ($byTemplate as $tpl => $classes) {
                $output->writeln('# '.$tpl);
                foreach ($classes as $c) {
                    $output->writeln('  - '.$c);
                }
                $output->writeln('');
            }
            $all = $this->collector->collectAll();
            $output->writeln('Gesamt eindeutige Klassen: '.count($all));

            return Command::SUCCESS;
        }
        $classList = $this->collector->collectAll();
        if ($asJson) {
            $payload = [
                'total' => count($classList),
                'classes' => $classList,
            ];
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $output->writeln(is_string($json) ? $json : '');

            return Command::SUCCESS;
        }
        foreach ($classList as $c) {
            $output->writeln($c);
        }

        return Command::SUCCESS;
    }
}
