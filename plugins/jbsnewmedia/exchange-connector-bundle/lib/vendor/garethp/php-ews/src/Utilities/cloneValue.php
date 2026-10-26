<?php

namespace garethp\ews\Utilities;

function cloneValue($value)
{
    if (is_object($value)) {
        return clone $value;
    }

    if (is_array($value)) {
        return array_map(function ($value) {
            return cloneValue($value);
        }, $value);
    }

    return $value;
}
