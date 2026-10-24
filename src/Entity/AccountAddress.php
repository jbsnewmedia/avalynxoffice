<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'account_address')]
#[ORM\UniqueConstraint(name: 'uniq_account_address', columns: ['account_id', 'address_id'])]
#[ORM\HasLifecycleCallbacks]
class AccountAddress
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'addressLinks')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Account $account = null;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Address::class)]
    #[ORM\JoinColumn(name: 'address_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Address $address = null;

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getAddress(): ?Address
    {
        return $this->address;
    }

    public function setAddress(?Address $address): self
    {
        $this->address = $address;

        return $this;
    }
}
