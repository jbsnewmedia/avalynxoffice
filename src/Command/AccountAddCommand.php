<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Entity\Account;
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
    name: 'app:account:add',
    description: 'Creates a new account and optionally assigns it to a user.',
)]
class AccountAddCommand extends Command
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
            ->addArgument('email', InputArgument::REQUIRED, 'Main e-mail address of the account')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name of the account', null)
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'E-mail address of a user the account is assigned to', null)
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $emailArg = $input->getArgument('email');
        $email = is_string($emailArg) ? strtolower(trim($emailArg)) : '';
        $nameOpt = $input->getOption('name');
        $name = is_string($nameOpt) && '' !== trim($nameOpt) ? trim((string) $nameOpt) : $email;

        if ('' === $email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('Invalid e-mail address: %s', $email));

            return Command::FAILURE;
        }

        $account = new Account();
        $account->setEmail($email);
        $account->setName($name);

        $userOpt = $input->getOption('user');
        if (is_string($userOpt) && '' !== trim($userOpt)) {
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => strtolower(trim($userOpt))]);
            if (!$user instanceof User) {
                $io->error(sprintf('User not found: %s', $userOpt));

                return Command::FAILURE;
            }
            $account->addUser($user);
            $io->note(sprintf('Account is assigned to user %s.', $user->getEmail()));
        }

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $io->success(sprintf('Account %s (%s) has been created.', $name, $email));

        return Command::SUCCESS;
    }
}
