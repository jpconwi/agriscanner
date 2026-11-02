<?php

namespace App\Services\Scans;

use RuntimeException;

/** The analysis finished but the database write failed. The result is NOT saved yet. */
class ScanNotSavedException extends RuntimeException
{
    public function __construct(public readonly string $recordedIn)
    {
        parent::__construct($recordedIn === 'none'
            ? 'The analysis finished but we could not save it, and it could not be recorded for recovery. Please upload the photo again in a few minutes.'
            : 'The analysis finished but the result is NOT saved yet because the database is unavailable. It has been recorded for automatic recovery; check your scan history again shortly, or retry the upload.');
    }
}
