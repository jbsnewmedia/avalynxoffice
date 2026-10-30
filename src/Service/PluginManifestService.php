<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

class PluginManifestService
{
    public const MANIFEST_RELATIVE_PATH = 'plugins/plugins.json';

    /**
     * @param bool $autoRefresh Regenerate a missing or stale manifest on load.
     *                          Disabled for production web requests: there
     *                          the manifest must already exist (generated
     *                          at build time via app:plugin:manifest).
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly bool $autoRefresh = true,
    ) {
    }

    /**
     * @return array{plugins: list<array<string, mixed>>}
     */
    public function loadManifest(): array
    {
        if ($this->autoRefresh) {
            if (!$this->isManifestFresh()) {
                return $this->refreshManifest();
            }
        } elseif (!is_file($this->getManifestPath())) {
            throw new \RuntimeException('plugins/plugins.json is missing. Generate it with "php bin/console app:plugin:manifest" (the manifest is not regenerated automatically outside the dev environment).');
        }

        try {
            $manifest = $this->readJsonFile($this->getManifestPath());
        } catch (\JsonException $exception) {
            throw new \RuntimeException('plugins/plugins.json is not valid JSON.', 0, $exception);
        }

        $plugins = $this->extractPluginList($manifest);
        if (null === $plugins) {
            throw new \RuntimeException('plugins/plugins.json does not have the expected format.');
        }

        return ['plugins' => $plugins];
    }

    /**
     * @return array{plugins: list<array<string, mixed>>}
     */
    public function refreshManifest(): array
    {
        $existingManifest = $this->readManifestFile();
        $existingPluginStates = $this->extractPluginStates($existingManifest);

        $manifest = [
            'plugins' => $this->discoverPlugins($existingPluginStates),
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$this->writeFile($this->getManifestPath(), $json."\n")) {
            throw new \RuntimeException('plugins/plugins.json could not be written.');
        }

        return $manifest;
    }

    public function getManifestPath(): string
    {
        return $this->projectDir.'/'.self::MANIFEST_RELATIVE_PATH;
    }

    /**
     * Resolves a manifest path and guarantees that it points into the
     * plugins/ directory. Manifest entries end up in require/import calls, so
     * traversal (".."), absolute paths and symlinks escaping plugins/ are
     * rejected.
     */
    public function toAbsolutePath(string $relativePath): string
    {
        $pluginsDir = $this->normalizePath($this->projectDir.'/plugins');
        $candidate = $this->normalizePath($this->projectDir.'/'.ltrim(str_replace('\\', '/', $relativePath), '/'));

        if (!$this->isInsideDirectory($candidate, $pluginsDir)) {
            throw new \RuntimeException(sprintf('Plugin path "%s" is outside of the plugins directory.', $relativePath));
        }

        // Symlinks are only visible to realpath(); compare the resolved
        // locations when the path exists.
        $realCandidate = realpath($candidate);
        $realPluginsDir = realpath($pluginsDir);
        if (false !== $realCandidate && false !== $realPluginsDir && !$this->isInsideDirectory($realCandidate, $realPluginsDir)) {
            throw new \RuntimeException(sprintf('Plugin path "%s" resolves outside of the plugins directory.', $relativePath));
        }

        return $candidate;
    }

    private function isInsideDirectory(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, '/').'/');
    }

    /**
     * Lexically resolves "." and ".." segments (the path does not need to exist).
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '').implode('/', $segments);
    }

    protected function isManifestFresh(): bool
    {
        $manifestPath = $this->getManifestPath();
        if (!is_file($manifestPath)) {
            return false;
        }

        $manifestMtime = $this->getFileMTime($manifestPath);
        if (false === $manifestMtime) {
            return false;
        }

        try {
            $manifest = $this->readJsonFile($manifestPath);
        } catch (\JsonException) {
            return false;
        }

        $pluginList = $this->extractPluginList($manifest);
        if (null === $pluginList) {
            return false;
        }

        $discoveredPluginPaths = [];
        foreach (glob($this->projectDir.'/plugins/*/*/composer.json') ?: [] as $composerFile) {
            $composerMtime = filemtime($composerFile);
            if (false === $composerMtime || $composerMtime > $manifestMtime) {
                return false;
            }

            $discoveredPluginPaths[] = $this->toRelativePath(dirname($composerFile));
        }

        sort($discoveredPluginPaths);

        $manifestPluginPaths = [];
        foreach ($pluginList as $plugin) {
            $path = $plugin['path'] ?? null;
            if (is_string($path)) {
                $manifestPluginPaths[] = $path;
            }
        }

        sort($manifestPluginPaths);

        return $discoveredPluginPaths === $manifestPluginPaths;
    }

    /**
     * @param array<string, bool> $existingPluginStates
     *
     * @return list<array<string, mixed>>
     */
    private function discoverPlugins(array $existingPluginStates = []): array
    {
        $plugins = [];

        foreach (glob($this->projectDir.'/plugins/*/*/composer.json') ?: [] as $composerFile) {
            $pluginDir = dirname($composerFile);
            $relativePluginDir = $this->toRelativePath($pluginDir);
            $composer = $this->decodeComposerFile($composerFile);
            $bundleClass = $this->getNestedString($composer, 'extra', 'symfony', 'bundle');

            if (!is_string($bundleClass) || '' === $bundleClass) {
                continue;
            }

            $plugin = [
                'package' => is_string($composer['name'] ?? null) ? $composer['name'] : basename($pluginDir),
                'path' => $relativePluginDir,
                'bundle' => $bundleClass,
                'active' => array_key_exists($relativePluginDir, $existingPluginStates)
                    ? $existingPluginStates[$relativePluginDir]
                    : (array_key_exists($bundleClass, $existingPluginStates)
                        ? $existingPluginStates[$bundleClass]
                        : true),
                'environments' => $this->normalizeEnvironments($composer),
                'autoload' => [
                    'psr-4' => $this->normalizePsr4Autoload($this->getNestedArray($composer, 'autoload', 'psr-4'), $pluginDir),
                ],
            ];

            $routes = $this->createRouteFallback($bundleClass, $pluginDir, $relativePluginDir);
            if (null !== $routes) {
                $plugin['routes'] = $routes;
            }

            $plugins[] = $plugin;
        }

        usort(
            $plugins,
            static fn (array $left, array $right): int => ($left['package'] <=> $right['package']) ?: ($left['bundle'] <=> $right['bundle']),
        );

        return $plugins;
    }

    /**
     * @return array{plugins: list<array<string, mixed>>}|null
     */
    private function readManifestFile(): ?array
    {
        $manifestPath = $this->getManifestPath();
        if (!is_file($manifestPath)) {
            return null;
        }

        try {
            $manifest = $this->readJsonFile($manifestPath);
        } catch (\JsonException) {
            return null;
        }

        $plugins = $this->extractPluginList($manifest);
        if (null === $plugins) {
            return null;
        }

        return ['plugins' => $plugins];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function extractPluginList(mixed $manifest): ?array
    {
        if (!is_array($manifest) || !isset($manifest['plugins']) || !is_array($manifest['plugins']) || !array_is_list($manifest['plugins'])) {
            return null;
        }

        $plugins = [];
        foreach ($manifest['plugins'] as $plugin) {
            $entry = $this->stringKeyedArray($plugin);
            if (null === $entry) {
                return null;
            }

            $plugins[] = $entry;
        }

        return $plugins;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stringKeyedArray(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }

            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @param array{plugins: list<array<string, mixed>>}|null $manifest
     *
     * @return array<string, bool>
     */
    private function extractPluginStates(?array $manifest): array
    {
        if (null === $manifest) {
            return [];
        }

        $states = [];
        foreach ($manifest['plugins'] as $plugin) {
            $isActive = (bool) ($plugin['active'] ?? true);

            if (is_string($plugin['path'] ?? null)) {
                $states[$plugin['path']] = $isActive;
            }

            if (is_string($plugin['bundle'] ?? null)) {
                $states[$plugin['bundle']] = $isActive;
            }
        }

        return $states;
    }

    /**
     * @param array<mixed, mixed> $autoload
     *
     * @return array<string, list<string>>
     */
    private function normalizePsr4Autoload(array $autoload, string $pluginDir): array
    {
        $normalized = [];

        foreach ($autoload as $namespace => $paths) {
            if (!is_string($namespace)) {
                continue;
            }

            $pathList = is_array($paths) ? $paths : [$paths];
            foreach ($pathList as $path) {
                if (!is_string($path) || '' === $path) {
                    continue;
                }

                $absolutePath = $pluginDir.'/'.trim($path, '/');
                $normalized[$namespace] ??= [];
                $normalized[$namespace][] = $this->toRelativePath($absolutePath);
            }

            if (isset($normalized[$namespace])) {
                $normalized[$namespace] = array_values(array_unique($normalized[$namespace]));
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $composer
     *
     * @return list<string>
     */
    private function normalizeEnvironments(array $composer): array
    {
        $environments = $this->getNestedArray($composer, 'extra', 'symfony', 'environments');
        if ([] === $environments) {
            return ['all'];
        }

        $normalized = [];
        foreach ($environments as $environment) {
            if (is_string($environment) && '' !== $environment) {
                $normalized[] = $environment;
            }
        }

        return [] === $normalized ? ['all'] : array_values(array_unique($normalized));
    }

    /**
     * @return array{resource: array{path: string, namespace: string}, type: string}|null
     */
    private function createRouteFallback(string $bundleClass, string $pluginDir, string $relativePluginDir): ?array
    {
        if (is_file($pluginDir.'/config/routes.yaml') || is_file($pluginDir.'/config/routes.php')) {
            return null;
        }

        $controllerDir = $pluginDir.'/src/Controller';
        if (!is_dir($controllerDir)) {
            return null;
        }

        $separatorPosition = strrpos($bundleClass, '\\');
        if (false === $separatorPosition) {
            return null;
        }

        return [
            'resource' => [
                'path' => $relativePluginDir.'/src/Controller',
                'namespace' => substr($bundleClass, 0, $separatorPosition).'\\Controller',
            ],
            'type' => 'attribute',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeComposerFile(string $composerFile): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('Plugin file "%s" contains invalid JSON.', $composerFile), 0, $exception);
        }

        $result = $this->stringKeyedArray($decoded);
        if (null === $result) {
            throw new \RuntimeException(sprintf('Plugin file "%s" does not have the expected format.', $composerFile));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function getNestedArray(array $source, string ...$keys): array
    {
        $value = $source;

        foreach ($keys as $key) {
            if (!array_key_exists($key, $value) || !is_array($value[$key])) {
                return [];
            }

            /** @var array<string, mixed> $value */
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function getNestedString(array $source, string ...$keys): ?string
    {
        if ([] === $keys) {
            return null;
        }

        $lastKey = array_last($keys);
        $parentKeys = array_slice($keys, 0, -1);

        $parent = [] === $parentKeys ? $source : $this->getNestedArray($source, ...$parentKeys);
        $value = $parent[$lastKey] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function toRelativePath(string $absolutePath): string
    {
        $projectPath = rtrim(str_replace('\\', '/', $this->projectDir), '/');
        $normalizedAbsolutePath = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($normalizedAbsolutePath, $projectPath.'/')) {
            return substr($normalizedAbsolutePath, strlen($projectPath) + 1);
        }

        return ltrim($normalizedAbsolutePath, '/');
    }

    /**
     * @return array<string, mixed>|list<mixed>
     *
     * @throws \JsonException
     */
    protected function readJsonFile(string $path): array
    {
        /** @var array<string, mixed>|list<mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    protected function writeFile(string $path, string $contents): bool
    {
        return false !== file_put_contents($path, $contents);
    }

    protected function getFileMTime(string $path): int|false
    {
        return filemtime($path);
    }
}
