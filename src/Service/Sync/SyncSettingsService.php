<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Sync;

use AvalynxOffice\Repository\AppSettingRepository;

/**
 * Configuration of the sync service, stored in the app_setting key/value
 * store and edited through the admin system status page. The sync daemon
 * reads the values on every pass, so changes apply without a restart.
 */
final readonly class SyncSettingsService
{
    public const string KEY_INTERVAL = 'sync.interval_seconds';
    public const string KEY_BUSY_INTERVAL = 'sync.busy_interval_seconds';
    public const string KEY_MAILS_LIMIT = 'sync.mails_limit';
    public const string KEY_CONTACTS_LIMIT = 'sync.contacts_limit';
    public const string KEY_CALENDAR_LIMIT = 'sync.calendar_limit';

    public const int DEFAULT_INTERVAL = 300;
    public const int DEFAULT_BUSY_INTERVAL = 2;
    public const int DEFAULT_MAILS_LIMIT = 100;
    public const int DEFAULT_CONTACTS_LIMIT = 100;
    public const int DEFAULT_CALENDAR_LIMIT = 100;

    public const int MAX_VALUE = 100000;

    public function __construct(
        private AppSettingRepository $settingRepository,
    ) {
    }

    /**
     * @return array{intervalSeconds: int, busyIntervalSeconds: int, mailsLimit: int, contactsLimit: int, calendarLimit: int}
     */
    public function all(): array
    {
        return [
            'intervalSeconds' => $this->int(self::KEY_INTERVAL, self::DEFAULT_INTERVAL),
            'busyIntervalSeconds' => $this->int(self::KEY_BUSY_INTERVAL, self::DEFAULT_BUSY_INTERVAL),
            'mailsLimit' => $this->int(self::KEY_MAILS_LIMIT, self::DEFAULT_MAILS_LIMIT),
            'contactsLimit' => $this->int(self::KEY_CONTACTS_LIMIT, self::DEFAULT_CONTACTS_LIMIT),
            'calendarLimit' => $this->int(self::KEY_CALENDAR_LIMIT, self::DEFAULT_CALENDAR_LIMIT),
        ];
    }

    /**
     * Persists the given values (clamped to sane bounds).
     *
     * @param array{intervalSeconds?: int, busyIntervalSeconds?: int, mailsLimit?: int, contactsLimit?: int, calendarLimit?: int} $values
     */
    public function save(array $values): void
    {
        $map = [
            self::KEY_INTERVAL => $values['intervalSeconds'] ?? null,
            self::KEY_BUSY_INTERVAL => $values['busyIntervalSeconds'] ?? null,
            self::KEY_MAILS_LIMIT => $values['mailsLimit'] ?? null,
            self::KEY_CONTACTS_LIMIT => $values['contactsLimit'] ?? null,
            self::KEY_CALENDAR_LIMIT => $values['calendarLimit'] ?? null,
        ];

        foreach ($map as $key => $value) {
            if (null === $value) {
                continue;
            }

            $this->settingRepository->setValue($key, (string) min(self::MAX_VALUE, max(0, $value)));
        }
    }

    private function int(string $key, int $default): int
    {
        $value = $this->settingRepository->getValue($key);

        return null !== $value && is_numeric($value) ? (int) $value : $default;
    }
}
