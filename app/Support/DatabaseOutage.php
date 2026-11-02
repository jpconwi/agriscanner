<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

final class DatabaseOutage
{
    /** SQLSTATE class 08 = connection exception; 57P* = operator intervention (shutdown), 53* = insufficient resources. */
    public static function isConnectionFailure(Throwable $e): bool
    {
        $previous = $e instanceof QueryException ? $e->getPrevious() : $e;
        $state = (string) ($e instanceof QueryException ? ($e->errorInfo[0] ?? $e->getCode()) : $e->getCode());

        if (str_starts_with($state, '08') || str_starts_with($state, '57P') || str_starts_with($state, '53')) {
            return true;
        }

        $message = strtolower($e->getMessage().' '.($previous?->getMessage() ?? ''));
        foreach (['could not connect', 'connection refused', 'connection timed out', 'server closed the connection', 'no route to host', 'could not translate host', 'ssl connection has been closed', 'terminating connection', 'timeout expired'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return $previous instanceof PDOException && str_contains(strtolower($previous->getMessage()), 'connection');
    }
}
