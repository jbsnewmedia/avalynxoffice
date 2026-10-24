<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_account')]
#[ORM\UniqueConstraint(name: 'uniq_user_account', columns: ['user_id', 'account_id'])]
#[ORM\HasLifecycleCallbacks]
class UserAccount
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'accountLinks')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private ?User $user = null;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'userLinks')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id')]
    private ?Account $account = null;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
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
}
