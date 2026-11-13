<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Util\ExternalFileException;
use MyShowcase\Util\ExternalFileLimits;
use MyShowcase\Util\ExternalUrl;

/**
 * The checks a downloaded file must pass before it is handed to the form's attachment pipeline (decision D53, section
 * 5.8.7). They are the checks an upload makes: the board's attachment type by extension and by the MIME type that the
 * file really has (finfo, not the one the server claims), the sizes, and a valid image for an image type. A file that
 * fails any of them is refused with a reason code. SVG is refused: no SVG type is allowed on the board by default, and
 * the image pipeline cannot process it.
 */
final class ExternalFileAcceptance
{
    /** Extensions whose content is checked as an image (the same list as the upload's thumbnail step). */
    private const IMAGE_EXTENSIONS = ['gif', 'png', 'jpg', 'jpeg', 'jpe', 'webp'];

    /** @param array<int, array<string, mixed>> $types the board's attachment types of the showcase */
    public function __construct(private readonly array $types)
    {
    }

    /**
     * @return array{name: string, extension: string, mime: string, size: int}
     * @throws ExternalFileException
     */
    public function accept(ExternalFileDownload $download, ExternalUrl $url, int $field_maximum_bytes): array
    {
        $detected = (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $download->path);

        $name = self::nameOf($url);

        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === '') {
            $extension = self::extensionOfMime($detected);

            if ($extension === '') {
                throw new ExternalFileException('type');
            }

            $name .= '.' . $extension;
        }

        $type = null;

        foreach ($this->types as $candidate) {
            if (strtolower((string)$candidate['file_extension']) === $extension) {
                $type = $candidate;

                break;
            }
        }

        if ($type === null || strtolower((string)$type['mime_type']) !== $detected) {
            throw new ExternalFileException('type');
        }

        $limit = $field_maximum_bytes > 0 ? $field_maximum_bytes : ExternalFileLimits::CEILING_BYTES;

        $type_maximum = (int)($type['maximum_size'] ?? 0);

        if ($type_maximum > 0) {
            $limit = min($limit, $type_maximum * 1024);
        }

        $limit = min($limit, ExternalFileLimits::CEILING_BYTES);

        if ($download->bytes > $limit) {
            throw new ExternalFileException('size');
        }

        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            $image = @getimagesize($download->path);

            if ($image === false || (string)($image['mime'] ?? '') !== $detected) {
                throw new ExternalFileException('content');
            }
        }

        return ['name' => $name, 'extension' => $extension, 'mime' => $detected, 'size' => $download->bytes];
    }

    /**
     * The file name the URL's last path segment gives: decoded, reduced to ASCII letters, digits, dots, hyphens and
     * underscores, without leading dots. A file name that is empty after this is "download".
     */
    public static function nameOf(ExternalUrl $url): string
    {
        $path = explode('?', $url->path, 2)[0];

        $segment = rawurldecode((string)substr((string)strrchr('/' . $path, '/'), 1));

        $name = ltrim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $segment), '.');

        return $name === '' ? 'download' : substr($name, 0, 200);
    }

    private static function extensionOfMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/zip' => 'zip',
            default => '',
        };
    }
}
