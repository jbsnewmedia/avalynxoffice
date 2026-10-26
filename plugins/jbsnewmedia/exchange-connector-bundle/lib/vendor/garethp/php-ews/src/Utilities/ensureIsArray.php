<?php

namespace garethp\ews\Utilities;

use garethp\ews\API\Type;

/**
 * @param $input
 * @param $checkAssoc
 * @return array
 */
function ensureIsArray($input, $checkAssoc = false)
{
    if (!is_array($input)) {
        return [$input];
    }

    if ($checkAssoc && Type::arrayIsAssoc($input)) {
        return [$input];
    }

    return $input;
}

function ensureIsMailbox($input)
{
    if (is_string($input)) {
        $address = new Type\Mailbox();
        $address->setEmailAddress($input);
        $input = $address;
    }

    return $input;
}
