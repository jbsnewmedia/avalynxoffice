<?php

namespace garethp\ews\API;

class MiddlewareRequest
{
    protected $name;

    protected $arguments;

    protected $options;

    protected function __construct($name, $arguments, $options)
    {
        $this->name = $name;
        $this->arguments = $arguments;
        $this->options = $options;
    }

    public static function newRequest($name, $arguments, $options)
    {
        $request = new static($name, $arguments, $options);
        return $request;
    }

    public function getRequest()
    {
        return $this->arguments[0];
    }

    public function setRequest($request)
    {
        $this->arguments[0] = $request;

        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function getArguments()
    {
        return $this->arguments;
    }
}
