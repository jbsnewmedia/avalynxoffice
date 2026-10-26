<?php
/**
 * Contains \garethp\ews\API\Enumeration.
 */

namespace garethp\ews\API;

/**
 * Base class for Exchange Web Service Enumerations.
 *
 * @package php-ews\Enumeration
 */
class Enumeration extends Type
{
    /**
     * Element value.
     *
     * @var string
     */
    public $_;

    /**
     * Returns the value of this enumeration as a string..
     *
     * @return string
     */
    public function __toString()
    {
        return $this->_;
    }
}
