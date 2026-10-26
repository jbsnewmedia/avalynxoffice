<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Sync;

use AvalynxOffice\Repository\AppSettingRepository;
use AvalynxOffice\Service\Sync\SyncSettingsService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
final class SyncSettingsServiceTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->refreshDatabase(self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class));
    }
    public function testDefaultsAreUsedWhenNothingIsStored(): void
    {
        $service = self::getContainer()->get(SyncSettingsService::class);
        \assert($service instanceof SyncSettingsService);

        self::assertSame([
            'intervalSeconds' => 300,
            'busyIntervalSeconds' => 2,
            'mailsLimit' => 100,
            'contactsLimit' => 100,
            'calendarLimit' => 100,
        ], $service->all());
    }

    public function testSavePersistsAndReloadsValues(): void
    {
        $service = self::getContainer()->get(SyncSettingsService::class);
        \assert($service instanceof SyncSettingsService);
        $service->save([
            'intervalSeconds' => 10,
            'busyIntervalSeconds' => 2,
            'mailsLimit' => 10,
            'contactsLimit' => 20,
            'calendarLimit' => 30,
        ]);

        self::assertSame(10, $service->all()['intervalSeconds']);
        self::assertSame(2, $service->all()['busyIntervalSeconds']);
        self::assertSame(10, $service->all()['mailsLimit']);
        self::assertSame(20, $service->all()['contactsLimit']);
        self::assertSame(30, $service->all()['calendarLimit']);
    }

    public function testSaveClampsValuesToSaneBounds(): void
    {
        $service = self::getContainer()->get(SyncSettingsService::class);
        \assert($service instanceof SyncSettingsService);
        $service->save([
            'intervalSeconds' => -50,
            'mailsLimit' => 999999999,
        ]);

        self::assertSame(0, $service->all()['intervalSeconds']);
        self::assertSame(SyncSettingsService::MAX_VALUE, $service->all()['mailsLimit']);
    }

    public function testStoredRawValuesAreAccessibleViaRepository(): void
    {
        $service = self::getContainer()->get(SyncSettingsService::class);
        \assert($service instanceof SyncSettingsService);
        $service->save(['intervalSeconds' => 45]);

        $repository = self::getContainer()->get(AppSettingRepository::class);
        \assert($repository instanceof AppSettingRepository);
        self::assertSame('45', $repository->getValue(SyncSettingsService::KEY_INTERVAL));
        self::assertNull($repository->getValue(SyncSettingsService::KEY_MAILS_LIMIT));
    }
}
