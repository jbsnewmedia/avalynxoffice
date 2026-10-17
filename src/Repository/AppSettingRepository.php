<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppSetting>
 */
class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    /**
     * Reads a setting value by key, returning the default when the key is
     * not stored (or stored without a value).
     */
    public function getValue(string $key, ?string $default = null): ?string
    {
        $setting = $this->findOneBy(['settingKey' => $key]);
        if (null === $setting || null === $setting->getSettingValue()) {
            return $default;
        }

        return $setting->getSettingValue();
    }

    /**
     * Creates or updates a setting row for the given key.
     */
    public function setValue(string $key, string $value): void
    {
        $setting = $this->findOneBy(['settingKey' => $key]);
        if (null === $setting) {
            $setting = new AppSetting($key, $value);
            $this->getEntityManager()->persist($setting);
        } else {
            $setting->setSettingValue($value);
        }
        $this->getEntityManager()->flush();
    }
}
