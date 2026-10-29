<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Support;

use AvalynxOffice\Kernel;

/**
 * Kernel for parallel test workers (para-test).
 *
 * Every worker gets its own cache directory so concurrent workers never race
 * on the Symfony container cache. Plain test runs keep the default cache.
 */
final class WorkerKernel extends Kernel
{
    #[\Override]
    public function getCacheDir(): string
    {
        return parent::getCacheDir().'-worker-'.(getenv('TEST_TOKEN') ?: '1');
    }
}
