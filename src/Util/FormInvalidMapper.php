<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Util;

use Symfony\Component\Form\FormInterface;

/**
 * Builds the "invalid" payload for AvalynxForm: a map of fully qualified
 * input names (e.g. "calendar_event[title]") to their first error message.
 */
final readonly class FormInvalidMapper
{
    /**
     * @template TData
     *
     * @param FormInterface<TData> $form
     *
     * @return array<string, string>
     */
    public static function map(FormInterface $form): array
    {
        $map = [];
        self::collect($form, null, $map);

        return $map;
    }

    /**
     * @template TData
     *
     * @param FormInterface<TData>  $form
     * @param array<string, string> $map
     */
    private static function collect(FormInterface $form, ?string $prefix, array &$map): void
    {
        $name = null === $prefix ? $form->getName() : $prefix.'['.$form->getName().']';

        foreach ($form->getErrors() as $error) {
            $map[$name] ??= $error->getMessage();
            break;
        }

        foreach ($form->all() as $child) {
            self::collect($child, $name, $map);
        }
    }
}
