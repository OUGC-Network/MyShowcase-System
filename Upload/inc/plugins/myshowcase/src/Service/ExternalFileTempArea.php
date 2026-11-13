<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Util\ExternalFileException;

/**
 * A private folder for the files an import downloads (section 5.8.9). Every file is removed by cleanup(), which an import
 * calls on every path (success, refusal, exception and shutdown). Nothing else in the system is ever removed.
 */
final class ExternalFileTempArea
{
    private ?string $directory = null;

    /** @var string[] */
    private array $files = [];

    /**
     * @throws ExternalFileException when the folder cannot be made
     */
    public function newFile(string $suffix = ''): string
    {
        if ($this->directory === null) {
            $base = rtrim(sys_get_temp_dir(), '/\\');

            $directory = $base . DIRECTORY_SEPARATOR . 'myshowcase-import-' . bin2hex(random_bytes(8));

            if (!mkdir($directory, 0700) && !is_dir($directory)) {
                throw new ExternalFileException('unknown');
            }

            $this->directory = $directory;
        }

        $path = $this->directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . $suffix;

        $this->files[] = $path;

        return $path;
    }

    /** Removes every file and the folder made here, and forgets them. Safe to call more than once. */
    public function cleanup(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->files = [];

        if ($this->directory !== null && is_dir($this->directory)) {
            @rmdir($this->directory);
        }

        $this->directory = null;
    }

    public function directory(): ?string
    {
        return $this->directory;
    }
}
