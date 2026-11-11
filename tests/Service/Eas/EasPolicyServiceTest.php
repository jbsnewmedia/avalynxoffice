<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Eas;

use AvalynxOffice\Service\Eas\EasPolicyService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
final class EasPolicyServiceTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->refreshDatabase(self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class));
    }

    public function testNothingIsStoredByDefault(): void
    {
        $service = self::getContainer()->get(EasPolicyService::class);
        \assert($service instanceof EasPolicyService);

        self::assertSame([], $service->stored());
    }

    public function testSaveStoresToggleAndNumberDeviations(): void
    {
        $service = self::getContainer()->get(EasPolicyService::class);
        \assert($service instanceof EasPolicyService);

        $service->save([
            'devpwenabled' => '1',
            'allowcam' => '0',
            'mindevpwlenngth' => '8',
            'maxinacttimedevlock' => null,
        ]);

        self::assertSame([
            'devpwenabled' => '1',
            'allowcam' => '0',
            'mindevpwlenngth' => '8',
        ], $service->stored());
    }

    public function testSaveRemovesRowWhenRestrictionTurnedOff(): void
    {
        $service = self::getContainer()->get(EasPolicyService::class);
        \assert($service instanceof EasPolicyService);

        $service->save(['devpwenabled' => '1']);
        self::assertSame('1', $service->stored()['devpwenabled']);

        $service->save(['devpwenabled' => null]);
        self::assertArrayNotHasKey('devpwenabled', $service->stored());

        $service->save(['mindevpwlenngth' => '12']);
        self::assertSame('12', $service->stored()['mindevpwlenngth']);

        $service->save(['mindevpwlenngth' => '']);
        self::assertArrayNotHasKey('mindevpwlenngth', $service->stored());
    }

    public function testSaveClampsNumberBounds(): void
    {
        $service = self::getContainer()->get(EasPolicyService::class);
        \assert($service instanceof EasPolicyService);

        $service->save(['maxdevpwfailedattempts' => '4', 'maxinacttimedevlock' => '9999', 'mindevcomplexchars' => '4']);
        self::assertSame('4', $service->stored()['maxdevpwfailedattempts']);
        self::assertSame('9999', $service->stored()['maxinacttimedevlock']);
        self::assertSame('4', $service->stored()['mindevcomplexchars']);
    }

    public function testSaveRejectsInvalidToggleValue(): void
    {
        $service = self::getContainer()->get(EasPolicyService::class);
        \assert($service instanceof EasPolicyService);

        $this->expectException(\InvalidArgumentException::class);
        $service->save(['devpwenabled' => '2']);
    }

    public function testSaveRejectsOutOfRangeNumber(): void
    {
        $service = self::getContainer()->get(EasPolicyService::class);
        \assert($service instanceof EasPolicyService);

        $this->expectException(\InvalidArgumentException::class);
        $service->save(['maxdevpwfailedattempts' => '1']);
    }

    public function testSaveRejectsUnknownKey(): void
    {
        $service = self::getContainer()->get(EasPolicyService::class);
        \assert($service instanceof EasPolicyService);

        $this->expectException(\InvalidArgumentException::class);
        $service->save(['notarealpolicy' => '1']);
    }
}
