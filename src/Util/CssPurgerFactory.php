<?php

declare(strict_types=1);

namespace App\Util;

use JBSNewMedia\CssPurger\CssPurger;
use JBSNewMedia\CssPurger\Vendors\Bootstrap as VendorBootstrapPurger;

class CssPurgerFactory
{
    public function create(string $path): CssPurger
    {
        return new VendorBootstrapPurger($path);
    }
}
