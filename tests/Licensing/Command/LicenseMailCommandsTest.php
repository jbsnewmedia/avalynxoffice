<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Licensing\Command;

use AvalynxOffice\Command\LicenseReleaseMailCommand;
use AvalynxOffice\Command\MailSendCommand;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Licensing\Service\LicenseManager;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[\PHPUnit\Framework\Attributes\Group('integration')]
final class LicenseMailCommandsTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
    }

    private function createOutgoingEmail(bool $sent = false, bool $licenseHold = false, ?\DateTimeImmutable $scheduledAt = null): Email
    {
        $account = new Account();
        $account->setName('Support')->setEmail('support@example.com');
        $email = new Email();
        $email->setSubject('Queued message');
        $email->setAccount($account);
        $email->setIsOutgoing(true);
        $email->setIsDraft(false);
        $email->setIsSent($sent);
        $email->setLicenseHold($licenseHold);
        $email->setScheduledAt($scheduledAt);
        $this->em->persist($account);
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    private function activateReadOnly(): void
    {
        $users = [];
        for ($i = 1; $i <= 5; ++$i) {
            $users[] = (new \AvalynxOffice\Entity\User())
                ->setEmail(sprintf('readonly-%d-%s@example.com', $i, bin2hex(random_bytes(4))))
                ->setPassword('x')
                ->setLastAuthAt(new \DateTimeImmutable('now'));
        }
        foreach ($users as $user) {
            $this->em->persist($user);
        }

        $state = static::getContainer()->get(\AvalynxOffice\Licensing\Service\LicenseGracePeriodManager::class)->getState(null);
        $state->setViolationStartedAt(new \DateTimeImmutable('-100 days', new \DateTimeZone('UTC')));
        $state->setGracePeriodEndsAt(new \DateTimeImmutable('-70 days', new \DateTimeZone('UTC')));
        $this->em->flush();

        static::getContainer()->get(LicenseManager::class)->reset();
    }

    public function testReadOnlyCommandHoldsPendingMessagesWithoutSending(): void
    {
        $this->activateReadOnly();
        $email = $this->createOutgoingEmail();
        $held = $this->createOutgoingEmail(licenseHold: true);

        $command = static::getContainer()->get(MailSendCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('restricted mode', $tester->getDisplay());

        /** @var Email $reloaded */
        $reloaded = $this->em->find(Email::class, $email->getId());
        self::assertTrue($reloaded->isLicenseHold());
        self::assertFalse($reloaded->isSent());
        self::assertFalse($reloaded->isSending());

        /** @var Email $heldReloaded */
        $heldReloaded = $this->em->find(Email::class, $held->getId());
        self::assertTrue($heldReloaded->isLicenseHold());
    }

    public function testHeldMessagesAreSkippedWhenLicenseIsRestoredUntilExplicitRelease(): void
    {
        $this->activateReadOnly();
        $email = $this->createOutgoingEmail();

        $command = static::getContainer()->get(MailSendCommand::class);
        (new CommandTester($command))->execute([]);

        // License restored by deactivating the excess users.
        $users = $this->em->getRepository(\AvalynxOffice\Entity\User::class)->findAll();
        $kept = 0;
        foreach ($users as $user) {
            if ($kept < 3) {
                ++$kept;

                continue;
            }
            $user->setActive(false);
        }
        $this->em->flush();

        // Without release, the held message is still not sent.
        $manager = static::getContainer()->get(LicenseManager::class);
        $manager->reset();
        self::assertFalse($manager->isReadOnly());
        (new CommandTester($command))->execute([]);

        /** @var Email $reloaded */
        $reloaded = $this->em->find(Email::class, $email->getId());
        self::assertTrue($reloaded->isLicenseHold());
        self::assertFalse($reloaded->isSent());

        // Explicit release hands the message back to the queue.
        $releaseTester = new CommandTester(static::getContainer()->get(LicenseReleaseMailCommand::class));
        $releaseExit = $releaseTester->execute([]);

        self::assertSame(Command::SUCCESS, $releaseExit);
        self::assertStringContainsString('1 held message(s)', $releaseTester->getDisplay());
        /** @var Email $released */
        $released = $this->em->find(Email::class, $email->getId());
        self::assertFalse($released->isLicenseHold());
        self::assertFalse($released->isSent());
    }

    public function testReleaseFailsWhileStillReadOnly(): void
    {
        $this->activateReadOnly();
        $this->createOutgoingEmail(licenseHold: true);

        $tester = new CommandTester(static::getContainer()->get(LicenseReleaseMailCommand::class));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        $held = $this->em->getRepository(Email::class)->findBy(['licenseHold' => true]);
        self::assertCount(1, $held);
    }

    public function testScheduledMessagesAreNotSentBeforeTheirSchedule(): void
    {
        $email = $this->createOutgoingEmail(scheduledAt: new \DateTimeImmutable('+1 hour'));

        $command = static::getContainer()->get(MailSendCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);

        /** @var Email $reloaded */
        $reloaded = $this->em->find(Email::class, $email->getId());
        self::assertFalse($reloaded->isSent());
        self::assertFalse($reloaded->isLicenseHold());
    }

    public function testEmailRepositoryFindsHeldMessages(): void
    {
        $this->createOutgoingEmail(licenseHold: true);
        /** @var EmailRepository $repository */
        $repository = $this->em->getRepository(Email::class);

        self::assertCount(1, $repository->findBy(['licenseHold' => true]));
    }

    public function testReadOnlyActivationDuringTheRunHoldsRemainingMessages(): void
    {
        $email = $this->createOutgoingEmail();

        $entityManager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $repository = $this->createStub(EmailRepository::class);
        $repository->method('findBy')->willReturn([$email]);

        $command = new MailSendCommand(
            $repository,
            $entityManager,
            $this->createStub(\AvalynxOffice\Service\Mail\FolderManager::class),
            $this->createStub(\AvalynxOffice\Service\Mail\AttachmentStorage::class),
            $this->createStub(\AvalynxOffice\Service\Mail\SmtpMailerFactory::class),
            $this->createStub(\AvalynxOffice\Repository\ConnectorRepository::class),
            new \AvalynxOffice\Tests\Support\FlippingReadOnlyLicenseEnforcement(2),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('restricted mode', $tester->getDisplay());
        self::assertTrue($email->isLicenseHold());
        self::assertFalse($email->isSent());
    }
}
