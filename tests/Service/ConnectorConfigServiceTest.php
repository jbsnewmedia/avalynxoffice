<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Service\ConnectorConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ConnectorConfigServiceTest extends TestCase
{
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

        $service = new ConnectorConfigService($entityManager, $repository);

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

        $service = new ConnectorConfigService($entityManager, $repository);
        $service->delete($connector, 'pop3');
        $service->delete($connector, 'pop3');
    }
}
