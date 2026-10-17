<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Util;

use JBSNewMedia\CssPurger\CssPurger;
use JBSNewMedia\CssPurger\Vendors\Bootstrap as VendorBootstrapPurger;

class CssPurgerFactory
{
    public function create(string $path): CssPurger
    {
        return new VendorBootstrapPurger($path);
    }
}
