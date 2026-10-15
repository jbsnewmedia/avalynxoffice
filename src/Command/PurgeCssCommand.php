<?php

declare(strict_types=1);

namespace App\Command;

use App\Util\CssClassCollector;
use App\Util\CssPurgerFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'assets:css-purge',
    description: 'Purge eine CSS-Datei auf Basis der in den Twig-Templates verwendeten CSS-Klassen.'
)]
class PurgeCssCommand extends Command
{
    public function __construct(
        private readonly CssClassCollector $collector,
        private readonly CssPurgerFactory $purgerFactory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::OPTIONAL, 'Pfad zur Eingabe-CSS-Datei (absolut oder relativ)', '/app/assets/css/bootstrap.min.css')
            ->addArgument('output', InputArgument::OPTIONAL, 'Pfad zur Ausgabe-CSS-Datei (Standard: <input> mit .purged vor .css)')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Eingabedatei überschreiben (Backup wird als .bak erstellt)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur Bericht ausgeben, keine Datei schreiben')
            ->addOption('safelist', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Zusätzliche Klassen whitelisten (mehrfach verwenden oder per Komma)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Bericht als JSON ausgeben')
            ->addOption('debug-selectors', null, InputOption::VALUE_NONE, 'Finale Selektoren-Liste anzeigen/ausgeben (auch in JSON)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectDir = dirname(__DIR__, 2);
        $inArg = $input->getArgument('input');
        $inArg = is_string($inArg) ? $inArg : '/app/assets/css/bootstrap.min.css';
        $outArg = $input->getArgument('output');
        $outArg = is_string($outArg) ? $outArg : null;
        $overwrite = (bool) $input->getOption('overwrite');
        $dryRun = (bool) $input->getOption('dry-run');
        $asJson = (bool) $input->getOption('json');
        $debugSelectors = (bool) $input->getOption('debug-selectors');
        $safelist = [];
        $rawSafelist = $input->getOption('safelist');
        $rawSafelist = is_array($rawSafelist) ? $rawSafelist : [];
        foreach ($rawSafelist as $entry) {
            foreach (preg_split('/\s*,\s*/', is_scalar($entry) ? (string) $entry : '') ?: [] as $cls) {
                $cls = trim($cls);
                if ('' !== $cls) {
                    $safelist[] = $cls;
                }
            }
        }
        $inPath = $this->toAbsolutePath($inArg, $projectDir);
        if (!is_file($inPath)) {
            $output->writeln('<error>Eingabedatei nicht gefunden: '.$inArg.'</error>');

            return Command::FAILURE;
        }
        $outPath = null;
        if (null !== $outArg) {
            $outPath = $this->toAbsolutePath($outArg, $projectDir);
        } elseif ($overwrite) {
            $outPath = $inPath; // wird überschrieben (mit Backup)
        } else {
            $outPath = preg_replace('/\.css($|\?.*)/i', '.purged.css$1', $inPath) ?? ($inPath.'.purged.css');
        }
        $bytesIn = (int) filesize($inPath);
        $usedClasses = $this->collector->collectAll();
        try {
            $purger = $this->purgerFactory->create($inPath);
        } catch (\Throwable $e) {
            $output->writeln('<error>Initialisierung des Vendor-CSS-Purgers fehlgeschlagen: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        try {
            $purger->loadContent();
            $purger->prepareContent();
            $purger->runContent();
        } catch (\Throwable $e) {
            $output->writeln('<error>Verarbeitung der CSS-Datei im Vendor-Purger fehlgeschlagen: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $selectors = [
            ':root',
            '[data-bs-theme=light]',
            '[data-bs-theme=dark]',
            'body',
        ];
        foreach ($usedClasses as $cls) {
            $norm = $this->normalizeClassName($cls);
            if (null !== $norm) {
                $selectors[] = '.'.$norm;
            }
        }
        foreach ($safelist as $entry) {
            if ('.' === $entry[0] || ':' === $entry[0] || '[' === $entry[0]) {
                $selectors[] = $entry;
                continue;
            }
            $norm = $this->normalizeClassName($entry);
            if (null !== $norm) {
                $selectors[] = '.'.$norm;
            }
        }
        $selectors = array_values(array_unique($selectors));
        sort($selectors, SORT_NATURAL | SORT_FLAG_CASE);
        $purger->addSelectors($selectors);
        $purgedCss = '';
        try {
            $purgedCss = $purger->generateOutput();
        } catch (\Throwable $e) {
            $output->writeln('<error>Erzeugen der Ausgabedaten fehlgeschlagen: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $stats = [
            'bytes_in' => $bytesIn,
            'bytes_out' => strlen($purgedCss),
            'bytes_saved' => max(0, $bytesIn - strlen($purgedCss)),
        ];
        $stats['percent_reduction'] = $stats['bytes_in'] > 0 ? round(($stats['bytes_saved'] / $stats['bytes_in']) * 100, 2) : 0.0;
        if ($asJson) {
            $payload = [
                'input' => $inArg,
                'output' => $dryRun ? null : $this->toRelativePath($outPath, $projectDir),
                'total_used_classes' => count($usedClasses),
                'stats' => $stats,
            ];
            if ($debugSelectors) {
                $payload['selectors'] = $selectors;
            }
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $output->writeln(is_string($json) ? $json : '');
        } else {
            $output->writeln('<info>Gefundene Klassen:</info> '.count($usedClasses));
            $output->writeln(sprintf('Größe vorher: %d B | nachher: %d B | gespart: %d B (%.2f%%)', $stats['bytes_in'], $stats['bytes_out'], $stats['bytes_saved'], $stats['percent_reduction']));
            if ($debugSelectors) {
                $output->writeln('<info>Selektoren (gesamt '.count($selectors).'):</info>');
                foreach ($selectors as $sel) {
                    $output->writeln('  '.$sel);
                }
            }
        }
        if ($dryRun) {
            return Command::SUCCESS;
        }
        $outDir = dirname($outPath);
        $filesystem = new Filesystem();
        if (!is_dir($outDir)) {
            try {
                $filesystem->mkdir($outDir);
            } catch (IOExceptionInterface) {
                $output->writeln('<error>Konnte Zielverzeichnis nicht anlegen: '.$outDir.'</error>');

                return Command::FAILURE;
            }
        }
        if ($overwrite && realpath($outPath) === realpath($inPath)) {
            $bak = $inPath.'.bak';
            try {
                $filesystem->copy($inPath, $bak, true);
                $output->writeln('<info>Backup erstellt:</info> '.$this->toRelativePath($bak, $projectDir));
            } catch (IOExceptionInterface) {
                $output->writeln('<comment>Warnung: Backup konnte nicht erstellt werden: '.$bak.'</comment>');
            }
        }
        try {
            $filesystem->dumpFile($outPath, $purgedCss);
        } catch (IOExceptionInterface) {
            $output->writeln('<error>Schreiben der Ausgabedatei fehlgeschlagen: '.$outPath.'</error>');

            return Command::FAILURE;
        }
        if (!$asJson) {
            $output->writeln('<info>Purged CSS geschrieben nach:</info> '.$this->toRelativePath($outPath, $projectDir));
        }

        return Command::SUCCESS;
    }

    private function toAbsolutePath(string $path, string $projectDir): string
    {
        if ('' === $path) {
            return $projectDir;
        }
        if (DIRECTORY_SEPARATOR === $path[0]) {
            return $path;
        }

        return $projectDir.DIRECTORY_SEPARATOR.$path;
    }

    private function toRelativePath(string $path, string $projectDir): string
    {
        if (str_starts_with($path, $projectDir.DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($projectDir) + 1);
        }

        return $path;
    }

    private function normalizeClassName(string $name): ?string
    {
        $name = trim($name, " \t\n\r\0\x0B'\"");
        if ('' === $name) {
            return null;
        }
        if ('.' === $name[0]) {
            $name = substr($name, 1);
        }
        if (!preg_match('/^[A-Za-z0-9._:%\-\/\[\]\(\)%]+$/', $name)) {
            return null;
        }

        return $name;
    }
}
