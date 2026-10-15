<?php

declare(strict_types=1);

namespace App\Command;

use ScssPhp\ScssPhp\Compiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'assets:scss-compile',
    description: 'Compile SCSS files from assets/scss to public/css',
)]
class CompileScssCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::OPTIONAL, 'Input SCSS entry file relative to project root', 'assets/scss/bootstrap.scss')
            ->addArgument('output', InputArgument::OPTIONAL, 'Output CSS file relative to project root', 'assets/css/bootstrap.min.css')
            ->addOption('source-map', null, InputOption::VALUE_NONE, 'Generate source map alongside the CSS');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectDir = dirname(__DIR__, 2);
        $inputArg = $input->getArgument('input');
        $inRel = is_string($inputArg) ? $inputArg : 'assets/scss/bootstrap.scss';
        $outputArg = $input->getArgument('output');
        $outRel = is_string($outputArg) ? $outputArg : 'assets/css/bootstrap.min.css';
        $in = $this->toAbsolutePath($inRel, $projectDir);
        $out = $this->toAbsolutePath($outRel, $projectDir);
        if (!file_exists($in)) {
            $output->writeln("<error>Input file not found: {$inRel}</error>");

            return Command::FAILURE;
        }
        $outDir = dirname($out);
        if (!is_dir($outDir)) {
            try {
                new Filesystem()->mkdir($outDir);
            } catch (IOExceptionInterface) {
                $output->writeln('<error>Failed to create output directory: '.$outDir.'</error>');

                return Command::FAILURE;
            }
        }
        $compiler = new Compiler();
        $compiler->setImportPaths([
            $projectDir.'/assets/scss',
            $projectDir.'/assets',
            $projectDir.'/node_modules',
        ]);
        $compiler->setOutputStyle(\ScssPhp\ScssPhp\OutputStyle::COMPRESSED);
        $sourceMap = (bool) $input->getOption('source-map');
        if ($sourceMap) {
            $compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
            $compiler->setSourceMapOptions([
                'sourceMapWriteTo' => $out.'.map',
                'sourceMapURL' => basename($out).'.map',
                'sourceMapFilename' => basename($out),
                'sourceMapBasepath' => $projectDir,
                'sourceRoot' => '/',
            ]);
        }
        try {
            $css = $compiler->compileFile($in)->getCss();
        } catch (\Throwable $e) {
            $output->writeln('<error>SCSS compilation failed: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        file_put_contents($out, $css);
        $output->writeln('<info>Compiled '.$inRel.' -> '.$outRel.'</info>');

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
}
