<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Controller;

use JetBrains\PhpStorm\NoReturn;
use MyBB;
use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\ShowcaseContext;
use MyShowcase\System\ExceptionInvalidRangeHeader;
use MyShowcase\System\ExceptionNonExistentFile;
use MyShowcase\System\ExceptionUnreadableFile;
use MyShowcase\System\ExceptionUnsatisfiableRange;
use MyShowcase\System\PartialFileServlet;
use MyShowcase\System\RangeHeader;
use Random\RandomException;
use Symfony\Component\Routing\Annotation\Route;

use function MyShowcase\Plugin\Functions\attachmentLogInsert;
use function MyShowcase\Plugin\Functions\hooksRun;

use const MyShowcase\Plugin\Core\TABLES_DATA;
use const MyShowcase\ROOT;

class AttachmentsController extends BaseController
{
    private readonly AttachmentsRepository $attachments_repository;
    public array $attachment_data;
    public bool $is_thumbnail = true;
    public int $download_count_increase = 1;

    /**
     * @throws RandomException
     */
    #[Route('/upload/{post_hash}', name: 'entry_create_upload', methods: ['POST'])]
    #[NoReturn]
    public function uploadAttachment(
        ?string $post_hash = null,
        ?string $entry_public_id = null,
    ): void {
        $this->verifyPassword();

        global $mybb, $lang;

        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            $this->error($lang->invalid_post_code);
        }

        if (!$this->context->showcase->attachments_allow_in_entries ||
            !$this->context->current_user->hasPermission(UserPermissions::AttachmentsCanUpload)) {
            $this->errorNoPermission();
        }

        $field_id_input = $mybb->get_input('field_id', \MyBB::INPUT_INT);

        /** @var CustomFieldEntity|false $field */
        $field = $this->context->custom_fields_collection->offsetGet($field_id_input);

        if (!$field instanceof CustomFieldEntity || empty($field->field_id)) {
            $this->errorNoPermission();
        }

        $field_key = $field->field_key;

        if (empty($_FILES[$field_key])) {
            $this->error($lang->myshowcase_create_update_attachment_error_missing_file);
        }

        $field_definition = $this->context->collection_fields[$field->field_id];

        if ($field_definition['html_type'] !== FieldHtmlTypes::File ||
            !is_member($field_definition['allowed_groups_fill'])) {
            $this->errorNoPermission();
        }

        $entry_id = null;

        if ($post_hash === null) {
            $entry = $this->context->entries_repository->queryEntry(
                ["public_id='{$this->context->database->escape_string((string)$entry_public_id)}'"],
                ['entry_id', 'post_hash', 'status', 'user_id'],
            );

            if (!$entry) {
                $this->errorNoPermission();
            }

            $this->context->entries_manager->addEntry($entry);

            $entry = $this->context->entries_manager->getEntryByID($entry['entry_id']);

            // Files of an existing entry: whoever may edit the entry.
            if (!$this->context->current_user->hasPermission(UserPermissions::EntriesCanUpdate) ||
                (!$this->isEntryOwner($entry) && !$this->isEntryModerator())) {
                $this->errorNoPermission();
            }

            $entry_id = $entry->entry_id;
        } else {
            // Files of an entry being created: whoever may create entries.
            if (!$this->context->current_user->hasPermission(UserPermissions::EntriesCanPost)) {
                $this->errorNoPermission();
            }

            $entry = new EntryEntity(post_hash: $post_hash, user_id: $this->context->current_user->uid);
        }

        if ($mybb->request_method === 'post') {
            require_once MYBB_ROOT . 'inc/functions_upload.php';

            $upload_attachment_result = $this->context->attachments_manager->uploadAttachment(
                $_FILES[$field_key],
                $entry,
                field_id: $field->field_id,
                field_definition: $field->field_id ? $this->context->collection_fields[$field->field_id] : [],
                do_watermark: $mybb->get_input('attachment_watermark_file', MyBB::INPUT_BOOL),
            );

            if (isset($upload_attachment_result['error'])) {
                $this->error($upload_attachment_result['error']);
            } else {
                $this->success([
                    'id' => $upload_attachment_result['attachment_id'],
                    'path' => $upload_attachment_result['file_name'],
                    'url' => $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                            Routes::AttachmentDownload,
                            [
                                'attachment_public_id' => $upload_attachment_result['public_id'],
                                'attachment_name' => $upload_attachment_result['attachment_name'],
                            ],
                        ),
                    'filename' => basename($_FILES[$field_key]['name']),
                    'size' => $upload_attachment_result['file_size'],
                    'type' => $upload_attachment_result['mime_type'],
                    'status' => 'success', // error, awaiting
                    'message' => 'File uploaded successfully',
                    'key' => $upload_attachment_result['attachment_id'],
                    'metadata' => [
                        'width' => empty($upload_attachment_result['dimensions']) ? '' : explode(
                            'x',
                            $upload_attachment_result['dimensions']
                        )[0],
                        'height' => empty($upload_attachment_result['dimensions']) ? '' : explode(
                            'x',
                            $upload_attachment_result['dimensions']
                        )[1],
                        'author' => $mybb->user['username'] ?? '',
                    ],
                ]);
            }
        }
    }

    #[NoReturn]
    public function getAttachments(
        ?string $post_hash = null,
        ?string $entry_public_id = null,
        ?string $entry_slug_custom = null,
    ): void {
        $this->verifyPassword();

        global $db;

        $where_clauses = ["showcase_id='{$this->context->showcase->showcase_id}'"];

        if ($post_hash === null) {
            $entry_id = (int)($this->context->entries_repository->queryEntry(
                ["public_id='{$db->escape_string($entry_public_id ?? '')}'"],
                ['entry_id'],
            )['entry_id'] ?? 0);

            $where_clauses[] = "entry_id='{$entry_id}'";
        } else {
            $where_clauses[] = "post_hash='{$db->escape_string($post_hash)}'";
        }

        global $mybb;

        $field_key = $mybb->get_input('field_key');

        if ($field_key) {
            $field_id = array_filter(
                $this->context->collection_fields,
                fn($field_data) => $field_data['field_key'] === $field_key
            )[0]['field_id'] ?? 0;

            if (empty($field_id)) {
                $this->errorNoPermission();
            }

            $field_definition = $this->context->collection_fields[$field_id];

            if ($field_definition['html_type'] !== FieldHtmlTypes::File ||
                !is_member($field_definition['allowed_groups_fill'])) {
                $this->errorNoPermission();
            }
        }

        $attachmentObjects = $this->context->attachments_repository->queryAttachments(
            $where_clauses,
            [
                'attachment_name',
                'mime_type',
                'file_size',
                'public_id',
            ]
        );

        foreach ($attachmentObjects as $attachment_id => $attachment_data) {
            $attachmentObjects[$attachment_id]['url'] = $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::AttachmentDownload,
                    [
                        'attachment_public_id' => $attachment_data['public_id'],
                        'attachment_name' => $attachment_data['attachment_name'],
                    ],
                );
        }

        $this->success(array_values($attachmentObjects));
    }

    #[Route('/attachment/{attachment_public_id}/{attachment_name}', name: 'attachment_view', methods: ['GET'])]
    #[NoReturn]
    public function downloadAttachment(
        string $attachment_public_id,
        string $attachment_name,
    ): void {
        $this->is_thumbnail = false;

        $this->viewThumbnail($attachment_public_id, $attachment_name);
    }

    #[Route('/thumbnail/{attachment_public_id}/{attachment_name}', name: 'thumbnail_view', methods: ['GET'])]
    #[NoReturn]
    public function viewThumbnail(
        string $attachment_public_id,
        string $attachment_name,
    ): void {
        $this->verifyPassword(true);

        global $mybb, $db;
        global $lang;

        $lang->load('messages');

        $this->attachment_data = $this->context->attachments_repository->queryAttachment(
            [
                "showcase_id='{$this->context->showcase->showcase_id}'",
                "public_id='{$db->escape_string($attachment_public_id)}'",
                "attachment_name='{$db->escape_string($attachment_name)}'",
            ],
            array_keys(TABLES_DATA['myshowcase_attachments']),
        );

        // An unknown id gets the same answer as an attachment the user may not see, so the two cannot be told apart.
        if (empty($this->attachment_data['attachment_id']) || empty($this->attachment_data['attachment_name'])) {
            $this->error($lang->error_invalidattachment, 404);
        }

        // Files uploaded with a form that was not saved yet have no entry.
        $entry = null;

        if ((int)$this->attachment_data['entry_id']) {
            $entry_data = $this->context->entries_repository->queryEntry(
                ["entry_id='" . (int)$this->attachment_data['entry_id'] . "'"],
                ['entry_id', 'user_id', 'post_hash', 'status']
            );

            // The file of an entry that no longer exists is not served to anyone.
            if (empty($entry_data['entry_id'])) {
                $this->error($lang->error_invalidattachment, 404);
            }

            $this->context->entries_manager->addEntry($entry_data);

            $entry = $this->context->entries_manager->getEntryByID((int)$entry_data['entry_id']);
        }

        hooksRun('controller_attachments_download_start', $this);

        // The file and its thumbnail answer the same: the uploader always, anyone else with the permissions to see
        // the entry, the file and to download it.
        $user_id = (int)$this->context->current_user->uid;

        $is_owner = $user_id > 0 && $user_id === (int)$this->attachment_data['user_id'];

        if (!$this->context->current_user->hasPermission(UserPermissions::MainCanView)) {
            $this->errorNoPermission(403);
        }

        // A file of an entry the user may not see is not served, whoever uploaded it. A file not attached to an entry
        // yet (its form was not saved) is served to its uploader only.
        if ($entry !== null ? !$this->isEntryVisible($entry) : !$is_owner) {
            $this->error($lang->error_invalidattachment, 404);
        }

        if (!$is_owner) {
            if (!$this->context->current_user->hasPermission(UserPermissions::EntriesCanView) ||
                !$this->context->current_user->hasPermission(UserPermissions::AttachmentsCanView)) {
                $this->errorNoPermission(403);
            }

            if ((int)$this->attachment_data['status'] !== ContentStatuses::VisibleApproved &&
                !$this->context->current_user->hasPermission(UserPermissions::AttachmentsCanViewUnapproved)) {
                $this->error($lang->error_invalidattachment, 404);
            }

            // A thumbnail is a preview: only the file itself needs the download permission. This is checked against
            // what was requested, before a missing thumbnail falls back to the image below.
            if (!$this->is_thumbnail &&
                !$this->context->current_user->hasPermission(UserPermissions::AttachmentsCanDownload)) {
                $this->errorNoPermission(403);
            }
        }

        $attachment_file_extension = my_strtolower(get_extension($this->attachment_data['attachment_name']));

        $attachment_mime_type = my_strtolower($this->attachment_data['mime_type']);

        $attachment_types = $this->context->cache_manager
            ->get(CacheDataTypes::AttachmentTypes)[$this->context->showcase->showcase_id] ?? [];

        foreach ($attachment_types as $attachment_type_id => $attachment_type_data) {
            if (!($attachment_type_data['file_extension'] === $attachment_file_extension &&
                $attachment_type_data['mime_type'] === $attachment_mime_type)) {
                unset($attachment_type_data);
            } else {
                break;
            }
        }

        if (empty($attachment_type_data)) {
            $this->error($lang->error_invalidattachment, 404);
        }

        if (!$is_owner && !is_member($attachment_type_data['allowed_groups'])) {
            $this->errorNoPermission(403);
        }

        $absolute_uploads_path = mk_path_abs($this->context->showcase->attachments_uploads_path);

        // An image without a thumbnail (WebP, smaller than the thumbnail box) shows its file instead; other files show
        // the icon of their type.
        if ($this->is_thumbnail && ((string)$this->attachment_data['thumbnail_name'] === '' ||
                !is_file($absolute_uploads_path . '/' . $this->attachment_data['thumbnail_name']))) {
            if (!str_starts_with($attachment_mime_type, 'image/')) {
                header('Location: ' . $mybb->settings['bburl'] . '/' . str_replace('{theme}', 'images', (string)$attachment_type_data['type_icon']));

                exit;
            }

            $this->is_thumbnail = false;

            $this->download_count_increase = 0;
        }

        // Only increment the download count if this is not a thumbnail
        if ($this->download_count_increase && !$this->is_thumbnail && !$is_owner) {
            $this->context->attachments_repository->updateAttachment(
                ['downloads' => $this->attachment_data['downloads'] + $this->download_count_increase],
                (int)$this->attachment_data['attachment_id']
            );
        }

        // basename isn't UTF-8 safe. This is a workaround.
        $attachment_file_name = ltrim(basename(' ' . $this->attachment_data['attachment_name']));

        hooksRun('controller_attachments_download_intermediate', $this);

        if ($this->is_thumbnail) {
            header("Content-disposition: filename=\"{$attachment_file_name}\"");

            header(
                'Content-type: ' . match ($attachment_file_extension) {
                    'gif' => 'image/gif',
                    'bmp' => 'image/bmp',
                    'png' => 'image/png',
                    'jpg', 'jpeg', 'jpe' => 'image/jpeg',
                    default => 'image/unknown',
                }
            );

            header(
                'Content-length: ' . filesize($absolute_uploads_path . '/' . $this->attachment_data['thumbnail_name'])
            );

            $handle = fopen($absolute_uploads_path . '/' . $this->attachment_data['thumbnail_name'], 'rb');

            while (!feof($handle)) {
                echo fread($handle, 8192);
            }

            fclose($handle);

            exit;
        } else {
            if (!file_exists($absolute_uploads_path . '/' . $this->attachment_data['file_name'])) {
                $this->error($lang->error_invalidattachment, 404);
            }

            $file_type = $attachment_mime_type;

            switch ($attachment_mime_type) {
                case 'application/pdf':
                case 'image/bmp':
                case 'image/gif':
                case 'image/jpeg':
                case 'image/pjpeg':
                case 'image/png':
                case 'image/webp':
                case 'text/plain':
                    if (!empty($attachment_type_data['force_download'])) {
                        $disposition = 'attachment';
                    } else {
                        $disposition = 'inline';
                    }

                    break;

                default:
                    if (!$file_type) {
                        $file_type = 'application/force-download';
                    }

                    $disposition = 'attachment';
            }

            require_once ROOT . '/System/ExceptionInvalidRangeHeader.php';
            require_once ROOT . '/System/ExceptionNonExistentFile.php';
            require_once ROOT . '/System/ExceptionUnreadableFile.php';
            require_once ROOT . '/System/ExceptionUnsatisfiableRange.php';
            require_once ROOT . '/System/PartialFileServlet.php';
            require_once ROOT . '/System/RangeHeader.php';

            try {
                (new PartialFileServlet(
                    RangeHeader::createFromHeaderString(
                        RangeHeader::getRequestHeader('Range')
                    )
                ))->sendFile(
                    $absolute_uploads_path . '/' . $this->attachment_data['file_name'],
                    $file_type,
                    (int)$this->attachment_data['file_size'],
                    $attachment_file_name,
                    $disposition
                );
            } catch (ExceptionInvalidRangeHeader $e) {
                header('HTTP/1.1 400 Bad Request');
            } catch (ExceptionUnsatisfiableRange $e) {
                header('HTTP/1.1 416 Range Not Satisfiable');
            } catch (ExceptionNonExistentFile $e) {
                header('HTTP/1.1 404 Not Found');
            } catch (ExceptionUnreadableFile $e) {
                header('HTTP/1.1 500 Internal Server Error');
            } finally {
                // A file shown in place of its missing thumbnail is not a download.
                if ($this->download_count_increase) {
                    attachmentLogInsert([
                        'user_id' => $this->context->current_user->uid,
                        'attachment_id' => $this->attachment_data['attachment_id'],
                        'ipaddress' => $mybb->session->packedip,
                        // A DATETIME column: MySQL in strict mode refuses a Unix timestamp.
                        'dateline' => new \DateTime('@' . TIME_NOW),
                    ]);
                }
            }

            exit;
        }
    }
}