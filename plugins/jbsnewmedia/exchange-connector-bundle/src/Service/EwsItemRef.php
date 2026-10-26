<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Service;

/**
 * Reference to one item on the Exchange server (EWS ItemId + ChangeKey).
 */
final readonly class EwsItemRef
{
    public function __construct(
        public string $id,
        public ?string $changeKey,
    ) {
    }

    /**
     * @return array{Id: string, ChangeKey: string|null}
     */
    public function toArray(): array
    {
        return ['Id' => $this->id, 'ChangeKey' => $this->changeKey];
    }
}
