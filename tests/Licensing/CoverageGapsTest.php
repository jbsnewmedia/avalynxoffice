<?php

declare(strict_types=1);

namespace App\Tests\Licensing;

use App\Licensing\Entity\LicenseState;
use App\Licensing\Entity\StoredLicenseKey;
use App\Licensing\EventSubscriber\LicenseExceptionSubscriber;
use App\Licensing\Model\LicenseKeyReference;
use App\Licensing\Model\LicenseSource;
use App\Licensing\Security\LicenseCodec;
use App\Licensing\Security\TrustedPublicKeyProvider;
use App\Licensing\Service\LicenseEnforcementInterface;
use App\Licensing\Service\LicenseGracePeriodManager;
use App\Licensing\Service\LicenseManager;
use App\Tests\Licensing\Support\TestLicenseIssuer;
use App\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Closes remaining coverage gaps: trivial getters, log/rotation branches and
 * defensive code paths.
 */
final class CoverageGapsTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    private TestLicenseIssuer $issuer;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->issuer = new TestLicenseIssuer();
        $publicKeys = json_encode(['test-2026-01' => $this->issuer->publicKeyBase64()], JSON_THROW_ON_ERROR);
        $_ENV['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = $publicKeys;
        $_SERVER['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = $publicKeys;
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $_ENV['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = '';
    }

    public function testLicenseStateEntityAccessors(): void
    {
        $state = new LicenseState();

        self::assertSame(LicenseState::SINGLETON_ID, $state->getId());

        $state->setOrganizationId('org-x');
        $state->setInstallationId('inst-x');
        $state->setLastKnownStatus('VALID');

        self::assertSame('org-x', $state->getOrganizationId());
        self::assertSame('inst-x', $state->getInstallationId());
        self::assertSame('VALID', $state->getLastKnownStatus());
    }

    public function testStoredLicenseKeyAccessors(): void
    {
        $key = 'WOL1.abc.def';
        $stored = new StoredLicenseKey($key);

        self::assertNotNull($stored->getId());
        self::assertSame(hash('sha256', $key), $stored->getKeyHash());
        self::assertSame($key, $stored->getLicenseKey());
        self::assertInstanceOf(\DateTimeImmutable::class, $stored->getAddedAt());
    }

    public function testLicenseExceptionSubscriberSubscribesToKernelException(): void
    {
        $events = LicenseExceptionSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(\Symfony\Component\HttpKernel\KernelEvents::EXCEPTION, $events);
    }

    public function testLicenseKeyReferencePreviewMasksTheKey(): void
    {
        $key = 'WOL1.abcdefghijklmnopqrstuvwxyz0123456789.signature';
        $reference = new LicenseKeyReference($key, LicenseSource::ENV);

        self::assertSame(substr($key, 0, 24).'…', $reference->preview());
    }

    public function testCodecRejectsKeyWithTooFewParts(): void
    {
        $codec = new LicenseCodec();

        $this->expectException(\App\Licensing\Validation\LicenseKeyException::class);
        $codec->decode('WOL1.a');
    }

    public function testTrustedPublicKeyProviderRejectsScalarJson(): void
    {
        $provider = new TrustedPublicKeyProvider('"just-a-string"');

        self::assertSame([], $provider->getKeyIds());
        self::assertNull($provider->getPublicKeyFor('k'));
    }

    public function testEnforcementWithoutOpenTransactionSkipsTheRowLock(): void
    {
        static::getContainer()->get(\App\Licensing\Service\LicenseGracePeriodManager::class)->getState(null);

        $enforcement = static::getContainer()->get(LicenseEnforcementInterface::class);

        // No transaction is open: the guard runs without the pessimistic lock.
        $enforcement->assertUserSeatAvailable();
        self::addToAssertionCount(1);
    }

    public function testLockStateForUpdateFailsWithoutStateRow(): void
    {
        $manager = static::getContainer()->get(LicenseGracePeriodManager::class);

        $this->em->beginTransaction();
        try {
            $manager->lockStateForUpdate();
            self::fail('Locking a missing state row must fail.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        } finally {
            $this->em->rollBack();
        }
    }

    public function testPersistResolutionRefreshesStaleCheckTimestamp(): void
    {
        $manager = static::getContainer()->get(LicenseGracePeriodManager::class);
        $state = $manager->getState(null);
        $old = new \DateTimeImmutable('-10 minutes');
        $state->setLastCheckedAt($old);
        $this->em->flush();

        $now = new \DateTimeImmutable();
        $manager->persistResolution($state, null, null, null, $now);

        self::assertSame($now->getTimestamp(), $state->getLastCheckedAt()?->getTimestamp());
    }

    public function testManagerGetStateExposesTheStateRow(): void
    {
        $manager = static::getContainer()->get(LicenseManager::class);

        self::assertSame(LicenseState::SINGLETON_ID, $manager->getState()->getId());
    }

    public function testCompositeProviderDeduplicatesIdenticalKeysAcrossSources(): void
    {
        $key = $this->issuer->issue();

        $_ENV['AVALYNXOFFICE_LICENSE_KEYS'] = $key.','.$key;
        $_SERVER['AVALYNXOFFICE_LICENSE_KEYS'] = $_ENV['AVALYNXOFFICE_LICENSE_KEYS'];
        $this->em->persist(new StoredLicenseKey($key));
        $this->em->flush();

        $manager = static::getContainer()->get(LicenseManager::class);

        // The key appears in ENV (twice) and in the database, but counts once.
        self::assertSame(10, $manager->getAllowedSeats());
        self::assertCount(1, $manager->getValidLicenses());

        $_ENV['AVALYNXOFFICE_LICENSE_KEYS'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_KEYS'] = '';
    }

    public function testFileProviderIgnoresUnreadableFile(): void
    {
        $path = sys_get_temp_dir().'/license-unreadable-'.bin2hex(random_bytes(4)).'.keys';
        file_put_contents($path, 'WOL1.abc.def');
        chmod($path, 0000);

        try {
            $provider = new \App\Licensing\Provider\FileLicenseKeyProvider($path);
            self::assertSame([], $provider->getLicenseKeys());
        } finally {
            chmod($path, 0644);
            unlink($path);
        }
    }

    public function testSubscriberUnwrapsWrappedExceptions(): void
    {
        $subscriber = static::getContainer()->get(LicenseExceptionSubscriber::class);

        $request = \Symfony\Component\HttpFoundation\Request::create('/email/00000000-0000-7000-8000-000000000000/read', 'POST');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $nested = new \RuntimeException(
            'outer',
            0,
            new \App\Licensing\Exception\LicenseReadOnlyException(\App\Licensing\Service\LicenseOperation::MAIL_MODIFY),
        );
        $event = new \Symfony\Component\HttpKernel\Event\ExceptionEvent(
            static::getContainer()->get('kernel'),
            $request,
            \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
            $nested,
        );

        $subscriber->onKernelException($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }
}
