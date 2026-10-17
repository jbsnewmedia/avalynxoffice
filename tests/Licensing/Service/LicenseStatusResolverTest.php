<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Licensing\Service;

use AvalynxOffice\Licensing\Entity\LicenseState;
use AvalynxOffice\Licensing\Model\LicenseStatus;
use AvalynxOffice\Licensing\Service\LicenseStatusResolver;
use PHPUnit\Framework\TestCase;

final class LicenseStatusResolverTest extends TestCase
{
    private LicenseStatusResolver $resolver;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->resolver = new LicenseStatusResolver(30);
        $this->now = new \DateTimeImmutable('2026-10-13T12:00:00+00:00');
    }

    private function createState(?\DateTimeImmutable $violationStartedAt = null, ?\DateTimeImmutable $graceEndsAt = null): LicenseState
    {
        $state = new LicenseState();
        $state->setViolationStartedAt($violationStartedAt);
        $state->setGracePeriodEndsAt($graceEndsAt);

        return $state;
    }

    public function testCommunityWithThreeSeats(): void
    {
        $resolution = $this->resolver->resolve($this->createState(), 3, 3, 0, $this->now);

        self::assertSame(LicenseStatus::COMMUNITY, $resolution->status);
        self::assertNull($resolution->violationStartedAt);
        self::assertNull($resolution->gracePeriodEndsAt);
    }

    public function testCommunityWithoutLicensesAndFewerSeats(): void
    {
        $resolution = $this->resolver->resolve($this->createState(), 3, 1, 0, $this->now);

        self::assertSame(LicenseStatus::COMMUNITY, $resolution->status);
    }

    public function testValidWithLicensesAndWithinSeats(): void
    {
        $resolution = $this->resolver->resolve($this->createState(), 10, 9, 1, $this->now);

        self::assertSame(LicenseStatus::VALID, $resolution->status);
    }

    public function testCommunityStaysCommunityWithLicensesButBaseSeatsOnly(): void
    {
        // A key exists but adds no seats above the base? additional_seats >= 1
        // makes allowedSeats > 3, so this case means: licenses present, but
        // seats within base -> community only when allowedSeats == 3.
        $resolution = $this->resolver->resolve($this->createState(), 3, 3, 1, $this->now);

        self::assertSame(LicenseStatus::COMMUNITY, $resolution->status);
    }

    public function testExceedingSeatsStartsGracePeriod(): void
    {
        $state = $this->createState();
        $resolution = $this->resolver->resolve($state, 3, 4, 0, $this->now);

        self::assertSame(LicenseStatus::GRACE_PERIOD, $resolution->status);
        self::assertSame($this->now, $resolution->violationStartedAt);
        self::assertSame($this->now->modify('+30 days')->getTimestamp(), $resolution->gracePeriodEndsAt?->getTimestamp());
        self::assertFalse($resolution->violationCleared);
    }

    public function testGracePeriodKeepsOriginalStartAndEnd(): void
    {
        $start = $this->now->modify('-10 days');
        $end = $start->modify('+30 days');
        $state = $this->createState($start, $end);

        $resolution = $this->resolver->resolve($state, 3, 5, 0, $this->now);

        self::assertSame(LicenseStatus::GRACE_PERIOD, $resolution->status);
        self::assertSame($start->getTimestamp(), $resolution->violationStartedAt?->getTimestamp());
        self::assertSame($end->getTimestamp(), $resolution->gracePeriodEndsAt?->getTimestamp());
    }

    public function testRepeatedFlappingDoesNotExtendGracePeriod(): void
    {
        // Violation started, then usage went back within quota (violation
        // cleared), then exceeded again: a fresh grace period starts, but
        // while a violation persists the recorded end is never moved.
        $start = $this->now->modify('-29 days');
        $end = $start->modify('+30 days');
        $state = $this->createState($start, $end);

        $withinGrace = $this->resolver->resolve($state, 3, 4, 0, $this->now);
        self::assertSame(LicenseStatus::GRACE_PERIOD, $withinGrace->status);
        self::assertSame($end->getTimestamp(), $withinGrace->gracePeriodEndsAt?->getTimestamp());

        $cleared = $this->resolver->resolve($state, 3, 3, 0, $this->now);
        self::assertTrue($cleared->violationCleared);
        self::assertNull($cleared->violationStartedAt);
    }

    public function testReadOnlyAfterGracePeriod(): void
    {
        $start = $this->now->modify('-31 days');
        $end = $start->modify('+30 days');
        $state = $this->createState($start, $end);

        $resolution = $this->resolver->resolve($state, 3, 4, 0, $this->now);

        self::assertSame(LicenseStatus::READ_ONLY, $resolution->status);
        self::assertSame($start->getTimestamp(), $resolution->violationStartedAt?->getTimestamp());
    }

    public function testReadOnlyEndsOnlyWhenUsageIsBackWithinQuota(): void
    {
        $start = $this->now->modify('-100 days');
        $end = $start->modify('+30 days');
        $state = $this->createState($start, $end);

        self::assertSame(LicenseStatus::READ_ONLY, $this->resolver->resolve($state, 3, 4, 0, $this->now)->status);
        self::assertSame(LicenseStatus::READ_ONLY, $this->resolver->resolve($state, 3, 50, 0, $this->now)->status);

        $compliant = $this->resolver->resolve($state, 3, 2, 0, $this->now);
        self::assertSame(LicenseStatus::COMMUNITY, $compliant->status);
        self::assertTrue($compliant->violationCleared);
    }

    public function testLicenseUpgradeEndsReadOnly(): void
    {
        $start = $this->now->modify('-100 days');
        $end = $start->modify('+30 days');
        $state = $this->createState($start, $end);

        // A new license raises the allowed seats above the used seats.
        $compliant = $this->resolver->resolve($state, 10, 8, 1, $this->now);

        self::assertSame(LicenseStatus::VALID, $compliant->status);
        self::assertTrue($compliant->violationCleared);
    }

    public function testGracePeriodEndsWithoutRecordedEndIsDerivedFromStart(): void
    {
        $start = $this->now->modify('-40 days');
        $state = $this->createState($start, null);

        $resolution = $this->resolver->resolve($state, 3, 4, 0, $this->now);

        self::assertSame(LicenseStatus::READ_ONLY, $resolution->status);
        self::assertSame($start->modify('+30 days')->getTimestamp(), $resolution->gracePeriodEndsAt?->getTimestamp());
    }

    public function testClockRollbackNeverExtendsGracePeriod(): void
    {
        $start = new \DateTimeImmutable('2026-06-01T00:00:00+00:00');
        $end = $start->modify('+30 days'); // 2026-07-01
        $state = $this->createState($start, $end);

        // The system time was rolled back far before the violation start.
        $rolledBack = new \DateTimeImmutable('2026-05-01T00:00:00+00:00');

        $resolution = $this->resolver->resolve($state, 3, 4, 0, $rolledBack);

        self::assertSame(LicenseStatus::GRACE_PERIOD, $resolution->status);
        self::assertSame($start->getTimestamp(), $resolution->violationStartedAt?->getTimestamp());
        self::assertSame($end->getTimestamp(), $resolution->gracePeriodEndsAt?->getTimestamp());
    }

    public function testClockRollbackDoesNotRestartExpiredGracePeriod(): void
    {
        $start = new \DateTimeImmutable('2026-06-01T00:00:00+00:00');
        $end = $start->modify('+30 days');
        $state = $this->createState($start, $end);

        // Rolled back to a time inside the original grace window after it
        // had already expired: the comparison uses the recorded start, so
        // the state stays read-only instead of reviving the grace period.
        $rolledBack = new \DateTimeImmutable('2026-06-15T00:00:00+00:00');

        self::assertSame(LicenseStatus::GRACE_PERIOD, $this->resolver->resolve($state, 3, 4, 0, $rolledBack)->status);

        $farBack = new \DateTimeImmutable('2020-01-01T00:00:00+00:00');
        self::assertSame(LicenseStatus::GRACE_PERIOD, $this->resolver->resolve($state, 3, 4, 0, $farBack)->status);
    }
}
