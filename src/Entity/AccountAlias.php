<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Routes all mail for one local part of a connector's domain to an account
 * (e.g. local part "info" on connector "jbs-newmedia.de" routes
 * info@jbs-newmedia.de). The pair (connector, localPart) is globally unique:
 * every address has exactly one target account and can be moved between
 * accounts at any time.
 */
#[ORM\Entity(repositoryClass: \AvalynxOffice\Repository\AccountAliasRepository::class)]
#[ORM\Table(name: 'account_alias')]
#[ORM\UniqueConstraint(name: 'uniq_account_alias_address', columns: ['connector_id', 'local_part'])]
#[ORM\HasLifecycleCallbacks]
class AccountAlias
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'aliasLinks')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;
    #[ORM\ManyToOne(targetEntity: Connector::class, inversedBy: 'aliases')]
    #[ORM\JoinColumn(name: 'connector_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Connector $connector = null;
    #[ORM\Column(name: 'local_part', type: 'string', length: 190)]
    private string $localPart = '';

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getConnector(): ?Connector
    {
        return $this->connector;
    }

    public function setConnector(?Connector $connector): self
    {
        $this->connector = $connector;

        return $this;
    }

    public function getLocalPart(): string
    {
        return $this->localPart;
    }

    public function setLocalPart(string $localPart): self
    {
        $this->localPart = strtolower(trim($localPart));

        return $this;
    }

    /**
     * Full routing address of this alias, e.g. "info@jbs-newmedia.de".
     */
    public function getAddress(): string
    {
        $connector = $this->connector;

        return $this->localPart.'@'.($connector?->getDomain() ?? 'invalid');
    }
}
