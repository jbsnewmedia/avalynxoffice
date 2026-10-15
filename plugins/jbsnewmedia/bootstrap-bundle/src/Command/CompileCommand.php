<?php

declare(strict_types=1);

namespace JBSNewMedia\BootstrapBundle\Command;

use ScssPhp\ScssPhp\Compiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'bootstrap:compile',
    description: 'Compile SCSS to CSS (writes readable CSS and .min.css). Imports Bootstrap from vendor.'
)]
class CompileCommand extends Command
{
    private readonly string $projectDir;

    public function __construct(KernelInterface $kernel, private readonly ?\JBSNewMedia\BootstrapBundle\Service\ScssCompilerFactory $compilerFactory = null)
    {
        parent::__construct();
        $this->projectDir = $kernel->getProjectDir();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::OPTIONAL, 'Input SCSS entry file (relative to project root)', 'assets/scss/bootstrap5-custom.scss')
            ->addArgument('output', InputArgument::OPTIONAL, 'Minified output CSS file (relative to project root)', 'assets/css/bootstrap.min.css')
            ->addOption('output-normal', 'O', InputOption::VALUE_REQUIRED, 'Readable (non-minified) CSS output (relative to project root)', 'assets/css/bootstrap.css')
            ->addOption('source-map', null, InputOption::VALUE_NONE, 'Generate source map alongside each CSS output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = $this->compilerFactory ?? new \JBSNewMedia\BootstrapBundle\Service\ScssCompilerFactory();
        if (!$factory->isAvailable()) {
            $output->writeln('<error>Required class ScssPhp\\ScssPhp\\Compiler not found.</error>');
            $output->writeln('<comment>If you are loading BootstrapBundle from source, run "composer install" inside jbsnewmedia/bootstrap-bundle so its vendor/autoload.php exists.</comment>');
            $output->writeln('<comment>Alternatively, add "scssphp/scssphp" to your application composer.json.</comment>');

            return Command::FAILURE;
        }

        $inRaw = $input->getArgument('input');
        $inRel = is_scalar($inRaw) ? (string) $inRaw : '';
        $outRaw = $input->getArgument('output');
        $outMinRel = is_scalar($outRaw) ? (string) $outRaw : '';
        $outNormalRaw = $input->getOption('output-normal');
        $outNormalRel = is_scalar($outNormalRaw) ? (string) $outNormalRaw : '';
        $in = $this->projectDir.DIRECTORY_SEPARATOR.$inRel;
        $outMin = $this->projectDir.DIRECTORY_SEPARATOR.$outMinRel;
        $outNormal = $this->projectDir.DIRECTORY_SEPARATOR.$outNormalRel;

        if (!file_exists($in)) {
            $output->writeln("<error>Input file not found: {$inRel}</error>");
            if ('vendor/twbs/bootstrap/scss/bootstrap.scss' === $inRel) {
                $output->writeln('<comment>Hint: Make sure the package "twbs/bootstrap" is installed (composer require twbs/bootstrap) and the path is correct.</comment>');
            }
            if ('assets/scss/bootstrap5-custom.scss' === $inRel) {
                $output->writeln('<comment>Hint: Create the file assets/scss/bootstrap5-custom.scss (override variables, then add "@import \"bootstrap\";") or explicitly use the vendor entry: vendor/twbs/bootstrap/scss/bootstrap.scss</comment>');
            }

            return Command::FAILURE;
        }

        foreach ([dirname($outNormal), dirname($outMin)] as $outDir) {
            if (!is_dir($outDir)) {
                if (!@mkdir($outDir, 0777, true) && !is_dir($outDir)) {
                    $output->writeln('<error>Failed to create output directory: '.$outDir.'</error>');

                    return Command::FAILURE;
                }
            }
        }

        $compiler = $factory->create();
        $compiler->setImportPaths([
            $this->projectDir.'/vendor/twbs/bootstrap/scss',
            $this->projectDir.'/vendor',
            $this->projectDir.'/assets/scss',
            $this->projectDir.'/assets',
        ]);

        $sourceMap = (bool) $input->getOption('source-map');
        if ($sourceMap) {
            $compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
        }

        $scss = @file_get_contents($in);
        if (false === $scss) {
            $output->writeln('<error>Failed to read input file.</error>');

            return Command::FAILURE;
        }

        try {
            $compiler->setOutputStyle(\ScssPhp\ScssPhp\OutputStyle::EXPANDED);
            if ($sourceMap) {
                $compiler->setSourceMapOptions([
                    'sourceMapWriteTo' => $outNormal.'.map',
                    'sourceMapURL' => basename($outNormal).'.map',
                    'sourceMapFilename' => basename($outNormal),
                    'sourceMapBasepath' => $this->projectDir,
                    'sourceRoot' => '/',
                ]);
            }
            $resultReadable = $compiler->compileString($scss, $in);
            $cssReadable = $resultReadable->getCss();
            file_put_contents($outNormal, $cssReadable);
            $output->writeln('<info>Compiled (readable) '.$inRel.' -> '.$outNormalRel.'</info>');
            if ($sourceMap) {
                $mapContent = $resultReadable->getSourceMap();
                if (is_string($mapContent) && '' !== $mapContent) {
                    $mapPath = $outNormal.'.map';
                    file_put_contents($mapPath, $mapContent);
                    $output->writeln('<info>Source map written: '.$this->makePathRelative($mapPath).'</info>');
                }
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>SCSS compilation (readable) failed: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        try {
            $compiler->setOutputStyle(\ScssPhp\ScssPhp\OutputStyle::COMPRESSED);
            if ($sourceMap) {
                $compiler->setSourceMapOptions([
                    'sourceMapWriteTo' => $outMin.'.map',
                    'sourceMapURL' => basename($outMin).'.map',
                    'sourceMapFilename' => basename($outMin),
                    'sourceMapBasepath' => $this->projectDir,
                    'sourceRoot' => '/',
                ]);
            }
            $resultMin = $compiler->compileString($scss, $in);
            $cssMin = $resultMin->getCss();
            file_put_contents($outMin, $cssMin);
            $output->writeln('<info>Compiled (minified) '.$inRel.' -> '.$outMinRel.'</info>');
            if ($sourceMap) {
                $mapContent = $resultMin->getSourceMap();
                if (is_string($mapContent) && '' !== $mapContent) {
                    $mapPath = $outMin.'.map';
                    file_put_contents($mapPath, $mapContent);
                    $output->writeln('<info>Source map written: '.$this->makePathRelative($mapPath).'</info>');
                }
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>SCSS compilation (minified) failed: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function makePathRelative(string $absPath): string
    {
        $prefix = rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($absPath, $prefix)) {
            return substr($absPath, strlen($prefix));
        }

        return $absPath;
    }
}
