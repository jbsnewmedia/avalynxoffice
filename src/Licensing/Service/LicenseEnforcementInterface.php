<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Service;

use AvalynxOffice\Licensing\Exception\LicenseReadOnlyException;

/**
 * Central enforcement contract for user-initiated write operations.
 *
 * Technical system writes (mail import, folder synchronization, licensing
 * state, security logging) must NOT call this service.
 */
interface LicenseEnforcementInterface
{
    /**
     * Blocks user-initiated write operations in read-only mode.
     *
     * @throws LicenseReadOnlyException when the installation is read-only
     */
    public function assertCanPerform(LicenseOperation $operation): void;

    /**
     * Guards the creation or activation of an additional user account.
     * When called inside an active transaction, the license state row is
     * locked pessimistically so that concurrent activations cannot slip
     * past the quota.
     *
     * @throws LicenseReadOnlyException in read-only mode
     */
    public function assertUserSeatAvailable(): void;

    /**
     * Whether the installation is currently in read-only mode.
     */
    public function isReadOnly(): bool;
}
