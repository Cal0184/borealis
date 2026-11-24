<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\HttpException;

/** A failure talking to Google, shown to the person as is (HTTP 502 for the browser). $status is Google's. */
final class GoogleError extends HttpException
{
    public function __construct(string $message, public readonly int $googleStatus = 0, public readonly string $reason = '')
    {
        parent::__construct(502, $message);
    }
}
