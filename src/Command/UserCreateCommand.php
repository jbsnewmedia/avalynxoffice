<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Licensing\Service\LicenseEnforcementInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates an administrator user non-interactively, intended for container
 * start-ups: the production entrypoint calls this after the migrations when
 * OFFICE_ADMIN_EMAIL and OFFICE_ADMIN_PASSWORD are provided.
 *
 * The command is idempotent: an existing user with the same email is left
 * untouched, so restarts never duplicate the administrator.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Creates an administrator user non-interactively (container bootstrap).',
)]
class UserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Administrator email address')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Administrator password')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $emailArgument = $input->getOption('email');
        $email = is_string($emailArgument) ? trim($emailArgument) : '';
        $passwordArgument = $input->getOption('password');
        $password = is_string($passwordArgument) ? $passwordArgument : '';

        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('A valid email address must be provided (--email).');

            return Command::FAILURE;
        }

        if ('' === $password) {
            $io->error('The password must not be empty (--password).');

            return Command::FAILURE;
        }

        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existingUser) {
            $io->note(sprintf('User %s already exists. Creation skipped.', $email));

            return Command::SUCCESS;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        // Seat accounting and the user insert must share one transaction so
        // that concurrent activations are serialized by the license state
        // row lock.
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $this->licenseEnforcement->assertUserSeatAvailable();
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        });

        $io->success(sprintf('Administrator %s created successfully.', $email));

        return Command::SUCCESS;
    }
}
