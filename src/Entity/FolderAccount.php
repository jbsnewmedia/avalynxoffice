<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'account_folder')]
#[ORM\UniqueConstraint(name: 'uniq_account_folder', columns: ['account_id', 'folder_id'])]
#[ORM\HasLifecycleCallbacks]
class FolderAccount
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'folderLinks')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id')]
    private ?Account $account = null;
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Folder::class, inversedBy: 'accountLinks', cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id')]
    private ?Folder $folder = null;
    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getFolder(): ?Folder
    {
        return $this->folder;
    }

    public function setFolder(?Folder $folder): self
    {
        $this->folder = $folder;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
