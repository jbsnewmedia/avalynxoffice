<?php

namespace garethp\ews\API;

class Message extends Type
{
    public function getNonNullResponseMessages()
    {
        return $this->getNonNullItems();
    }
}
