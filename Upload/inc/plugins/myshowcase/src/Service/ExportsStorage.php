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
     * Turns the setting's value into a folder relative to the forum folder: surrounding whitespace is dropped, empty is
     * the default, and any value that is not a plain relative folder inside the forum folder, and a dedicated one (see
     * isDedicatedFolder()), is null. Used where the setting is read (directory(), configurationProblem()) and where it is
     * saved (admin_config_settings_change in admin_hooks.php).
     */
    public static function normalizeDirectory(string $value): ?string
    {
        $value = trim($value);

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

        if (!self::isInsideForumRoot($normalized) || !self::isDedicatedFolder(rtrim(MYBB_ROOT, '/') . '/' . $normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * A folder can be used for exports when it does not exist yet, or when it is a real directory (not a link) that holds
     * nothing but export files, the plugin's deny file exactly as written, and an index page. Anything else in it is
     * somebody else's, so the plugin never writes to it or deletes from it.
     */
    public static function isDedicatedFolder(string $directory): bool
    {
        if (!file_exists($directory) && !is_link($directory)) {
            return true;
        }

        if (is_link($directory) || !is_dir($directory)) {
            return false;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . '/' . $name;

            if (is_link($path) || !is_file($path)) {
                return false;
            }

            $owned = match ($name) {
                '.htaccess' => file_get_contents($path) === self::DENY_FILE,
                'index.html' => true,
                default => (new self())->isValidFileName($name),
            };

            if (!$owned) {
                return false;
            }
        }

        return true;
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
     * they are not there yet. An existing index page is kept. An existing deny file must be the plugin's own, exactly:
     * otherwise the folder is not protected by this plugin and nothing is written to it, so false is returned.
     */
    public function ensureDirectory(): bool
    {
        $directory = $this->directory();

        if (!self::isDedicatedFolder($directory)) {
            return false;
        }

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return false;
        }

        if (is_file($directory . '/.htaccess')) {
            if (file_get_contents($directory . '/.htaccess') !== self::DENY_FILE) {
                return false;
            }
        } elseif (file_put_contents($directory . '/.htaccess', self::DENY_FILE) === false) {
            return false;
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
     * Removes the stored exports (regular files only), the deny file when it is exactly the plugin's, and the index page
     * only when the folder held nothing else; then the folder if it is empty (plugin uninstall). Links are never followed
     * and parent folders are never removed.
     */
    public function removeAll(): void
    {
        $directory = $this->directory();

        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));

        $is_regular = static fn(string $path): bool => is_file($path) && !is_link($path);

        $htaccess = $directory . '/.htaccess';

        $deny_file_is_ours = $is_regular($htaccess) && file_get_contents($htaccess) === self::DENY_FILE;

        $only_plugin_files = true;

        foreach ($names as $name) {
            $is_export = $this->isValidFileName($name) && $is_regular($directory . '/' . $name);

            $is_ours = ($name === '.htaccess' && $deny_file_is_ours) || ($name === 'index.html' && $is_regular($directory . '/' . $name));

            if (!$is_export && !$is_ours) {
                $only_plugin_files = false;
            }
        }

        foreach ($names as $name) {
            $path = $directory . '/' . $name;

            if ($this->isValidFileName($name) && $is_regular($path)) {
                @unlink($path);
            } elseif ($name === '.htaccess' && $deny_file_is_ours) {
                @unlink($path);
            } elseif ($name === 'index.html' && $only_plugin_files && $is_regular($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
