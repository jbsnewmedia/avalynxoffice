<?php

declare(strict_types=1);

namespace App\Licensing\Provider;

use App\Licensing\Model\LicenseKeyReference;

interface LicenseKeyProviderInterface
{
    /**
     * @return list<LicenseKeyReference>
     */
    public function getLicenseKeys(): array;
}
