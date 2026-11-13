<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Util\ExternalFileException;
use MyShowcase\Util\ExternalFileLimits;
use MyShowcase\Util\ExternalUrl;

/**
 * The production transport (decision D53, section 5.8.3 to 5.8.6): cURL, with the connection pinned to the validated
 * address, no redirects followed (the fetcher checks each hop), certificates verified, no cookies, no credentials, the
 * body streamed into the target file and stopped at the size limit, and the connect and total time limits.
 */
final class CurlExternalFileTransport implements ExternalFileTransport
{
    public function request(ExternalUrl $url, string $address, string $target_path, int $max_bytes, int $total_seconds): ExternalFileTransportResult
    {
        if (!function_exists('curl_init')) {
            throw new ExternalFileException('curl_missing');
        }

        $handle = @fopen($target_path, 'wb');

        if ($handle === false) {
            throw new ExternalFileException('unknown');
        }

        $written = 0;

        $exceeded = false;

        $location = null;

        $connect = max(1, min(ExternalFileLimits::CONNECT_TIMEOUT, $total_seconds));

        $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;

        $curl = curl_init((string)$url);

        if ($curl === false) {
            fclose($handle);

            throw new ExternalFileException('unknown');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$url->host . ':' . $url->port . ':' . $pinned],
            CURLOPT_CONNECTTIMEOUT => $connect,
            CURLOPT_TIMEOUT => max(1, $total_seconds),
            CURLOPT_USERAGENT => ExternalFileLimits::userAgent(),
            CURLOPT_HTTPHEADER => ['Accept: */*', 'Accept-Encoding: identity'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                if (stripos($header, 'location:') === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use ($handle, &$written, &$exceeded, $max_bytes): int {
                $written += strlen($data);

                if ($written > $max_bytes) {
                    $exceeded = true;

                    return 0;
                }

                fwrite($handle, $data);

                return strlen($data);
            },
        ]);

        $ok = curl_exec($curl);

        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        $errno = curl_errno($curl);

        curl_close($curl);

        fclose($handle);

        if ($exceeded) {
            return new ExternalFileTransportResult(0, null, $written, 'size');
        }

        if ($ok === false) {
            return new ExternalFileTransportResult(0, null, $written, self::reasonOf($errno));
        }

        return new ExternalFileTransportResult($status, $location, $written);
    }

    /** The reason code of a cURL error number (never its message). */
    public static function reasonOf(int $errno): string
    {
        return match ($errno) {
            28 => 'time',
            7 => 'connect',
            35, 51, 58, 59, 60, 66, 77, 83 => 'tls',
            default => 'unknown',
        };
    }
}
