<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Repository\AppSettingRepository;
use JBSNewMedia\BootstrapBundle\Service\ScssCompilerFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Installation-wide theme settings. Changing the primary color rewrites the
 * SCSS variable and recompiles the Bootstrap CSS through the compiler of the
 * JBS bootstrap bundle.
 */
final readonly class ThemeService
{
    public const string SETTING_PRIMARY_COLOR = 'theme.primary_color';
    public const string DEFAULT_PRIMARY_COLOR = '#2563eb';

    public function __construct(
        private AppSettingRepository $appSettingRepository,
        private ScssCompilerFactory $scssCompilerFactory,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /**
     * Returns the stored primary color (#RRGGBB, lowercase) or the default
     * when nothing (valid) is stored.
     */
    public function getPrimaryColor(): string
    {
        $stored = $this->appSettingRepository->getValue(self::SETTING_PRIMARY_COLOR);
        if (null === $stored || 1 !== preg_match('/^#[0-9a-fA-F]{6}$/', $stored)) {
            return self::DEFAULT_PRIMARY_COLOR;
        }

        return strtolower($stored);
    }

    /**
     * Stores a new primary color and recompiles the CSS.
     *
     * Accepts three-digit (#f80) and six-digit (#ff8800) hex notations
     * (0-9a-f, case-insensitive) and normalizes both to lowercase
     * #rrggbb before persisting.
     *
     * @throws \InvalidArgumentException when the color is not a #rgb/#rrggbb hex value
     */
    public function setPrimaryColor(string $color): void
    {
        $color = strtolower(trim($color));
        if (1 === preg_match('/^#[0-9a-f]{3}$/', $color)) {
            $color = '#'.$color[1].$color[1].$color[2].$color[2].$color[3].$color[3];
        }
        if (1 !== preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new \InvalidArgumentException(sprintf('Invalid primary color "%s"; expected a #rgb or #rrggbb hex value.', $color));
        }

        $this->appSettingRepository->setValue(self::SETTING_PRIMARY_COLOR, $color);
        $this->compile($color);
    }

    /**
     * Replaces the $primary SCSS variable in the given stylesheet source.
     *
     * @throws \RuntimeException when the variable cannot be located
     */
    public function replacePrimaryColor(string $scss, string $primaryColor): string
    {
        $themed = preg_replace('/^\$primary:\s*[^;]+;/m', '$primary: '.$primaryColor.';', $scss, 1, $count);
        if (0 === $count) {
            throw new \RuntimeException('Failed to locate the $primary variable in the bootstrap stylesheet.');
        }

        return (string) $themed;
    }

    /**
     * Compiles the application SCSS with the given primary color: a themed
     * copy of assets/scss/bootstrap.scss is written below var/theme and run
     * through the JBS bootstrap bundle compiler, producing the readable and
     * the minified Bootstrap CSS.
     *
     * @throws \RuntimeException when the stylesheet cannot be read or compiled
     */
    public function compile(string $primaryColor): void
    {
        $source = $this->projectDir.'/assets/scss/bootstrap.scss';
        $scss = @file_get_contents($source);
        if (false === $scss) {
            throw new \RuntimeException('Failed to read '.$source.'.');
        }

        $themed = $this->replacePrimaryColor($scss, $primaryColor);

        $themeDir = $this->projectDir.'/var/theme';
        if (!is_dir($themeDir) && !@mkdir($themeDir, 0777, true) && !is_dir($themeDir)) {
            throw new \RuntimeException('Failed to create theme directory '.$themeDir.'.');
        }
        $inputPath = $themeDir.'/bootstrap.scss';
        file_put_contents($inputPath, $themed);

        // The relative imports of bootstrap.scss resolve against the input
        // file location, so the themed copy keeps the same directory depth
        // as assets/scss.
        $compiler = $this->scssCompilerFactory->create();
        $compiler->setImportPaths([
            $this->projectDir.'/vendor/twbs/bootstrap/scss',
            $this->projectDir.'/vendor',
            $this->projectDir.'/assets/scss',
            $this->projectDir.'/assets',
        ]);

        try {
            $compiler->setOutputStyle(\ScssPhp\ScssPhp\OutputStyle::EXPANDED);
            file_put_contents(
                $this->projectDir.'/assets/css/bootstrap.css',
                $compiler->compileFile($inputPath)->getCss(),
            );

            $compiler->setOutputStyle(\ScssPhp\ScssPhp\OutputStyle::COMPRESSED);
            file_put_contents(
                $this->projectDir.'/assets/css/bootstrap.min.css',
                $compiler->compileFile($inputPath)->getCss(),
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('SCSS compilation failed: '.$e->getMessage(), 0, $e);
        }
    }
}
