<?php

namespace garethp\ews\API\Type;

use garethp\ews\API\Type;

/**
 * Class representing DateTimePrecisionType
 *
 * Precision for returned DateTime values
 * XSD Type: DateTimePrecisionType
 */
class DateTimePrecisionType extends Type
{

    const SECONDS = 'SECONDS';

    const MILLISECONDS = 'MILLISECONDS';
}
