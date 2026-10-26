<?php

namespace garethp\ews\API;

class MiddlewareResponse
{
    protected $response;
    
    protected function __construct($response = null)
    {
        $this->response = $response;
    }

    public static function newResponse($response = null)
    {
        $response = new static($response);
        return $response;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function setResponse($response)
    {
        $this->response = $response;

        return $this;
    }
}
