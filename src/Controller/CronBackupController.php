<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Service\Backup\BackupSchedulerService;
use AvalynxOffice\Service\Backup\BackupScheduleService;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public cron endpoint for hosts that schedule via URL fetch (e.g.
 * All-Inkl KAS cronjobs): GET /cron/backup?token=<cron token>.
 *
 * The token is generated on the admin backup page and acts as the only
 * authentication — it must be treated like a password. With force=1 the
 * due-date check is skipped (automatic backups stay skipped while they
 * are disabled). Always answers with HTTP 200 so cron systems treat the
 * call as delivered; the JSON body carries the outcome.
 */
final readonly class CronBackupController
{
    public function __construct(
        private BackupSchedulerService $scheduler,
        private BackupScheduleService $schedule,
        #[Target('cronBackupLimiter')]
        private RateLimiterFactoryInterface $cronBackupLimiter,
    ) {
    }

    #[Route('/cron/backup', name: 'app_cron_backup', methods: ['GET'])]
    public function backup(Request $request): JsonResponse
    {
        if (!$this->cronBackupLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['success' => false, 'message' => 'too many requests'], \Symfony\Component\HttpFoundation\Response::HTTP_TOO_MANY_REQUESTS);
        }

        $token = (string) $request->query->get('token', '');
        $expected = $this->schedule->cronToken();

        if ('' === $expected || !hash_equals($expected, $token)) {
            return new JsonResponse(['success' => false, 'message' => 'invalid token'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }

        $force = '1' === $request->query->get('force');
        $result = $this->scheduler->maybeRun(force: $force);

        return new JsonResponse([
            'success' => true,
            'ran' => $result['ran'],
            'filename' => $result['filename'],
            'reason' => $result['reason'],
        ]);
    }
}
