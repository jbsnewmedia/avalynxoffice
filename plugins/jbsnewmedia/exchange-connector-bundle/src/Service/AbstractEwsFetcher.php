<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Service;

use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared behaviour of the EWS fetchers: console logging, error log file and
 * config value readers (same conventions as the POP3 connector).
 */
abstract class AbstractEwsFetcher
{
    protected ?OutputInterface $output = null;

    protected ?string $errorLogPath = null;

    protected function bindRequest(ConnectorFetchRequest $request): void
    {
        $this->output = $request->output ?? $this->output;
        $this->errorLogPath = $request->errorLogPath ?? $this->errorLogPath;
    }

    protected function log(string $message, string $type = 'info'): void
    {
        $this->output?->writeln(sprintf('<%s>   %s</%s>', $type, $message, $type));
    }

    protected function logError(string $message, ?\Throwable $exception = null): void
    {
        $this->log($message, 'error');
        if (null === $this->errorLogPath) {
            return;
        }
        $detail = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
        if ($exception instanceof \Throwable) {
            $detail .= sprintf(
                "Error: %s\nFile: %s:%d\nTrace:\n%s\n\n",
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            );
        }
        @file_put_contents($this->errorLogPath, $detail, FILE_APPEND);
    }

    /**
     * Whether the messages on the server are deleted after a successful
     * import: the request flag wins over the stored connector setting.
     *
     * @param array<string, mixed> $config
     */
    protected static function deleteAfter(array $config, ConnectorFetchRequest $request): bool
    {
        return $request->deleteAfter ?? self::boolValue($config, 'ewsDeleteAfterFetch', false);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $config
     */
    protected static function boolValue(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected static function intValue(array $config, string $key): int
    {
        $value = $config[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
