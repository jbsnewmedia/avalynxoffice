<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Model;

/**
 * Diagnostic record for a single configured license key.
 */
final readonly class LicenseKeyDiagnostic
{
    public function __construct(
        public string $keyPreview,
        public LicenseSource $source,
        public LicenseKeyStatus $status,
        public ?string $keyId = null,
        public ?string $licenseId = null,
        public ?string $organizationId = null,
        public ?int $additionalSeats = null,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
