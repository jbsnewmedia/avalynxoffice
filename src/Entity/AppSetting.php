<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Simple key/value store for installation-wide settings (e.g. the theme
 * primary color), edited through the admin area.
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_setting')]
#[ORM\HasLifecycleCallbacks]
class AppSetting
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(name: 'setting_key', type: 'string', length: 190, unique: true)]
    private ?string $settingKey = null;
    #[ORM\Column(name: 'setting_value', type: 'text', nullable: true)]
    private ?string $settingValue = null;

    public function __construct(?string $settingKey = null, ?string $settingValue = null)
    {
        $this->id = Uuid::v7();
        if (null !== $settingKey) {
            $this->settingKey = $settingKey;
        }
        if (null !== $settingValue) {
            $this->settingValue = $settingValue;
        }
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getSettingKey(): ?string
    {
        return $this->settingKey;
    }

    public function setSettingKey(string $settingKey): self
    {
        $this->settingKey = $settingKey;

        return $this;
    }

    public function getSettingValue(): ?string
    {
        return $this->settingValue;
    }

    public function setSettingValue(?string $settingValue): self
    {
        $this->settingValue = $settingValue;

        return $this;
    }
}
