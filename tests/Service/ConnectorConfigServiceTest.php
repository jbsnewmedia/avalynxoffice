<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Service\ConnectorConfigService;
use AvalynxOffice\Service\ConnectorPluginRegistry;
use AvalynxOffice\Service\Security\SecretCrypto;
use AvalynxOffice\Tests\Support\FakeConnectorPlugin;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ConnectorConfigServiceTest extends TestCase
{
    private const string TEST_KEY = 'ylnmhNTxE6uJxSt0NVduo9/tPPFgA9hZf20A6vbHCwM=';

    public function testSaveCreatesPersistsAndUpdatesTheConfigEntity(): void
    {
        $connector = new Connector();

        $existing = new ConnectorConfig();
        $existing->setConnector($connector);
        $existing->setPluginKey('pop3');

        $repository = $this->createStub(ConnectorConfigRepository::class);
        $repository->method('findOneBy')->willReturnOnConsecutiveCalls(null, $existing);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::exactly(2))->method('flush');

        $service = $this->service($entityManager, $repository);

        $created = $service->save($connector, 'pop3', ['pop3Host' => 'mail.example.com']);
        self::assertSame($persisted[0], $created);
        self::assertSame(['pop3Host' => 'mail.example.com'], $created->getConfig());

        $updated = $service->save($connector, 'pop3', ['pop3Host' => 'other.example.com']);
        self::assertSame($existing, $updated);
        self::assertSame(['pop3Host' => 'other.example.com'], $updated->getConfig());
    }

    public function testDeleteIgnoresUnknownConfigAndRemovesExistingOne(): void
    {
        $connector = new Connector();
        $existing = new ConnectorConfig();
        $existing->setConnector($connector);
        $existing->setPluginKey('pop3');

        $repository = $this->createStub(ConnectorConfigRepository::class);
        $repository->method('findOneBy')->willReturnOnConsecutiveCalls(null, $existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($existing);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->service($entityManager, $repository);
        $service->delete($connector, 'pop3');
        $service->delete($connector, 'pop3');
    }

    public function testSaveEncryptsSecretKeysDeclaredByThePlugin(): void
    {
        $connector = new Connector();
        $repository = $this->createStub(ConnectorConfigRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $crypto = new SecretCrypto(self::TEST_KEY);

        $service = $this->service($entityManager, $repository, $crypto, new FakeConnectorPlugin('pop3', secretConfigKeys: ['pop3Password', 'smtpPassword']));
        $stored = $service->save($connector, 'pop3', [
            'pop3Host' => 'mail.example.com',
            'pop3Password' => 's3cret',
            'smtpPassword' => '',
            'server_total' => 12,
        ])->getConfig();

        self::assertSame('mail.example.com', $stored['pop3Host']);
        self::assertSame(12, $stored['server_total']);
        self::assertSame('', $stored['smtpPassword']);
        self::assertIsString($stored['pop3Password']);
        self::assertStringStartsWith(SecretCrypto::PREFIX, $stored['pop3Password']);
        self::assertSame('s3cret', $crypto->decrypt($stored['pop3Password']));

        // Already encrypted values are stored as-is (no double encryption).
        $again = $service->save($connector, 'pop3', ['pop3Password' => $stored['pop3Password']])->getConfig();
        self::assertSame($stored['pop3Password'], $again['pop3Password']);
    }

    public function testSaveLeavesConfigUntouchedForUnknownPlugin(): void
    {
        $repository = $this->createStub(ConnectorConfigRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $service = $this->service($this->createStub(EntityManagerInterface::class), $repository);
        $stored = $service->save(new Connector(), 'unknown', ['password' => 'plain'])->getConfig();

        self::assertSame(['password' => 'plain'], $stored);
    }

    private function service(EntityManagerInterface $entityManager, ConnectorConfigRepository $repository, ?SecretCrypto $crypto = null, FakeConnectorPlugin ...$plugins): ConnectorConfigService
    {
        $crypto ??= new SecretCrypto(self::TEST_KEY);
        $registry = new ConnectorPluginRegistry($plugins, $repository, $crypto);

        return new ConnectorConfigService($entityManager, $repository, $registry, $crypto);
    }
}
