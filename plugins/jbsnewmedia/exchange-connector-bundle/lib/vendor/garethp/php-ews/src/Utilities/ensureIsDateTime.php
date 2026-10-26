<?php

namespace garethp\ews\Utilities;

/**
 * @param $dateTime
 * @return \DateTime
 */
function ensureIsDateTime($dateTime)
{
    if (!$dateTime instanceof \DateTime) {
        return new \DateTime($dateTime);
    }

    return $dateTime;
}
