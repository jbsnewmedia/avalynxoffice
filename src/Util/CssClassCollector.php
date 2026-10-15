<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Bundle\TwigBundle\TemplateIterator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Error\LoaderError;

class CssClassCollector
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly TwigEnvironment $twig,
        private readonly ParameterBagInterface $params,
    ) {
    }

    /**
     * @return array<string>
     */
    public function collectAll(): array
    {
        /** @var array<string, array<string>> $byTemplate */
        $byTemplate = $this->collectGrouped();
        /** @var array<string, bool> $all */
        $all = [];
        foreach ($byTemplate as $classes) {
            foreach ($classes as $cls) {
                $all[$cls] = true;
            }
        }
        $classList = array_keys($all);
        sort($classList, SORT_NATURAL | SORT_FLAG_CASE);

        return $classList;
    }

    /**
     * @return array<string, array<string>>
     */
    public function collectGrouped(): array
    {
        $defaultPathRaw = $this->params->has('twig.default_path') ? $this->params->get('twig.default_path') : null;
        $defaultPath = is_string($defaultPathRaw) ? $defaultPathRaw : null;
        $twigPathsRaw = $this->params->has('twig.paths') ? $this->params->get('twig.paths') : [];
        $twigPaths = is_array($twigPathsRaw) ? $twigPathsRaw : [];
        $iterator = new TemplateIterator(
            kernel: $this->kernel,
            paths: $twigPaths,
            defaultPath: $defaultPath,
            namePatterns: ['*.twig']
        );
        /** @var array<string, array<string>> $byTemplate */
        $byTemplate = [];
        foreach ($iterator as $templateName) {
            try {
                $source = $this->twig->getLoader()->getSourceContext($templateName)->getCode();
            } catch (LoaderError) {
                continue;
            }
            $classes = $this->extractCssClasses($source);
            if ($classes) {
                sort($classes, SORT_NATURAL | SORT_FLAG_CASE);
                $byTemplate[$templateName] = $classes;
            }
        }
        ksort($byTemplate);

        return $byTemplate;
    }

    /**
     * @return array<string>
     */
    public function extractCssClasses(string $code): array
    {
        $code = preg_replace('/\{#.*?#\}/s', '', $code) ?? $code;
        /** @var array<string, bool> $classes */
        $classes = [];
        $matches = [];
        preg_match_all('/class\s*=\s*(["\'])\s*(.*?)\1/s', $code, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $raw = (string) $m[2];
            $raw = preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', ' ', $raw) ?? $raw;
            $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
            $tokens = array_filter(explode(' ', trim($raw)));
            foreach ($tokens as $t) {
                if (str_starts_with($t, '{{') || str_starts_with($t, '{%')) {
                    continue;
                }
                if (!preg_match('/^[A-Za-z0-9_:\/\[\].%\-]+$/', $t)) {
                    $t = trim($t, "\t\n\r \0\x0B'\".,:;|()");
                }
                if ('' !== $t) {
                    $classes[$t] = true;
                }
            }
        }

        return array_keys($classes);
    }
}
