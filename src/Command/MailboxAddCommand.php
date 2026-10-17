<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mailbox:add',
    description: 'Creates a new mailbox.',
)]
class MailboxAddCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse der Mailbox')
            ->addArgument('password', InputArgument::REQUIRED, 'Password for POP3/SMTP')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Anzeigename der Mailbox', 'Default Mailbox')
            ->addOption('connector-email', null, InputOption::VALUE_REQUIRED, 'E-Mail-Adresse des Connectors (falls abweichend von der Mailbox-E-Mail)')
            ->addOption('connector-name', null, InputOption::VALUE_REQUIRED, 'Anzeigename des Connectors')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'POP3 Host', 'localhost')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'POP3 Port', 995)
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'POP3 Benutzername (falls abweichend von E-Mail)')
            ->addOption('security', null, InputOption::VALUE_REQUIRED, 'POP3 Sicherheit (ssl, tls, none)', 'ssl')
            ->addOption('smtp-host', null, InputOption::VALUE_REQUIRED, 'SMTP Host')
            ->addOption('smtp-port', null, InputOption::VALUE_REQUIRED, 'SMTP Port', 465)
            ->addOption('smtp-user', null, InputOption::VALUE_REQUIRED, 'SMTP Benutzername (falls abweichend von E-Mail)')
            ->addOption('smtp-password', null, InputOption::VALUE_REQUIRED, 'SMTP Passwort (falls abweichend von POP3-Passwort)')
            ->addOption('smtp-security', null, InputOption::VALUE_REQUIRED, 'SMTP Sicherheit (ssl, tls, none)', 'ssl')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $emailArg = $input->getArgument('email');
        $email = is_string($emailArg) ? $emailArg : '';
        $passwordArg = $input->getArgument('password');
        $password = is_string($passwordArg) ? $passwordArg : '';
        $nameOpt = $input->getOption('name');
        $name = is_string($nameOpt) ? $nameOpt : 'Default Mailbox';
        $connectorEmailOpt = $input->getOption('connector-email');
        $connectorEmail = is_string($connectorEmailOpt) && '' !== $connectorEmailOpt ? $connectorEmailOpt : $email;
        $connectorNameOpt = $input->getOption('connector-name');
        $connectorName = is_string($connectorNameOpt) && '' !== $connectorNameOpt ? $connectorNameOpt : $name.' Connector';
        $hostOpt = $input->getOption('host');
        $host = is_string($hostOpt) ? $hostOpt : 'localhost';
        $portOpt = $input->getOption('port');
        $port = is_numeric($portOpt) ? (int) $portOpt : 995;
        $userOpt = $input->getOption('user');
        $popUser = is_string($userOpt) ? $userOpt : $connectorEmail;
        $securityOpt = $input->getOption('security');
        $security = is_string($securityOpt) ? $securityOpt : 'ssl';
        $smtpHostOpt = $input->getOption('smtp-host');
        $smtpHost = is_string($smtpHostOpt) ? $smtpHostOpt : $host;
        $smtpPortOpt = $input->getOption('smtp-port');
        $smtpPort = is_numeric($smtpPortOpt) ? (int) $smtpPortOpt : 465;
        $smtpUserOpt = $input->getOption('smtp-user');
        $smtpUser = is_string($smtpUserOpt) ? $smtpUserOpt : $connectorEmail;
        $smtpPasswordOpt = $input->getOption('smtp-password');
        $smtpPassword = is_string($smtpPasswordOpt) ? $smtpPasswordOpt : $password;
        $smtpSecurityOpt = $input->getOption('smtp-security');
        $smtpSecurity = is_string($smtpSecurityOpt) ? $smtpSecurityOpt : 'ssl';
        $mailbox = new Mailbox();
        $mailbox->setEmail($email);
        $mailbox->setName($name);
        $connector = new MailboxConnector();
        $connector->setName($connectorName);
        $connector->setEmail($connectorEmail);
        $connector->setPop3Host($host);
        $connector->setPop3Port($port);
        $connector->setPop3Username($popUser);
        $connector->setPop3Password($password);
        $connector->setPop3Security($security);
        $connector->setPop3DeleteAfterFetch(false);
        $connector->setSmtpHost($smtpHost);
        $connector->setSmtpPort($smtpPort);
        $connector->setSmtpUsername($smtpUser);
        $connector->setSmtpPassword($smtpPassword);
        $connector->setSmtpSecurity($smtpSecurity);
        $connector->setIsPrimary(true);
        $mailbox->addConnector($connector);
        $userRepo = $this->entityManager->getRepository(User::class);
        $admin = $userRepo->findOneBy(['email' => 'admin@example.com']) ?: $userRepo->findOneBy([]);
        if ($admin) {
            $admin->addMailbox($mailbox);
            $io->note(sprintf('Mailbox is assigned to user %s.', $admin->getEmail()));
        }
        $this->entityManager->persist($mailbox);
        $this->entityManager->persist($connector);
        $this->entityManager->flush();
        $io->success(sprintf('Mailbox %s (%s) has been created.', $name, $email));

        return Command::SUCCESS;
    }
}
