<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Util\AddressClass;
use MyShowcase\Util\ExternalFileException;
use MyShowcase\Util\ExternalFileLimits;
use MyShowcase\Util\ExternalUrl;

/**
 * Downloads the file of a URL in an import (decision D53, section 5.8). Pure logic: the addresses come from an
 * ExternalFileResolver and the bytes from an ExternalFileTransport, both injected, so the rules are tested without a
 * network (tests/import/fetcher/run.php).
 *
 * Every hop of the download is checked the same way: the URL's syntax, every address the host resolves to (all of them
 * must be public), then a connection to the one validated address (the transport pins it, so a second answer of the DNS
 * cannot change the target). A redirect is followed only when it keeps the scheme and the port, at most three times.
 */
final class ExternalFileFetcher
{
    public function __construct(
        private readonly ExternalFileResolver $resolver,
        private readonly ExternalFileTransport $transport,
        private readonly ExternalFileTempArea $temp,
    ) {
    }

    /**
     * @param int $max_bytes the size limit of this file (the smallest of the field's, the type's and the ceiling)
     * @param int $total_seconds the time limit of this file
     * @throws ExternalFileException with the reason of the first failed rule; the temporary file is removed then
     */
    public function download(string $url_text, int $max_bytes, int $total_seconds): ExternalFileDownload
    {
        $path = $this->temp->newFile('.part');

        try {
            $current = ExternalUrl::parse($url_text);

            $visited = [];

            for ($hop = 0; ; $hop++) {
                $key = (string)$current;

                if (isset($visited[$key])) {
                    throw new ExternalFileException('redirects');
                }

                $visited[$key] = true;

                $address = $this->addressFor($current);

                $result = $this->transport->request($current, $address, $path, $max_bytes, $total_seconds);

                if ($result->error !== null) {
                    throw new ExternalFileException($result->error);
                }

                if (in_array($result->status, ExternalFileLimits::REDIRECT_STATUSES, true)) {
                    if ($hop >= ExternalFileLimits::MAX_REDIRECTS) {
                        throw new ExternalFileException('redirects');
                    }

                    if ($result->location === null || $result->location === '') {
                        throw new ExternalFileException('status');
                    }

                    $current = $current->redirectTo($result->location);

                    continue;
                }

                if ($result->status !== 200) {
                    throw new ExternalFileException('status');
                }

                if ($result->bytes === 0) {
                    throw new ExternalFileException('content');
                }

                return new ExternalFileDownload($path, $result->bytes, (string)$current);
            }
        } catch (ExternalFileException $exception) {
            if (is_file($path)) {
                @unlink($path);
            }

            throw $exception;
        }
    }

    /**
     * The one address the connection of this hop may use: the literal address of the URL, or the first of the host's
     * addresses once every one of them is public.
     *
     * @throws ExternalFileException
     */
    public function addressFor(ExternalUrl $url): string
    {
        $literal = $url->literalAddress();

        if ($literal !== null) {
            if (!AddressClass::isPublic($literal)) {
                throw new ExternalFileException('private_address');
            }

            return $literal;
        }

        if ($url->host === 'localhost' || str_ends_with($url->host, '.localhost')) {
            throw new ExternalFileException('private_address');
        }

        $addresses = $this->resolver->addresses($url->host);

        if ($addresses === []) {
            throw new ExternalFileException('dns');
        }

        foreach ($addresses as $address) {
            if (!AddressClass::isPublic($address)) {
                throw new ExternalFileException('private_address');
            }
        }

        return $addresses[0];
    }
}
