<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Util;

class CssPurger
{
    /**
     * @param array<string> $allowedClasses
     * @param array<string> $safelist
     *
     * @return array{css: string, stats: array<string, int>}
     */
    public function purgeWithReport(string $css, array $allowedClasses, array $safelist = []): array
    {
        $allowedSet = $this->buildAllowedSet($allowedClasses, $safelist);
        /** @var array<string, int> $stats */
        $stats = [
            'rules_total' => 0,
            'rules_kept' => 0,
            'rules_removed' => 0,
            'selectors_kept' => 0,
            'selectors_removed' => 0,
        ];
        $purged = $this->purgeCssRecursive($css, $allowedSet, $stats);

        return [
            'css' => $purged,
            'stats' => $stats,
        ];
    }

    /**
     * @param array<string, bool> $allowedSet
     * @param array<string, int>  $stats
     */
    private function purgeCssRecursive(string $css, array $allowedSet, array &$stats): string
    {
        $len = strlen($css);
        $i = 0;
        $out = '';
        while ($i < $len) {
            if (ctype_space($css[$i])) {
                $out .= $css[$i];
                ++$i;
                continue;
            }
            if ($i + 1 < $len && '/' === $css[$i] && '*' === $css[$i + 1]) {
                $end = strpos($css, '*/', $i + 2);
                if (false === $end) {
                    $out .= substr($css, $i);
                    break;
                }
                $out .= substr($css, $i, $end + 2 - $i);
                $i = $end + 2;
                continue;
            }
            $bracePos = strpos($css, '{', $i);
            if (false === $bracePos) {
                $out .= substr($css, $i);
                break;
            }
            $selector = trim(substr($css, $i, $bracePos - $i));
            $depth = 0;
            $j = $bracePos;
            while ($j < $len) {
                $ch = $css[$j];
                if ('{' === $ch) {
                    ++$depth;
                } elseif ('}' === $ch) {
                    --$depth;
                    if (0 === $depth) {
                        ++$j;
                        break;
                    }
                } elseif ('"' === $ch || "'" === $ch) {
                    $q = $ch;
                    ++$j;
                    while ($j < $len) {
                        if ('\\' === $css[$j]) {
                            $j += 2;
                            continue;
                        }
                        if ($css[$j] === $q) {
                            ++$j;
                            break;
                        }
                        ++$j;
                    }
                    continue;
                }

                ++$j;
            }
            if ($j > $len) {
                $j = $len;
            }
            $block = substr($css, $bracePos + 1, $j - $bracePos - 2); // Inhalt ohne äußere {}
            if ('' === $selector) {
                $i = $j;
                continue;
            }
            if (str_starts_with($selector, '@')) {
                $innerPurged = $this->purgeCssRecursive($block, $allowedSet, $stats);
                $out .= $selector.'{'.$innerPurged.'}';
                $i = $j;
                continue;
            }
            ++$stats['rules_total'];
            $selectors = array_map(trim(...), explode(',', $selector));
            $keptSelectors = [];
            foreach ($selectors as $sel) {
                if ('' === $sel) {
                    continue;
                }
                if ($this->selectorMatchesAllowed($sel, $allowedSet)) {
                    $keptSelectors[] = $sel;
                    ++$stats['selectors_kept'];
                } else {
                    ++$stats['selectors_removed'];
                }
            }
            if ($keptSelectors) {
                $out .= implode(',', $keptSelectors).'{'.$block.'}';
                ++$stats['rules_kept'];
            } else {
                ++$stats['rules_removed'];
            }
            $i = $j;
        }

        return $out;
    }

    /**
     * @param array<string, bool> $allowedSet
     */
    private function selectorMatchesAllowed(string $selector, array $allowedSet): bool
    {
        if (!preg_match_all('/\\.([A-Za-z0-9_-]+)/', $selector, $m)) {
            return false;
        }

        return array_any($m[1], fn ($cls) => isset($allowedSet[$cls]));
    }

    /**
     * @param array<string> $allowedClasses
     * @param array<string> $safelist
     *
     * @return array<string, bool>
     */
    private function buildAllowedSet(array $allowedClasses, array $safelist): array
    {
        $set = [];
        foreach ($allowedClasses as $c) {
            $c = trim((string) $c);
            if ('' !== $c) {
                $set[$c] = true;
            }
        }
        foreach ($safelist as $c) {
            $c = trim((string) $c);
            if ('' !== $c) {
                $set[$c] = true;
            }
        }

        return $set;
    }
}
