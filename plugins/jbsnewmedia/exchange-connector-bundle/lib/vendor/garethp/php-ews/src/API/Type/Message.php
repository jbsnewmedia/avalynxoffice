<?php

namespace garethp\ews\API\Type;

use garethp\ews\API\Type;

/**
 * Class Message
 *
 * @method boolean isRead()
 * @package garethp\ews\API\Type
 */
class Message extends Type
{
    public function isAReply()
    {
        return ($this->exists('InReplyTo') && $this->InReplyTo !== null);
    }
}
