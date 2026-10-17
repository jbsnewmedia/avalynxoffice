<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Provider;

use AvalynxOffice\Licensing\Model\LicenseKeyReference;

interface LicenseKeyProviderInterface
{
    /**
     * @return list<LicenseKeyReference>
     */
    public function getLicenseKeys(): array;
}
