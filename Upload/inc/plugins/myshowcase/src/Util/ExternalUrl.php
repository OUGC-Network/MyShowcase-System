<?php

declare(strict_types=1);

namespace MyShowcase\Util;

/**
 * An http or https URL of an import file, checked by its syntax before any network access (section 5.7), and the host
 * part, read as an address when it is one (section 5.8.2).
 *
 * The rules: at most 2048 characters; no control characters, spaces, non-ASCII bytes or backslashes; the scheme is http
 * or https; no userinfo; no fragment; a host of letters, digits, hyphens and dots, or an IPv6 literal in brackets; a port
 * that is absent or 80 or 443.
 */
final class ExternalUrl
{
    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $path,
        public readonly string $text,
    ) {
    }

    /**
     * @throws ExternalFileException with the reason of the first rule the URL breaks
     */
    public static function parse(string $text): self
    {
        if (strlen($text) > ExternalFileLimits::MAX_URL_LENGTH) {
            throw new ExternalFileException('length');
        }

        if (preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $text) === 1) {
            throw new ExternalFileException('characters');
        }

        if (str_contains($text, '#')) {
            throw new ExternalFileException('fragment');
        }

        if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*):(.*)$#', $text, $parts) !== 1) {
            throw new ExternalFileException('scheme');
        }

        $scheme = strtolower($parts[1]);

        if (!in_array($scheme, ExternalFileLimits::SCHEMES, true) || !str_starts_with($parts[2], '//')) {
            throw new ExternalFileException('scheme');
        }

        $rest = substr($parts[2], 2);

        $end = strcspn($rest, '/?');

        $authority = substr($rest, 0, $end);

        $path = substr($rest, $end);

        if (str_contains($authority, '@')) {
            throw new ExternalFileException('userinfo');
        }

        [$host, $port_text] = self::splitAuthority($authority);

        $port = $port_text === null
            ? ($scheme === 'https' ? 443 : 80)
            : self::portNumber($port_text);

        if ($path === '') {
            $path = '/';
        } elseif ($path[0] === '?') {
            $path = '/' . $path;
        }

        return new self($scheme, strtolower($host), $port, $path, $text);
    }

    /**
     * The URL a redirect points at, read against this URL: it must keep the scheme and the port, so a redirect cannot
     * change the protocol or the port (section 5.8.1).
     *
     * @throws ExternalFileException
     */
    public function redirectTo(string $location): self
    {
        if (str_starts_with($location, '//')) {
            // A network-path reference: the scheme of this URL, the host the reference names.
            $resolved = $this->scheme . ':' . $location;
        } elseif (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $location) === 1) {
            $resolved = $location;
        } else {
            // A path reference: this host and port, the path the reference gives.
            $resolved = $this->scheme . '://' . $this->authority() . (str_starts_with($location, '/') ? '' : '/') . $location;
        }

        $next = self::parse($resolved);

        if ($next->scheme !== $this->scheme) {
            throw new ExternalFileException('scheme');
        }

        if ($next->port !== $this->port) {
            throw new ExternalFileException('port');
        }

        return $next;
    }

    /**
     * The address written in the URL, when the host is one: an IPv6 literal, or an IPv4 address in any form inet_aton
     * accepts (decimal, octal with a leading zero, hexadecimal with 0x, and the forms of fewer than four parts). Null
     * when the host is a name.
     *
     * @throws ExternalFileException when the host looks numeric but is not a valid address
     */
    public function literalAddress(): ?string
    {
        if (str_contains($this->host, ':')) {
            return $this->host;
        }

        $labels = explode('.', rtrim($this->host, '.'));

        $numeric = true;

        foreach ($labels as $label) {
            if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $label) !== 1) {
                $numeric = false;

                break;
            }
        }

        if (!$numeric) {
            return null;
        }

        return self::ipv4FromParts($labels);
    }

    public function authority(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->port === $default ? $host : $host . ':' . $this->port;
    }

    public function __toString(): string
    {
        return $this->text;
    }

    /**
     * @return array{0: string, 1: ?string} the host and the port text (null when the URL has none)
     */
    private static function splitAuthority(string $authority): array
    {
        if ($authority === '') {
            throw new ExternalFileException('host');
        }

        if ($authority[0] === '[') {
            $close = strpos($authority, ']');

            if ($close === false) {
                throw new ExternalFileException('host');
            }

            $host = substr($authority, 1, $close - 1);

            $after = substr($authority, $close + 1);

            if (preg_match('/^[0-9a-f:.]+$/i', $host) !== 1 || !(str_starts_with($after, ':') || $after === '')) {
                throw new ExternalFileException('host');
            }

            if (@inet_pton($host) === false) {
                throw new ExternalFileException('host');
            }

            return [$host, $after === '' ? null : substr($after, 1)];
        }

        if (substr_count($authority, ':') > 1) {
            throw new ExternalFileException('host');
        }

        $port = null;

        if (str_contains($authority, ':')) {
            [$authority, $port] = explode(':', $authority, 2);
        }

        if ($authority === '' || preg_match('/^[A-Za-z0-9.-]+$/', $authority) !== 1) {
            throw new ExternalFileException('host');
        }

        return [$authority, $port];
    }

    private static function portNumber(string $port): int
    {
        if (preg_match('/^[0-9]{1,5}$/', $port) !== 1 || !in_array((int)$port, ExternalFileLimits::PORTS, true)) {
            throw new ExternalFileException('port');
        }

        return (int)$port;
    }

    /**
     * @param string[] $labels
     */
    private static function ipv4FromParts(array $labels): string
    {
        if (count($labels) > 4) {
            throw new ExternalFileException('host');
        }

        $values = [];

        foreach ($labels as $label) {
            if (str_starts_with(strtolower($label), '0x')) {
                $value = substr($label, 2) === '' ? 0 : hexdec(substr($label, 2));
            } elseif (strlen($label) > 1 && $label[0] === '0') {
                if (preg_match('/^[0-7]+$/', $label) !== 1) {
                    throw new ExternalFileException('host');
                }

                $value = octdec($label);
            } else {
                $value = (int)$label;
            }

            $values[] = (int)$value;
        }

        $count = count($values);

        $limits = [4294967295, 16777215, 65535, 255];

        foreach ($values as $index => $value) {
            // The last part may fill the rest of the address; the others are one byte each.
            $limit = $index === $count - 1 ? $limits[$index] : 255;

            if ($value < 0 || $value > $limit) {
                throw new ExternalFileException('host');
            }
        }

        $address = 0;

        foreach ($values as $index => $value) {
            if ($index === $count - 1) {
                $address += $value;
            } else {
                $address += $value << (8 * (3 - $index));
            }
        }

        return long2ip($address);
    }
}
