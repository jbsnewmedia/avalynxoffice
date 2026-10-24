<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\ConnectorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:connector:configure-smtp',
    description: 'Configures the SMTP settings of an existing connector.',
)]
class ConnectorConfigureSmtpCommand extends Command
{
    public function __construct(
        private readonly ConnectorRepository $connectorRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'ID oder Domain des Connectors')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'SMTP Host')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'SMTP Port', 465)
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'SMTP Benutzername')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'SMTP Passwort')
            ->addOption('security', null, InputOption::VALUE_REQUIRED, 'SMTP Sicherheit (ssl, tls, none)', 'ssl')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifierArg = $input->getArgument('identifier');
        $identifier = is_string($identifierArg) ? $identifierArg : '';
        $connector = $this->connectorRepository->findOneBy(['domain' => strtolower($identifier)]);
        if (!$connector instanceof Connector) {
            try {
                $connector = $this->connectorRepository->find($identifier);
            } catch (\Throwable) {
                $connector = null;
            }
        }
        if (!$connector instanceof Connector) {
            $io->error(sprintf('Connector with identifier "%s" was not found.', $identifier));

            return Command::FAILURE;
        }
        $host = $input->getOption('host');
        if (is_string($host)) {
            $connector->setSmtpHost($host);
        }
        $port = $input->getOption('port');
        if (is_numeric($port)) {
            $connector->setSmtpPort((int) $port);
        }
        $user = $input->getOption('user');
        if (is_string($user)) {
            $connector->setSmtpUsername($user);
        }
        $password = $input->getOption('password');
        if (is_string($password)) {
            $connector->setSmtpPassword($password);
        }
        $security = $input->getOption('security');
        if (is_string($security)) {
            $connector->setSmtpSecurity($security);
        }
        $this->entityManager->flush();
        $io->success(sprintf('SMTP configuration for connector %s (%s) has been updated.', $connector->getName(), $connector->getDomain()));

        return Command::SUCCESS;
    }
}
