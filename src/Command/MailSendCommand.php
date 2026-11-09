<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Service\Mail\MailSendService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:mail:send',
    description: 'Sends all emails that are marked for sending.'
)]
class MailSendCommand extends Command
{
    public function __construct(
        private readonly MailSendService $mailSendService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $messages = $this->mailSendService->sendPending();
        if ([] === $messages) {
            $output->writeln('No pending emails found to send.');

            return Command::SUCCESS;
        }

        foreach ($messages as $message) {
            $output->writeln($message);
        }

        return Command::SUCCESS;
    }
}
