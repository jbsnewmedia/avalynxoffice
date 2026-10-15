<?php

declare(strict_types=1);

namespace JBSNewMedia\BootstrapBundle\Service;

class PurgeService
{
    public function __construct(private ?BootstrapPurgerFactory $purgerFactory = null)
    {
    }

    /**
     * Purge a CSS file using selectors found in given paths and extra selectors provided.
     *
     * @param string   $cssPath        Path to the input CSS file
     * @param string[] $pathsToScan    Files or directories to scan (recursive for dirs)
     * @param string[] $extraSelectors Additional selectors to keep
     * @param bool     $readable       If true, output will be formatted (not minified)
     *
     * @return array{0: string[], 1: string, 2: array{found: int, normalized: int, scanned_files: string[]}} [selectorsKept, cssOutput, stats]
     */
    public function purge(string $cssPath, array $pathsToScan = [], array $extraSelectors = [], bool $readable = false): array
    {
        [$content, $scannedFiles] = $this->collectContents($pathsToScan);
        $foundSelectors = $this->extractSelectors($content);

        /** @var mixed $sel */
        foreach ($extraSelectors as $sel) {
            $selStr = is_scalar($sel) ? (string) $sel : '';
            $selStr = trim($selStr);
            if ('' !== $selStr) {
                $foundSelectors[] = $selStr;
            }
        }

        $normalized = $this->normalizeSelectors($foundSelectors);
        $normalized = array_values(array_unique($normalized));

        $factory = $this->purgerFactory ??= new BootstrapPurgerFactory();
        $purger = $factory->create($cssPath);
        $purger->loadContent();
        $purger->prepareContent();
        $purger->runContent();
        if ($normalized) {
            $purger->addSelectors($normalized);
        }
        $css = $purger->generateOutput(!$readable); // library expects minify flag; invert readable

        $search = '/* Purged by CssPurger (https://jbs-newmedia.de/css-purger) - MIT License - JBS New Media GmbH, Juergen Schwind */'."\n";
        $replace = '/* Purged with Bootstrap Bundle (https://jbs-newmedia.de/bootstrap-bundle) - Purged by CssPurger (https://jbs-newmedia.de/css-purger) - MIT License - JBS New Media GmbH, Juergen Schwind */'."\n";
        $css = str_replace($search, $replace, $css);

        return [$normalized, $css, [
            'found' => count($foundSelectors),
            'normalized' => count($normalized),
            'scanned_files' => $scannedFiles,
        ]];
    }

    /**
     * @param string[] $paths
     *
     * @return array{0: string, 1: string[]}
     */
    private function collectContents(array $paths): array
    {
        $buffer = '';
        $scannedFiles = [];
        foreach ($paths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            if (is_dir($path)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                /** @var \SplFileInfo $file */
                foreach ($it as $file) {
                    if ($this->isScannableFile($file->getPathname())) {
                        $scannedFiles[] = $file->getPathname();
                        $buffer .= "\n\n/* FILE: {$file->getPathname()} */\n".@file_get_contents($file->getPathname());
                    }
                }
            } elseif (is_file($path) && $this->isScannableFile($path)) {
                $scannedFiles[] = $path;
                $buffer .= "\n\n/* FILE: {$path} */\n".@file_get_contents($path);
            }
        }

        return [$buffer, $scannedFiles];
    }

    private function isScannableFile(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = ['twig', 'html', 'htm', 'php', 'phtml', 'js', 'ts', 'vue', 'jsx', 'tsx', 'md'];

        return in_array($ext, $allowed, true);
    }

    /**
     * Extracts class, id, tag and attribute-based selectors from raw content.
     * This is a heuristic using regex, good enough for most Twig/HTML/JS.
     *
     * @return string[] raw selector tokens (not yet normalized)
     */
    private function extractSelectors(string $content): array
    {
        $selectors = [];

        $clean = (string) $content;
        $clean = preg_replace('/\{#.*?#\}/s', '', $clean) ?? $clean;

        // Harvest from Twig blocks
        if (preg_match_all('/(\{\{.*?\}\}|\{%.*?%\})/s', $clean, $twigBlocks)) {
            foreach ($twigBlocks[0] as $block) {
                if (preg_match_all('/(["\'])\s*([^"\'\s][^"\']*)\s*\1/s', $block, $strs)) {
                    foreach ($strs[2] as $literal) {
                        $literal = trim((string) $literal);
                        if ('' !== $literal && $this->isValidCssIdent($literal)) {
                            $selectors[] = '.'.$literal;
                        }
                    }
                }
            }
        }

        // Remove all Twig print/control blocks
        $clean = preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', '', $clean) ?? $clean;

        // class="foo bar" and class='foo bar'
        if (preg_match_all('/class\s*=\s*(["\'])(.*?)\1/si', $clean, $m)) {
            foreach ($m[2] as $classList) {
                $lines = preg_split('/\s+/', trim((string) $classList));
                if (is_array($lines)) {
                    foreach ($lines as $cls) {
                        $cls = trim($cls);
                        if ('' !== $cls && $this->isValidCssIdent($cls)) {
                            $selectors[] = '.'.$cls;
                        }
                    }
                }
            }
        }

        // className="foo" (React/JSX)
        if (preg_match_all('/className\s*=\s*(["\'])(.*?)\1/si', $clean, $m2)) {
            foreach ($m2[2] as $classList) {
                $lines = preg_split('/\s+/', trim((string) $classList));
                if (is_array($lines)) {
                    foreach ($lines as $cls) {
                        $cls = trim($cls);
                        if ('' !== $cls && $this->isValidCssIdent($cls)) {
                            $selectors[] = '.'.$cls;
                        }
                    }
                }
            }
        }

        // id="foo" and id='foo'
        if (preg_match_all('/id\s*=\s*(["\'])(.*?)\1/si', $clean, $m3)) {
            foreach ($m3[2] as $idVal) {
                $idVal = trim((string) $idVal);
                if ('' !== $idVal && $this->isValidCssIdent($idVal)) {
                    $selectors[] = '#'.$idVal;
                }
            }
        }

        // data-bs-theme=value => attribute selector
        if (preg_match_all('/data-bs-theme\s*=\s*(["\']?)([a-zA-Z0-9_-]+)\1/si', $clean, $m4)) {
            foreach ($m4[2] as $theme) {
                $selectors[] = '[data-bs-theme='.$theme.']';
            }
        }

        // HTML tags used, e.g., <body>, <h1>, <div>
        if (preg_match_all('/<\s*([a-z][a-z0-9-]*)[^>]*>/i', $clean, $m5)) {
            foreach ($m5[1] as $tag) {
                $selectors[] = strtolower($tag);
            }
        }

        // JS: element.classList.add('foo','bar')
        if (preg_match_all('/classList\.(?:add|toggle)\s*\(([^\)]*)\)/si', $clean, $m6)) {
            foreach ($m6[1] as $args) {
                if (preg_match_all('/["\']([a-zA-Z0-9_-]+)["\']/', (string) $args, $mArgs)) {
                    foreach ($mArgs[1] as $cls) {
                        if ($this->isValidCssIdent($cls)) {
                            $selectors[] = '.'.$cls;
                        }
                    }
                }
            }
        }

        return $selectors;
    }

    /** @param string[] $selectors
     * @return string[]
     */
    private function normalizeSelectors(array $selectors): array
    {
        $out = [];
        foreach ($selectors as $sel) {
            $sel = trim((string) $sel);
            if ('' === $sel) {
                continue;
            }
            $type = $sel[0];
            $valid = false;
            $valid = match ($type) {
                '.' => (bool) preg_match('/^\.[A-Za-z_][A-Za-z0-9_-]*$/', $sel),
                '#' => (bool) preg_match('/^#[A-Za-z_][A-Za-z0-9_-]*$/', $sel),
                '[' => (bool) preg_match('/^\[[A-Za-z0-9_-]+=[A-Za-z0-9_-]+\]$/', $sel),
                ':' => ':root' === $sel,
                default => (bool) preg_match('/^[a-z][a-z0-9-]*$/', $sel),
            };

            if (!$valid) {
                continue;
            }

            $sel = preg_replace('/\s+/', ' ', $sel) ?? $sel;
            $out[] = $sel;
        }

        return $out;
    }

    private function isValidCssIdent(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $token);
    }
}
