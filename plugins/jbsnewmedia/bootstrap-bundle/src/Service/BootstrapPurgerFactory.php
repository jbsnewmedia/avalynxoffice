<?php

declare(strict_types=1);

namespace JBSNewMedia\BootstrapBundle\Service;

use JBSNewMedia\CssPurger\Vendors\Bootstrap as BootstrapPurger;

class BootstrapPurgerFactory
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

        return class_exists(BootstrapPurger::class);
    }

    /**
     * @return BootstrapPurger
     */
    public function create(string $cssPath)
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Required class JBSNewMedia\\CssPurger\\Vendors\\Bootstrap not found. Please ensure the package "jbsnewmedia/css-purger" is installed. If you load the BootstrapBundle from source, either: 1) require jbsnewmedia/css-purger in your application, or 2) run "composer install" inside the bundle so its vendor/autoload.php exists.');
        }

        return new BootstrapPurger($cssPath);
    }
}
