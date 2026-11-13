<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use finfo;
use RuntimeException;

/**
 * Reads an uploaded import file (design doc, section 6, stages 2 and 3): the upload itself, the size against the
 * effective cap, the extension, the MIME type and the first byte. The content is parsed later (XmlFile).
 *
 * The file is read from PHP's temporary path and never stored; discard() removes that temporary file, and the controller
 * calls it on every path (success, refusal, exception).
 */
final class ImportUpload
{
    public const REASON_NO_FILE = 'no_file';

    public const REASON_UPLOAD = 'upload';

    public const REASON_TOO_LARGE = 'too_large';

    public const REASON_EMPTY = 'empty';

    public const REASON_EXTENSION = 'extension';

    public const REASON_MIME = 'mime';

    public const REASON_NOT_XML = 'not_xml';

    public const REASON_MEMORY = 'memory';

    /** Memory an XML file needs per byte while it is read and checked (ImportLimits: about 28 times, with headroom). */
    public const MEMORY_PER_BYTE = 30;

    private const MIME_TYPES = ['application/xml', 'text/xml', 'text/plain'];

    /** @var callable(string): bool */
    private $is_uploaded;

    /**
     * @param callable(string): bool|null $is_uploaded is_uploaded_file() unless a test supplies its own
     */
    public function __construct(
        private readonly int $cap,
        private readonly ?string $memory_limit = null,
        ?callable $is_uploaded = null,
    ) {
        $this->is_uploaded = $is_uploaded ?? static fn(string $path): bool => is_uploaded_file($path);
    }

    /**
     * @param array<string, mixed>|null $file the $_FILES entry of the upload
     * @throws ImportUploadException
     */
    public function read(?array $file): string
    {
        if (!is_array($file) || (string)($file['tmp_name'] ?? '') === '' || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ImportUploadException(self::REASON_NO_FILE);
        }

        if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ImportUploadException(self::REASON_TOO_LARGE);
        }

        if (($file['error'] ?? null) !== UPLOAD_ERR_OK) {
            throw new ImportUploadException(self::REASON_UPLOAD);
        }

        $path = (string)$file['tmp_name'];

        if (!($this->is_uploaded)($path) || !is_file($path)) {
            throw new ImportUploadException(self::REASON_UPLOAD);
        }

        if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'xml') {
            throw new ImportUploadException(self::REASON_EXTENSION);
        }

        $size = (int)filesize($path);

        if ($size <= 0) {
            throw new ImportUploadException(self::REASON_EMPTY);
        }

        if ($size > $this->cap) {
            throw new ImportUploadException(self::REASON_TOO_LARGE);
        }

        $bytes = ImportLimits::bytesOf((string)($this->memory_limit ?? ini_get('memory_limit')));

        if ($bytes > 0 && self::MEMORY_PER_BYTE * $size > $bytes - memory_get_usage()) {
            throw new ImportUploadException(self::REASON_MEMORY);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (!in_array($mime, self::MIME_TYPES, true)) {
            throw new ImportUploadException(self::REASON_MIME);
        }

        $content = $this->readBounded($path);

        $start = str_starts_with($content, "\xEF\xBB\xBF") ? 3 : 0;

        if (($content[$start] ?? '') !== '<') {
            throw new ImportUploadException(self::REASON_NOT_XML);
        }

        return $content;
    }

    /**
     * Removes the temporary file of an upload. It only ever touches a file PHP received for this request.
     *
     * @param array<string, mixed>|null $file
     */
    public function discard(?array $file): void
    {
        $path = is_array($file) ? (string)($file['tmp_name'] ?? '') : '';

        if ($path !== '' && is_file($path) && ($this->is_uploaded)($path)) {
            @unlink($path);
        }
    }

    /**
     * @throws RuntimeException
     */
    private function readBounded(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file cannot be read.');
        }

        try {
            $content = fread($handle, $this->cap + 1);
        } finally {
            fclose($handle);
        }

        return $content === false ? '' : $content;
    }
}
