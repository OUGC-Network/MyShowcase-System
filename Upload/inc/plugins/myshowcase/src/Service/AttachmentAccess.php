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
 * The public URLs of attachments, and who may download them (decisions D48 and D52). Shared by the export, which writes
 * the URL of a file field, and the import, which resolves a URL of this board's own attachment route.
 *
 * mayDownload() repeats the checks of the download route (AttachmentsController::viewThumbnail(), the same checks in the
 * same order), for the current user: a file the user may not download is never given a URL, and an import may not copy
 * one.
 */
final class AttachmentAccess
{
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
     * Whether the current user could download this file through the download route. The owner of the file needs no
     * download permission (the route does not check it for the owner either).
     *
     * @param array<string, mixed> $attachment a row with user_id, status, attachment_name and mime_type
     * @param array<int, array<string, mixed>> $types from attachmentTypes()
     */
    public function mayDownload(array $attachment, array $types): bool
    {
        $user = $this->context->current_user;

        $is_owner = (int)$user->uid > 0 && (int)$user->uid === (int)$attachment['user_id'];

        if (!$user->hasPermission(UserPermissions::MainCanView)) {
            return false;
        }

        if (!$is_owner) {
            if (!$user->hasPermission(UserPermissions::EntriesCanView) || !$user->hasPermission(UserPermissions::AttachmentsCanView)) {
                return false;
            }

            if ((int)$attachment['status'] !== ContentStatuses::VisibleApproved &&
                !$user->hasPermission(UserPermissions::AttachmentsCanViewUnapproved)) {
                return false;
            }

            if (!$user->hasPermission(UserPermissions::AttachmentsCanDownload)) {
                return false;
            }
        }

        $extension = my_strtolower(get_extension((string)$attachment['attachment_name']));

        $mime = my_strtolower((string)$attachment['mime_type']);

        foreach ($types as $type) {
            if ($type['file_extension'] === $extension && $type['mime_type'] === $mime) {
                return $is_owner || is_member($type['allowed_groups']);
            }
        }

        return false;
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
            ['attachment_id', 'public_id', 'attachment_name', 'file_name', 'user_id', 'status', 'mime_type', 'thumbnail_name', 'file_size']
        );

        return empty($row['attachment_id']) ? null : $row;
    }
}
