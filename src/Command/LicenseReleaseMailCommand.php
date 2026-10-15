<?php

declare(strict_types=1);

namespace App\Command;

use App\Licensing\Service\LicenseEnforcementInterface;
use App\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Controlled release of outgoing messages that were paused by the license
 * policy. The messages are handed back to the regular queue; they are never
 * released while the installation is still in read-only mode.
 */
#[AsCommand(
    name: 'app:license:release-mail',
    description: 'Gibt vom Lizenzmodus angehaltene ausgehende E-Mails zur erneuten Verarbeitung frei.'
)]
final class LicenseReleaseMailCommand extends Command
{
    public function __construct(
        private readonly EmailRepository $emailRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->licenseEnforcement->isReadOnly()) {
            $output->writeln('[LICENSE] AvalynxOffice befindet sich weiterhin im eingeschränkten Modus. Eine Freigabe ist nicht möglich.');

            return Command::FAILURE;
        }

        $held = $this->emailRepository->findBy(['licenseHold' => true]);
        foreach ($held as $email) {
            $email->setLicenseHold(false);
        }
        $this->entityManager->flush();

        $output->writeln(sprintf('%d angehaltene Nachricht(en) wurden freigegeben.', count($held)));

        return Command::SUCCESS;
    }
}
