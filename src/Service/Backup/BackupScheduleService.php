<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Backup;

use AvalynxOffice\Repository\AppSettingRepository;

/**
 * Settings and state of the automatic backups, stored in the app_setting
 * key/value store (same pattern as SyncSettingsService). The schedule is
 * re-read on every scheduler pass, so admin changes apply without a
 * restart — for the Docker loop as well as for the All-Inkl cron URL.
 */
final readonly class BackupScheduleService
{
    public const string KEY_ENABLED = 'backup.auto.enabled';
    public const string KEY_INTERVAL_HOURS = 'backup.auto.interval_hours';
    public const string KEY_INCLUDE_FILES = 'backup.auto.include_files';
    public const string KEY_RETENTION = 'backup.auto.retention';
    public const string KEY_LAST_RUN_AT = 'backup.auto.last_run_at';
    public const string KEY_LAST_RESULT = 'backup.auto.last_result';
    public const string KEY_TOKEN = 'backup.auto.cron_token';

    public const int DEFAULT_INTERVAL_HOURS = 24;
    public const int DEFAULT_RETENTION = 7;
    public const int MIN_INTERVAL_HOURS = 1;
    public const int MAX_INTERVAL_HOURS = 168;
    public const int MIN_RETENTION = 1;
    public const int MAX_RETENTION = 99;

    public function __construct(
        private AppSettingRepository $settingRepository,
    ) {
    }

    /**
     * @return array{enabled: bool, intervalHours: int, includeFiles: bool, retention: int}
     */
    public function all(): array
    {
        return [
            'enabled' => $this->bool(self::KEY_ENABLED, false),
            'intervalHours' => $this->int(self::KEY_INTERVAL_HOURS, self::DEFAULT_INTERVAL_HOURS),
            'includeFiles' => $this->bool(self::KEY_INCLUDE_FILES, true),
            'retention' => $this->int(self::KEY_RETENTION, self::DEFAULT_RETENTION),
        ];
    }

    /**
     * Persists the given values (clamped to sane bounds). Keys that are
     * absent from $values keep their stored setting.
     *
     * @param array{enabled?: bool, intervalHours?: int, includeFiles?: bool, retention?: int} $values
     */
    public function save(array $values): void
    {
        if (isset($values['enabled'])) {
            $this->settingRepository->setValue(self::KEY_ENABLED, $values['enabled'] ? '1' : '0');
        }

        if (isset($values['intervalHours'])) {
            $hours = max(self::MIN_INTERVAL_HOURS, min(self::MAX_INTERVAL_HOURS, $values['intervalHours']));
            $this->settingRepository->setValue(self::KEY_INTERVAL_HOURS, (string) $hours);
        }

        if (isset($values['includeFiles'])) {
            $this->settingRepository->setValue(self::KEY_INCLUDE_FILES, $values['includeFiles'] ? '1' : '0');
        }

        if (isset($values['retention'])) {
            $retention = max(self::MIN_RETENTION, min(self::MAX_RETENTION, $values['retention']));
            $this->settingRepository->setValue(self::KEY_RETENTION, (string) $retention);
        }
    }

    /**
     * When the last auto backup ran (ISO-8601 string), null when never.
     */
    public function lastRunAt(): ?\DateTimeImmutable
    {
        $value = $this->settingRepository->getValue(self::KEY_LAST_RUN_AT);
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Human readable outcome of the last auto backup ("ok: <filename>" or
     * "error: <reason>"), null when never run.
     */
    public function lastResult(): ?string
    {
        $value = $this->settingRepository->getValue(self::KEY_LAST_RESULT);

        return null !== $value && '' !== $value ? $value : null;
    }

    public function recordResult(string $result, ?\DateTimeImmutable $at = null): void
    {
        $now = $at ?? new \DateTimeImmutable();
        $this->settingRepository->setValue(self::KEY_LAST_RUN_AT, $now->format(\DateTimeInterface::ATOM));
        $this->settingRepository->setValue(self::KEY_LAST_RESULT, $result);
    }

    /**
     * The secret token for the All-Inkl style cron URL. Generated once on
     * first use; regenerating is an explicit admin action.
     */
    public function cronToken(): string
    {
        $token = $this->settingRepository->getValue(self::KEY_TOKEN);
        if (is_string($token) && 1 === preg_match('/^[0-9a-f]{48}$/', $token)) {
            return $token;
        }

        $token = bin2hex(random_bytes(24));
        $this->settingRepository->setValue(self::KEY_TOKEN, $token);

        return $token;
    }

    public function regenerateCronToken(): string
    {
        $token = bin2hex(random_bytes(24));
        $this->settingRepository->setValue(self::KEY_TOKEN, $token);

        return $token;
    }

    /**
     * Whether an auto backup is due: enabled AND (never ran OR interval
     * elapsed since the last run).
     */
    public function isDue(): bool
    {
        $settings = $this->all();
        if (!$settings['enabled']) {
            return false;
        }

        $lastRunAt = $this->lastRunAt();
        if (null === $lastRunAt) {
            return true;
        }

        return $lastRunAt->getTimestamp() + $settings['intervalHours'] * 3600 <= time();
    }

    /**
     * When the next auto backup is due (null when disabled).
     */
    public function nextRunAt(): ?\DateTimeImmutable
    {
        $settings = $this->all();
        if (!$settings['enabled']) {
            return null;
        }

        $lastRunAt = $this->lastRunAt();
        if (null === $lastRunAt) {
            return new \DateTimeImmutable();
        }

        return $lastRunAt->modify('+'.$settings['intervalHours'].' hours');
    }

    private function int(string $key, int $default): int
    {
        $value = $this->settingRepository->getValue($key);

        return null !== $value && is_numeric($value) ? (int) $value : $default;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->settingRepository->getValue($key);

        return null === $value ? $default : '1' === $value;
    }
}
