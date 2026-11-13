<?php

declare(strict_types=1);

namespace MyShowcase\Util;

/**
 * The documented constants of the external file fetcher (decision D53, section 5.8). They are constants, not settings:
 * the board's administrator switches the import of external files off (ImportSettings), and nothing else is configurable.
 *
 * | Constant            | Value                                   |
 * |---------------------|-----------------------------------------|
 * | CEILING_BYTES       | 50 MB per file, whatever the field allows |
 * | BUDGET_BYTES        | 100 MB downloaded per import             |
 * | MAX_FILES           | 25 files per import                      |
 * | MAX_REDIRECTS       | 3 redirects per file, each hop checked   |
 * | CONNECT_TIMEOUT     | 5 seconds                                |
 * | FILE_TIMEOUT        | 30 seconds per file                      |
 * | TIME_BUDGET_SHARE   | 60 % of PHP's max_execution_time for the whole import |
 * | MAX_URL_LENGTH      | 2048 characters                          |
 * | PORTS               | 80 and 443 only                          |
 */
final class ExternalFileLimits
{
    public const CEILING_BYTES = 52428800;

    public const BUDGET_BYTES = 104857600;

    public const MAX_FILES = 25;

    public const MAX_REDIRECTS = 3;

    public const CONNECT_TIMEOUT = 5;

    public const FILE_TIMEOUT = 30;

    public const TIME_BUDGET_SHARE = 0.6;

    /** The time budget when PHP has no execution limit (max_execution_time 0). */
    public const DEFAULT_TIME_BUDGET = 120;

    /** Below this many seconds an import does not start to download. */
    public const MIN_TIME_BUDGET = 5;

    public const MAX_URL_LENGTH = 2048;

    public const SCHEMES = ['http', 'https'];

    public const PORTS = [80, 443];

    public const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * The seconds an import may spend downloading, from PHP's max_execution_time: the share of it, or the default when the
     * limit is 0 (no limit). Below MIN_TIME_BUDGET the import refuses to start (budget).
     */
    public static function timeBudget(): int
    {
        $limit = (int)ini_get('max_execution_time');

        return $limit > 0 ? (int)floor($limit * self::TIME_BUDGET_SHARE) : self::DEFAULT_TIME_BUDGET;
    }

    public static function userAgent(): string
    {
        $version = defined('MyShowcase\\VERSION') ? (string)constant('MyShowcase\\VERSION') : 'dev';

        return 'MyShowcase-Import/' . preg_replace('/[^A-Za-z0-9._-]/', '', $version);
    }
}
