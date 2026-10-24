<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Plugin\Contract;

use AvalynxOffice\Entity\Connector;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Capability interface for connector plugins that send data (Ausgabe),
 * e.g. mail delivery via SMTP.
 */
interface ConnectorMailerInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function createMailer(Connector $connector, array $config): MailerInterface;
}
