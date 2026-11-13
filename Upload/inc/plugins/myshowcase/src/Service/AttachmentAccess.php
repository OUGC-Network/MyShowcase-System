<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\ExternalUrl;

/**
 * The public URLs of attachments, and who may download them (decisions D48, D52 and D58). Shared by the download route
 * (AttachmentsController::viewThumbnail()), the export, which writes the URL of a file field, and the import, which
 * resolves a URL of this board's own attachment route.
 *
 * decide() is the only place where the rules of the download route are written: the route answers its denials from it,
 * and the export gives a file no URL, and the import copies no file, that decide() does not allow for the current user.
 */
final class AttachmentAccess
{
    /** decide(): the user may get the attachment. */
    public const ALLOWED = 'allowed';

    /** decide(): answered like an unknown attachment (404), so that a hidden file cannot be told from a missing one. */
    public const HIDDEN = 'hidden';

    /** decide(): answered as a missing permission (403). */
    public const FORBIDDEN = 'forbidden';

    /** @var array<int, array<string, mixed>|null> the entry rows read by this instance, by entry id */
    private array $entries = [];

    public function __construct(private readonly ShowcaseContext $context)
    {
    }

    /**
     * The base of the public URLs: the showcase's "Url to Showcase" when it is set, else the board's address (as the
     * links of the showcase are built). Without a trailing slash.
     */
    public function publicBase(): string
    {
        global $mybb;

        $base = trim((string)$this->context->showcase->showcase_url);

        if ($base === '') {
            $base = (string)($mybb->settings['bburl'] ?? '');
        }

        return rtrim($base, '/');
    }

    /**
     * The public URL of an attachment, or '' when it has none: the base and the download route of the file, or nothing
     * when the base is not an absolute http or https address.
     */
    public function publicUrl(string $public_id, string $attachment_name): string
    {
        $path = $this->context->routing->generateUrl(
            Routes::AttachmentDownload,
            ['attachment_public_id' => $public_id, 'attachment_name' => $attachment_name]
        );

        return self::joinUrl($this->publicBase(), $path);
    }

    /**
     * Joins the base and a route path into an absolute http or https URL, or returns '' when either part is not one.
     */
    public static function joinUrl(string $base, string $path): string
    {
        if (!preg_match('#^https?://[^/?\#\s]+#i', $base) || !str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f]/', $path)) {
            return '';
        }

        return rtrim($base, '/') . $path;
    }

    /**
     * The board's attachment types of this showcase, as the download route reads them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function attachmentTypes(): array
    {
        return $this->context->cache_manager->get(CacheDataTypes::AttachmentTypes)[$this->context->showcase->showcase_id] ?? [];
    }

    /**
     * The one decision on who may get an attachment, for the current user (decision D58). The download route, the export
     * and the import's own-board URLs all ask this method; nothing else repeats its checks. The checks, in this order:
     *
     * 1. the main permission to view the board;
     * 2. the owning entry: it must be visible to the user (CanViewStatus, the entry rule of decisions D29 and D30), for
     *    everyone, the owner included; an attachment without an entry (its form is not saved yet) is for its owner only;
     * 3. for a user who is not the owner: the permissions to view entries and attachments, the attachment's status
     *    (an awaiting attachment needs the unapproved permission), and, for a file and not a thumbnail, the permission to
     *    download;
     * 4. the attachment type of its extension and MIME type, and for a user who is not the owner, its allowed groups.
     *
     * A thumbnail is a preview: only a file needs the download permission (step 3). The owner of a file needs no download
     * permission.
     *
     * @param array<string, mixed> $attachment a row with entry_id, user_id, status, attachment_name and mime_type
     * @param array<int, array<string, mixed>> $types from attachmentTypes()
     * @return string self::ALLOWED, self::HIDDEN (answered like an unknown attachment) or self::FORBIDDEN (no permission)
     */
    public function decide(array $attachment, bool $thumbnail, array $types): string
    {
        $user = $this->context->current_user;

        $is_owner = (int)$user->uid > 0 && (int)$user->uid === (int)$attachment['user_id'];

        if (!$user->hasPermission(UserPermissions::MainCanView)) {
            return self::FORBIDDEN;
        }

        $entry_id = (int)($attachment['entry_id'] ?? 0);

        if ($entry_id > 0) {
            $entry = $this->entryOf($entry_id);

            if ($entry === null) {
                return self::HIDDEN;
            }

            if (!$this->context->entries_manager->canViewStatus((int)$entry['status'], (int)$entry['user_id'])) {
                return self::HIDDEN;
            }
        } elseif (!$is_owner) {
            return self::HIDDEN;
        }

        if (!$is_owner) {
            if (!$user->hasPermission(UserPermissions::EntriesCanView) || !$user->hasPermission(UserPermissions::AttachmentsCanView)) {
                return self::FORBIDDEN;
            }

            if ((int)$attachment['status'] !== ContentStatuses::VisibleApproved &&
                !$user->hasPermission(UserPermissions::AttachmentsCanViewUnapproved)) {
                return self::HIDDEN;
            }

            if (!$thumbnail && !$user->hasPermission(UserPermissions::AttachmentsCanDownload)) {
                return self::FORBIDDEN;
            }
        }

        $type = $this->attachmentType($attachment, $types);

        if ($type === null) {
            return self::HIDDEN;
        }

        if (!$is_owner && !is_member($type['allowed_groups'])) {
            return self::FORBIDDEN;
        }

        return self::ALLOWED;
    }

    /**
     * The board's type of an attachment, by its extension and its MIME type, or null when no type of the showcase allows it.
     *
     * @param array<string, mixed> $attachment
     * @param array<int, array<string, mixed>> $types from attachmentTypes()
     * @return array<string, mixed>|null
     */
    public function attachmentType(array $attachment, array $types): ?array
    {
        $extension = my_strtolower(get_extension((string)$attachment['attachment_name']));

        $mime = my_strtolower((string)$attachment['mime_type']);

        foreach ($types as $type) {
            if ($type['file_extension'] === $extension && $type['mime_type'] === $mime) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The entry row of an attachment's entry (entry_id, user_id, status), or null when the entry does not exist. Read once
     * per entry for this instance.
     *
     * @return array<string, mixed>|null
     */
    private function entryOf(int $entry_id): ?array
    {
        if (!array_key_exists($entry_id, $this->entries)) {
            $row = $this->context->entries_repository->queryEntry(
                ["entry_id='" . $entry_id . "'"],
                ['entry_id', 'user_id', 'status']
            );

            $this->entries[$entry_id] = empty($row['entry_id']) ? null : $row;
        }

        return $this->entries[$entry_id];
    }

    /**
     * The port the board's own address uses, when the text is a URL of that address (decision D52): the board may be on a
     * port other than 80 or 443. Null for any other URL, which must then use 80 or 443 like every external file.
     *
     * @return int[]
     */
    public function ownPorts(string $text): array
    {
        $base = $this->publicBase();

        if ($base === '' || !str_starts_with($text, $base . '/')) {
            return [];
        }

        $parts = parse_url($base);

        return isset($parts['port']) ? [(int)$parts['port']] : [];
    }

    /**
     * The attachment a URL names, when the URL is this board's own download route for a file of this showcase (decision
     * D52). The URL must be exactly the route the board generates for that file, on the board's own base, so a look-alike
     * host or path is not one of the board's files and goes through the fetcher.
     *
     * @return array<string, mixed>|null the attachment row, or null when the URL is not one of this board's files
     */
    public function ownAttachment(ExternalUrl $url): ?array
    {
        $base = $this->publicBase();

        if ($base === '' || !str_starts_with($url->text, $base)) {
            return null;
        }

        $route = substr($url->text, strlen($base));

        if (preg_match('#/attachment/([^/]+)/([^/]+)$#', $route, $parts) !== 1) {
            return null;
        }

        $public_id = rawurldecode($parts[1]);

        $name = rawurldecode($parts[2]);

        $expected = $this->context->routing->generateUrl(
            Routes::AttachmentDownload,
            ['attachment_public_id' => $public_id, 'attachment_name' => $name]
        );

        if ($expected !== $route) {
            return null;
        }

        $database = $this->context->database;

        $row = $this->context->attachments_repository->queryAttachment(
            [
                "showcase_id='" . (int)$this->context->showcase->showcase_id . "'",
                "public_id='" . $database->escape_string($public_id) . "'",
                "attachment_name='" . $database->escape_string($name) . "'",
            ],
            ['attachment_id', 'public_id', 'attachment_name', 'file_name', 'entry_id', 'user_id', 'status', 'mime_type', 'thumbnail_name', 'file_size']
        );

        return empty($row['attachment_id']) ? null : $row;
    }
}
