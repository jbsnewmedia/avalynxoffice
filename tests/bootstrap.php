<?php

declare(strict_types=1);

use AvalynxOffice\Tests\Support\ParallelDatabase;
use AvalynxOffice\Tests\Support\WorkerKernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Parallel test workers (para-test): every worker gets its own database and
// kernel cache so the per-test reset stays race-free between workers.
if (getenv('PARATEST') === '1') {
    $_SERVER['KERNEL_CLASS'] = WorkerKernel::class;
    ParallelDatabase::boot();
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
