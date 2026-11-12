<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use const MyShowcase\ROOT;
use const MyShowcase\SETTINGS;

/**
 * Where the exported files live on disk and how they are named and reached. The files are never served from a URL: the
 * controller finds the row, checks the owner, asks pathOf() and sends the file itself.
 *
 * The default folder is inc/plugins/myshowcase/exports/, protected by a deny file that only Apache reads. The setting
 * exportsDirectory (inc/plugins/myshowcase.php) can name any absolute folder, which should be outside the web root.
 */
class ExportsStorage
{
    private const FILE_NAME_PATTERN = '/^[0-9]+-[a-z0-9_-]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{32}\.xml$/D';

    private const DENY_FILE = "# Nothing in here is meant to be requested from the web.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";

    public function __construct(private readonly ?string $directory = null)
    {
    }

    public function directory(): string
    {
        if ($this->directory !== null && $this->directory !== '') {
            return rtrim($this->directory, '/\\');
        }

        $configured = (string)(SETTINGS['exportsDirectory'] ?? '');

        return $configured !== '' ? rtrim($configured, '/\\') : ROOT . '/exports';
    }

    /**
     * Whether the folder is below the board's folder, where a web server can reach it unless it is told not to.
     */
    public function isInsideWebRoot(): bool
    {
        $directory = realpath($this->directory());

        $web_root = realpath(MYBB_ROOT);

        return $directory !== false && $web_root !== false && str_starts_with($directory . '/', rtrim($web_root, '/') . '/');
    }

    /**
     * Creates the folder with its deny file and an empty index page when they are not there yet.
     */
    public function ensureDirectory(): bool
    {
        $directory = $this->directory();

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return false;
        }

        if (!is_file($directory . '/.htaccess')) {
            file_put_contents($directory . '/.htaccess', self::DENY_FILE);
        }

        if (!is_file($directory . '/index.html')) {
            file_put_contents($directory . '/index.html', "<html>\n<head>\n<title></title>\n</head>\n<body>\n&nbsp;\n</body>\n</html>\n");
        }

        return is_writable($directory);
    }

    /**
     * {export id}-{route identifier}-{YYYYMMDD-HHMMSS}-{32 random hex}.xml. Nothing in it comes from a request: the
     * identifier is reduced to the characters of a route identifier.
     */
    public function fileName(int $export_id, string $route_identifier, int $time): string
    {
        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower($route_identifier));

        return $export_id . '-' . ($identifier !== '' ? $identifier : 'showcase') . '-' . gmdate('Ymd-His', $time) . '-' .
            bin2hex(random_bytes(16)) . '.xml';
    }

    public function isValidFileName(string $file_name): bool
    {
        return preg_match(self::FILE_NAME_PATTERN, $file_name) === 1;
    }

    public function write(string $file_name, string $contents): bool
    {
        if (!$this->isValidFileName($file_name) || !$this->ensureDirectory()) {
            return false;
        }

        $path = $this->directory() . '/' . $file_name;

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            return false;
        }

        chmod($path, 0640);

        return true;
    }

    /**
     * The real path of a stored file, or null when the name is not one this class makes, the file is gone, or it resolves
     * outside the folder.
     */
    public function pathOf(string $file_name): ?string
    {
        if (!$this->isValidFileName($file_name)) {
            return null;
        }

        $directory = realpath($this->directory());

        $path = realpath($this->directory() . '/' . $file_name);

        if ($directory === false || $path === false || !is_file($path) || dirname($path) !== $directory) {
            return null;
        }

        return $path;
    }

    public function delete(string $file_name): bool
    {
        $path = $this->pathOf($file_name);

        return $path === null || unlink($path);
    }

    /**
     * Removes every stored export and the folder's own files, then the folder (plugin uninstall).
     */
    public function removeAll(): void
    {
        $directory = $this->directory();

        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if ($this->isValidFileName($name)) {
                @unlink($directory . '/' . $name);
            }
        }

        // The shipped folder keeps its deny file, index page and README; a folder set in the settings is removed if empty.
        if ($directory !== ROOT . '/exports') {
            @unlink($directory . '/.htaccess');
            @unlink($directory . '/index.html');
            @rmdir($directory);
        }
    }
}
