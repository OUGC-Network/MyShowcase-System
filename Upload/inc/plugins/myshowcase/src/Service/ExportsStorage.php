<?php

declare(strict_types=1);

namespace MyShowcase\Service;

/**
 * Where the exported files live on disk and how they are named and reached. The files are never served from a URL: the
 * controller finds the row, checks the owner, asks pathOf() and sends the file itself.
 *
 * The folder is set by the global MyBB setting myshowcase_exports_directory: a path relative to the forum folder. Empty
 * means uploads/myshowcase/exports/. The folder must stay inside the forum folder; a value that does not is replaced by
 * the default and reported. The folder carries a deny file that only Apache reads; other servers need the rule in the
 * documentation.
 */
class ExportsStorage
{
    public const SETTING = 'myshowcase_exports_directory';

    public const DEFAULT_DIRECTORY = 'uploads/myshowcase/exports';

    private const FILE_NAME_PATTERN = '/^[0-9]+-[a-z0-9_-]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{32}\.xml$/D';

    private const DIRECTORY_PATTERN = '#^[A-Za-z0-9_./-]+$#D';

    private const DENY_FILE = "# Nothing in here is meant to be requested from the web.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";

    public function __construct(private readonly ?string $directory = null)
    {
    }

    /**
     * Turns the setting's value into a folder relative to the forum folder: empty is the default, and any value that is
     * not a plain relative folder inside the forum folder is null. Used both when the setting is read and when it is saved.
     */
    public static function normalizeDirectory(string $value): ?string
    {
        if ($value === '') {
            return self::DEFAULT_DIRECTORY;
        }

        if (preg_match(self::DIRECTORY_PATTERN, $value) !== 1 || str_starts_with($value, '/')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        $normalized = implode('/', $segments);

        return self::isInsideForumRoot($normalized) ? $normalized : null;
    }

    /**
     * The folder must resolve inside the forum folder: the nearest existing part is resolved with realpath(), so a
     * symlink that points elsewhere is refused too.
     */
    private static function isInsideForumRoot(string $relative): bool
    {
        $root = realpath(MYBB_ROOT);

        if ($root === false) {
            return false;
        }

        $probe = rtrim(MYBB_ROOT, '/') . '/' . $relative;

        $missing = [];

        while (!file_exists($probe)) {
            $missing[] = basename($probe);

            $probe = dirname($probe);
        }

        $real = realpath($probe);

        if ($real === false) {
            return false;
        }

        $resolved = rtrim($real, '/') . ($missing === [] ? '' : '/' . implode('/', array_reverse($missing)));

        return $resolved !== rtrim($root, '/') && str_starts_with($resolved . '/', rtrim($root, '/') . '/');
    }

    private static function configuredValue(): string
    {
        return isset($GLOBALS['mybb']->settings) ? (string)($GLOBALS['mybb']->settings[self::SETTING] ?? '') : '';
    }

    /**
     * The language key of the problem with the setting, or null when it is valid (or not read from the setting at all).
     */
    public function configurationProblem(): ?string
    {
        if ($this->directory !== null) {
            return null;
        }

        return self::normalizeDirectory(self::configuredValue()) === null
            ? 'myshowcase_import_export_error_exports_directory'
            : null;
    }

    public function directory(): string
    {
        if ($this->directory !== null && $this->directory !== '') {
            return rtrim($this->directory, '/\\');
        }

        $relative = self::normalizeDirectory(self::configuredValue()) ?? self::DEFAULT_DIRECTORY;

        return rtrim(MYBB_ROOT, '/') . '/' . $relative;
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
     * Creates the folder (and missing parents inside the forum folder) with its deny file and an empty index page when
     * they are not there yet.
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
     * Removes the stored exports and the deny file and index page this plugin wrote, then the folder if it is empty
     * (plugin uninstall). Links are never followed, and parent folders are never removed.
     */
    public function removeAll(): void
    {
        $directory = $this->directory();

        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory . '/' . $name;

            if (is_link($path) || !is_file($path)) {
                continue;
            }

            if ($this->isValidFileName($name) || $name === '.htaccess' || $name === 'index.html') {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
