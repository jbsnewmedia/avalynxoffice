<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Eas;

use AvalynxOffice\Repository\AppSettingRepository;

/**
 * Global Exchange ActiveSync (EAS) device policies, stored in the
 * app_setting key/value store and edited on the admin EAS policy page.
 *
 * The keys are the raw Z-Push provisioning policy keys (the shipped
 * policies.ini [default] section). The EAS service reads the same store
 * and overrides the default policy at request time. A missing key always
 * means the policy is OFF, so a fresh installation provisions devices
 * without any restriction.
 *
 * Toggle semantics from the admin's point of view: the checkbox switches
 * the RESTRICTION on; the stored value is the Z-Push policy value that
 * activates it (which is why e.g. allowcam stores "0").
 */
final readonly class EasPolicyService
{
    public const string KEY_PREFIX = 'eas.policy.';

    /**
     * Toggle policies: Z-Push key => the policy value that activates the
     * restriction. Unchecked (missing row) means no restriction.
     *
     * @var array<string, string>
     */
    public const array TOGGLES = [
        'devpwenabled' => '1',
        'alphanumpwreq' => '1',
        'allowsimpledevpw' => '0',
        'reqdevenc' => '1',
        'devencenabled' => '1',
        'allowcam' => '0',
        'allowstoragecard' => '0',
        'allowunsignedapps' => '0',
        'allowwifi' => '0',
        'allowtextmessaging' => '0',
        'allowpopimapemail' => '0',
        'allowconsumeremail' => '0',
        'allowbrowser' => '0',
        'allowinternetsharing' => '0',
        'allowbluetooth' => '0',
        'allowirda' => '0',
        'reqmansyncroam' => '1',
    ];

    /**
     * Numeric policies: Z-Push key => [off value, min, max]. An empty
     * stored value (or missing row) resets the policy to the off value.
     *
     * @var array<string, array{off: string, min: int, max: int}>
     */
    public const array NUMBERS = [
        'mindevpwlenngth' => ['off' => '', 'min' => 1, 'max' => 16],
        'mindevcomplexchars' => ['off' => '', 'min' => 1, 'max' => 4],
        'devpwexpiration' => ['off' => '0', 'min' => 0, 'max' => 730],
        'devpwhistory' => ['off' => '0', 'min' => 0, 'max' => 20],
        'maxdevpwfailedattempts' => ['off' => '', 'min' => 4, 'max' => 16],
        'maxinacttimedevlock' => ['off' => '', 'min' => 1, 'max' => 9999],
    ];

    public function __construct(
        private AppSettingRepository $settingRepository,
    ) {
    }

    /**
     * The stored policy deviations keyed by Z-Push policy key. Only the
     * restrictions the admin turned on (and numeric overrides) are stored;
     * everything else is off by definition.
     *
     * @return array<string, string>
     */
    public function stored(): array
    {
        $stored = [];

        foreach ($this->settingRepository->all() as $key => $value) {
            if (!str_starts_with($key, self::KEY_PREFIX)) {
                continue;
            }

            $policyKey = substr($key, strlen(self::KEY_PREFIX));

            if ('' === $value) {
                continue;
            }

            $stored[$policyKey] = $value;
        }

        return $stored;
    }

    /**
     * Persists the given policy deviations. Keys must be known policy
     * keys; a null value (or the key's off value) removes the row so the
     * policy falls back to off.
     *
     * @param array<string, string|null> $values Z-Push policy key => policy value
     */
    public function save(array $values): void
    {
        foreach (self::TOGGLES as $key => $onValue) {
            $value = $values[$key] ?? null;

            if (null === $value) {
                $this->settingRepository->remove(self::KEY_PREFIX.$key);

                continue;
            }

            if ($value !== $onValue) {
                throw new \InvalidArgumentException(sprintf('Invalid value "%s" for policy "%s".', $value, $key));
            }

            $this->settingRepository->setValue(self::KEY_PREFIX.$key, $value);
        }

        foreach (self::NUMBERS as $key => $definition) {
            $value = $values[$key] ?? null;

            if (null === $value || '' === $value || $value === $definition['off']) {
                $this->settingRepository->remove(self::KEY_PREFIX.$key);

                continue;
            }

            $number = (int) $value;

            if (!ctype_digit($value) || $number < $definition['min'] || $number > $definition['max']) {
                throw new \InvalidArgumentException(sprintf('Invalid value "%s" for policy "%s".', $value, $key));
            }

            $this->settingRepository->setValue(self::KEY_PREFIX.$key, (string) $number);
        }

        $knownKeys = array_merge(array_keys(self::TOGGLES), array_keys(self::NUMBERS));

        foreach (array_keys($values) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown policy key "%s".', $key));
            }
        }
    }
}
