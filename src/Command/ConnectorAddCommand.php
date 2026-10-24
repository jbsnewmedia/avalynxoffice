<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:connector:add',
    description: 'Creates a standalone POP3/SMTP connector for one domain.',
)]
class ConnectorAddCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain the connector fetches mail for (e.g. jbs-newmedia.de)')
            ->addArgument('password', InputArgument::REQUIRED, 'Password for POP3/SMTP')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name of the connector', null)
            ->addOption('default-account', null, InputOption::VALUE_REQUIRED, 'E-mail address of the account that receives unroutable messages (catch-all)', null)
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'POP3 host', 'localhost')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'POP3 port', 995)
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'POP3 username (defaults to the domain)', null)
            ->addOption('security', null, InputOption::VALUE_REQUIRED, 'POP3 security (ssl, tls, none)', 'ssl')
            ->addOption('keep-on-server', null, InputOption::VALUE_NONE, 'Keep fetched messages on the server')
            ->addOption('smtp-host', null, InputOption::VALUE_REQUIRED, 'SMTP host')
            ->addOption('smtp-port', null, InputOption::VALUE_REQUIRED, 'SMTP port', 465)
            ->addOption('smtp-user', null, InputOption::VALUE_REQUIRED, 'SMTP username (defaults to the POP3 username)', null)
            ->addOption('smtp-password', null, InputOption::VALUE_REQUIRED, 'SMTP password (defaults to the POP3 password)', null)
            ->addOption('smtp-security', null, InputOption::VALUE_REQUIRED, 'SMTP security (ssl, tls, none)', 'ssl')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $domainArg = $input->getArgument('domain');
        $domain = is_string($domainArg) ? strtolower(trim($domainArg)) : '';
        $passwordArg = $input->getArgument('password');
        $password = is_string($passwordArg) ? $passwordArg : '';
        $nameOpt = $input->getOption('name');
        $name = is_string($nameOpt) && '' !== trim($nameOpt) ? trim((string) $nameOpt) : $domain;
        $hostOpt = $input->getOption('host');
        $host = is_string($hostOpt) ? $hostOpt : 'localhost';
        $portOpt = $input->getOption('port');
        $port = is_numeric($portOpt) ? (int) $portOpt : 995;
        $userOpt = $input->getOption('user');
        $popUser = is_string($userOpt) && '' !== trim($userOpt) ? trim((string) $userOpt) : $domain;
        $securityOpt = $input->getOption('security');
        $security = is_string($securityOpt) ? $securityOpt : 'ssl';
        $smtpHostOpt = $input->getOption('smtp-host');
        $smtpHost = is_string($smtpHostOpt) ? $smtpHostOpt : $host;
        $smtpPortOpt = $input->getOption('smtp-port');
        $smtpPort = is_numeric($smtpPortOpt) ? (int) $smtpPortOpt : 465;
        $smtpUserOpt = $input->getOption('smtp-user');
        $smtpUser = is_string($smtpUserOpt) && '' !== trim($smtpUserOpt) ? trim((string) $smtpUserOpt) : $popUser;
        $smtpPasswordOpt = $input->getOption('smtp-password');
        $smtpPassword = is_string($smtpPasswordOpt) && '' !== $smtpPasswordOpt ? $smtpPasswordOpt : $password;
        $smtpSecurityOpt = $input->getOption('smtp-security');
        $smtpSecurity = is_string($smtpSecurityOpt) ? $smtpSecurityOpt : 'ssl';

        if ('' === $domain || !filter_var('example@'.$domain, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('Invalid domain: %s', $domain));

            return Command::FAILURE;
        }

        $connector = new Connector();
        $connector->setName($name);
        $connector->setDomain($domain);
        $connector->setPop3Host($host);
        $connector->setPop3Port($port);
        $connector->setPop3Username($popUser);
        $connector->setPop3Password($password);
        $connector->setPop3Security($security);
        $connector->setPop3DeleteAfterFetch(!$input->getOption('keep-on-server'));
        $connector->setSmtpHost($smtpHost);
        $connector->setSmtpPort($smtpPort);
        $connector->setSmtpUsername($smtpUser);
        $connector->setSmtpPassword($smtpPassword);
        $connector->setSmtpSecurity($smtpSecurity);

        $defaultAccountOpt = $input->getOption('default-account');
        if (is_string($defaultAccountOpt) && '' !== trim($defaultAccountOpt)) {
            $account = $this->accountRepository->findOneBy(['email' => strtolower(trim($defaultAccountOpt))]);
            if (!$account instanceof Account) {
                $io->error(sprintf('Account not found: %s', $defaultAccountOpt));

                return Command::FAILURE;
            }
            $connector->setDefaultAccount($account);
        }

        $this->entityManager->persist($connector);
        $this->entityManager->flush();

        $io->success(sprintf('Connector %s (%s) has been created.', $name, $domain));

        return Command::SUCCESS;
    }
}
