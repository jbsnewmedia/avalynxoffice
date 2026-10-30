<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Removes "active" HTML elements from an email body before it is embedded
 * into the sandboxed mail iframe. Elements such as <meta http-equiv="refresh">,
 * <base> or <link> would otherwise be able to bypass the iframe CSP and the
 * "external content" opt-in; <script>, <object>, <embed>, <iframe>, <frame>,
 * <form> and <applet> are removed for the same reason.
 *
 * Intentionally regex based (no DOM parser): the result only has to be safe
 * for the sandboxed frame, not a normalized document.
 */
final class EmailStripActiveTagsExtension extends AbstractExtension
{
    /** Elements removed together with their content. */
    private const array CONTAINER_TAGS = ['script', 'object', 'iframe', 'frame', 'form', 'applet'];

    /** Elements of which only the tags (opening/closing/self-closing) are removed. */
    private const array TAG_ONLY_TAGS = ['meta', 'link', 'base', 'embed', 'frameset', 'script', 'object', 'iframe', 'frame', 'form', 'applet'];

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('email_strip_active_tags', $this->stripActiveTags(...)),
        ];
    }

    public function stripActiveTags(?string $html): string
    {
        if (null === $html || '' === $html) {
            return '';
        }

        // Remove container elements with their content (case-insensitive, attribute and
        // whitespace tolerant, closing tag may carry whitespace before ">").
        $containers = implode('|', self::CONTAINER_TAGS);
        $containerPattern = '#<\s*('.$containers.')(?![a-z0-9:-])[^>]*>.*?<\s*/\s*\1\s*>#is';
        $previous = null;
        while ($previous !== $html) {
            $previous = $html;
            $html = preg_replace($containerPattern, '', $html) ?? $html;
        }

        // Remove remaining opening/self-closing/closing tags of unwanted elements.
        $tags = implode('|', self::TAG_ONLY_TAGS);
        $tagPattern = '#<\s*/?\s*(?:'.$tags.')(?![a-z0-9:-])(?:"[^"]*"|\'[^\']*\'|[^>\'"])*>?#i';
        $previous = null;
        while ($previous !== $html) {
            $previous = $html;
            $html = preg_replace($tagPattern, '', $html) ?? $html;
        }

        return $html;
    }
}
