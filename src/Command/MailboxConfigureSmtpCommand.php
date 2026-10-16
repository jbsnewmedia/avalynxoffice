<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MailboxConnectorRepository;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mailbox:configure-smtp',
    description: 'Configures the SMTP settings of an existing mailbox.',
)]
class MailboxConfigureSmtpCommand extends Command
{
    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly MailboxConnectorRepository $mailboxConnectorRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'ID oder E-Mail der Mailbox bzw. des Connectors')
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
        $connector = $this->mailboxConnectorRepository->findOneBy(['email' => $identifier]);
        if (!$connector) {
            $connector = $this->mailboxConnectorRepository->find($identifier);
        }
        if (!$connector) {
            $mailbox = $this->mailboxRepository->findOneBy(['email' => $identifier]) ?? $this->mailboxRepository->find($identifier);
            $connector = $mailbox?->getPrimaryConnector();
        }
        if (!$connector) {
            $io->error(sprintf('Mailbox or connector with identifier "%s" was not found.', $identifier));

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
        $io->success(sprintf('SMTP configuration for connector %s has been updated.', $connector->getEmail()));

        return Command::SUCCESS;
    }
}
