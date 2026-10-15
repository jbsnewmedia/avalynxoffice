<?php

declare(strict_types=1);

namespace JBSNewMedia\BootstrapBundle\Service;

use ScssPhp\ScssPhp\Compiler;

class ScssCompilerFactory
{
    public function __construct(private readonly string $autoloadPath = __DIR__.'/../../vendor/autoload.php')
    {
    }

    public function isAvailable(): bool
    {
        if (is_file($this->autoloadPath)) {
            /** @noinspection PhpIncludeInspection */
            @require_once $this->autoloadPath;
        }

        return class_exists(Compiler::class);
    }

    /**
     * @return Compiler
     */
    public function create()
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Required class ScssPhp\\ScssPhp\\Compiler not found.');
        }

        return new Compiler();
    }
}
