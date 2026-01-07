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

namespace MyShowcase\Controllers;

use JetBrains\PhpStorm\NoReturn;
use MyBB;
use MyShowcase\Entity\Entry;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Enum\Routes;
use MyShowcase\System\ExceptionInvalidRangeHeader;
use MyShowcase\System\ExceptionNonExistentFile;
use MyShowcase\System\ExceptionUnreadableFile;
use MyShowcase\System\ExceptionUnsatisfiableRange;
use MyShowcase\System\PartialFileServlet;
use MyShowcase\System\RangeHeader;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\attachmentLogInsert;
use function MyShowcase\Plugin\Functions\hooksRun;

use const MyShowcase\Plugin\Core\TABLES_DATA;
use const MyShowcase\ROOT;

class Attachments extends Base
{
    /**
     * @throws RandomException
     */
    #[NoReturn] public function uploadAttachment(
        ?string $post_hash = null,
        ?string $entry_slug = null,
    ): void {
        global $mybb, $lang;

        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            $this->error($lang->invalid_post_code);
        }

        if (!$this->showcase->config['attachments_allow_entries'] ||
            !$this->showcase->user_permissions[UserPermissions::CanUploadAttachments]) {
            $this->errorNoPermission();
        }

        $field_key = $mybb->get_input('field_key');

        $field_id = null;

        $field_id = $this->showcase->fieldSetFieldsIDs[$field_key] ?? 0;

        if (!$field_key || empty($field_id)) {
            $this->errorNoPermission();
        }

        if (empty($_FILES[$field_key])) {
            $this->error($lang->myshowcase_create_update_attachment_error_missing_file);
        }

        $field_definition = $this->showcase->fields_cache[$field_id];

        if ($field_definition['html_type'] !== FieldHtmlTypes::File ||
            !is_member($field_definition['allowed_groups_fill'])) {
            $this->errorNoPermission();
        }

        $entry_id = null;

        if ($post_hash === null) {
            $entry_data = $this->showcase->entries_repository->queryEntry(
                ["entry_slug='{$entry_slug}'"],
                ['entry_id', 'post_hash', 'status', 'user_id'],
            );

            if (!$entry_data) {
                $this->errorNoPermission();
            }

            $this->showcase->addEntry($entry_data);

            $entry_data = $this->showcase->getEntryByID($entry_data['entry_id']);

            $entry_id = $entry_data->entry_id;
        } else {
            $entry_data = new Entry(user_id: $this->showcase->current_user_id);

            $entry_data->setPostHash($post_hash);
        }

        if ($mybb->request_method === 'post') {
            require_once MYBB_ROOT . 'inc/functions_upload.php';

            $fileObject = $this->showcase->attachments_manager->uploadAttachment(
                $_FILES[$field_key],
                $entry_data,
                field_id: $field_id,
                field_definition: $field_id ? $this->showcase->fields_cache[$field_id] : [],
                do_watermark: $mybb->get_input('attachment_watermark_file', MyBB::INPUT_BOOL),
            );

            if (isset($fileObject['error'])) {
                $this->error($fileObject['error']);
            } else {
                $this->success([
                    'id' => $fileObject['attachment_id'],
                    'path' => $fileObject['file_name'],
                    'url' => isset($entry_data) ? $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                            Routes::AttachmentView,
                            [
                                'entry_slug' => $entry_slug,
                                'attachment_hash' => $fileObject['attachment_hash'],
                                'post_hash' => $entry_data->post_hash,
                            ],
                        ) : $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                            Routes::AttachmentViewFromPostHash,
                            [
                                'post_hash' => $entry_data->post_hash,
                                'attachment_hash' => $fileObject['attachment_hash'],
                            ],
                        ),
                    'filename' => basename($_FILES[$field_key]['name']),
                    'size' => $fileObject['file_size'],
                    'type' => $fileObject['mime_type'],
                    'status' => 'success', // error, pending
                    'message' => 'File uploaded successfully',
                    'key' => $fileObject['attachment_id'],
                    'metadata' => [
                        'width' => empty($fileObject['dimensions']) ? '' : explode('x', $fileObject['dimensions'])[0],
                        'height' => empty($fileObject['dimensions']) ? '' : explode('x', $fileObject['dimensions'])[1],
                        'author' => $mybb->user['username'] ?? '',
                    ],
                ]);
            }
        }

        /*
    ["field_type"]=>
    string(7) "varchar"
    ["file_capture"]=>
    int(0)
    ["allow_multiple_values"]=>
    int(0)
    ["default_value"]=>
    string(0) ""
    ["default_type"]=>
    int(0)
    ["filter_on_save"]=>
    int(0)
    ["enabled"]=>
    int(1)
    ["is_required"]=>
    int(0)
    ["read_only"]=>
    int(0)
  }
}




    ["gallery_files"]=>
    array(6) {
      ["name"]=>
      string(12) "Sonic005.png"
      ["full_path"]=>
      string(12) "Sonic005.png"
      ["type"]=>
      string(9) "image/png"
      ["tmp_name"]=>
      string(27) "/tmp/phpkas1nfmrpbov0hTFgbX"
      ["error"]=>
      int(0)
      ["size"]=>
      int(20407)
    }
         */

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Handle chunked uploads (FilePond sends chunks as separate requests)
            $chunkIndex = isset($_SERVER['HTTP_UPLOAD_OFFSET']) ? (int)$_SERVER['HTTP_UPLOAD_OFFSET'] : 0;
            $fileName = isset($_SERVER['HTTP_UPLOAD_FILENAME']) ? $_SERVER['HTTP_UPLOAD_FILENAME'] : 'unknown';
            $tempFileName = $uploadDir . 'temp_' . $fileName;

            // Append chunk to temporary file
            file_put_contents($tempFileName, file_get_contents('php://input'), FILE_APPEND);

            // If this is the last chunk, rename the file
            if (isset($_SERVER['HTTP_UPLOAD_LENGTH']) && $chunkIndex + strlen(
                    file_get_contents('php://input')
                ) >= (int)$_SERVER['HTTP_UPLOAD_LENGTH']) {
                $finalFileName = $uploadDir . $fileName;
                rename($tempFileName, $finalFileName);
                echo json_encode(['fileId' => uniqid()]);
            } else {
                echo json_encode(['offset' => $chunkIndex + strlen(file_get_contents('php://input'))]);
            }
            exit;
        }

        // Handle file deletion
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['delete'])) {
            $filePath = __DIR__ . '/uploads/' . $_GET['delete'];

            if (file_exists($filePath)) {
                if (unlink($filePath)) {
                    $this->success([
                        'success' => true,
                    ]);
                } else {
                    $this->error('Unable to delete file.');
                }
            } else {
                $this->error('File not found.', 404);
            }
        }

        // Handle file restore (resume)
        if ($mybb->request_method === 'get' && isset($_GET['restore'])) {
            $filePath = __DIR__ . '/uploads/' . $_GET['restore'];

            if (file_exists($filePath)) {
                header('Content-Type: application/octet-stream');

                readfile($filePath);

                exit;
            } else {
                $this->error('File not found.', 404);
            }
        }
    }

    #[NoReturn] public function getAttachments(
        ?string $post_hash = null,
        ?string $entry_slug = null,
        ?string $entry_slug_custom = null,
    ): void {
        global $db;

        $where_clauses = ["showcase_id='{$this->showcase->showcase_id}'"];

        if ($post_hash === null) {
            $where_clauses[] = "entry_slug='{$db->escape_string($entry_slug ?? '')}'";
        } else {
            $where_clauses[] = "post_hash='{$db->escape_string($post_hash)}'";
        }

        global $mybb;

        $field_key = $mybb->get_input('field_key');

        if ($field_key) {
            $field_id = $this->showcase->fieldSetFieldsIDs[$field_key] ?? 0;

            if (empty($field_id)) {
                $this->errorNoPermission();
            }

            $field_definition = $this->showcase->fields_cache[$field_id];

            if ($field_definition['html_type'] !== FieldHtmlTypes::File ||
                !is_member($field_definition['allowed_groups_fill'])) {
                $this->errorNoPermission();
            }
            //$where_clauses[] = "field_id='{$field_id}'";
        }

        $attachmentObjects = $this->showcase->attachments_repository->queryAttachments(
            $where_clauses,
            [
                'attachment_name',
                'mime_type',
                'file_size',
                //'field_id',
                'attachment_hash',
            ]
        );

        foreach ($attachmentObjects as $attachmentID => $attachmentData) {
            $attachmentObjects[$attachmentID]['url'] = $post_hash === null ? $this->routing->generateUrl(
                Routes::AttachmentViewFromPostHash,
                [
                    'post_hash' => $post_hash,
                    'attachment_hash' => $attachmentData['attachment_hash']
                ],
            ) : $this->routing->generateUrl(
                Routes::AttachmentViewFromPostHash,
                [
                    'post_hash' => $post_hash,
                    'attachment_hash' => $attachmentData['attachment_hash']
                ],
            );
            //$attachmentObjects[$attachmentID]['url'] = 'https://static.wikia.nocookie.net/sonic/images/1/10/SRCSonic.png/revision/latest?cb=20251202132714';
        }

        $this->success(array_values($attachmentObjects));
    }

    #[NoReturn] public function downloadAttachment(
        string $attachment_hash,
        ?string $post_hash = null,
        ?string $entry_slug = null,
    ): void {
        $this->viewThumbnail($attachment_hash, $post_hash, $entry_slug, false);
    }

    #[NoReturn] public function viewThumbnail(
        string $attachment_hash,
        ?string $post_hash = null,
        ?string $entry_slug = null,
        bool $is_thumbnail = true,
    ): void {
        global $mybb, $db;
        global $lang;

        $lang->load('messages');

        if ($entry_slug !== null) {
            $whereClauses = ["entry_slug='{$db->escape_string($entry_slug)}'"];
        } else {
            $whereClauses = ["post_hash='{$db->escape_string($post_hash)}'"];
        }

        $entry_data = $this->showcase->entries_repository->queryEntry(
            $whereClauses,
            ['entry_id', 'user_id', 'post_hash', 'status']
        );

        $this->showcase->addEntry($entry_data);

        $entry_data = $this->showcase->getEntryByID($entry_data['entry_id']);

        $tablesData = TABLES_DATA['myshowcase_attachments'];

        $whereClauses = [
            "showcase_id='{$this->showcase->showcase_id}'",
            "entry_id='{$entry_data->entry_id}'",
            "attachment_hash='{$db->escape_string($attachment_hash)}'",
        ];

        if ($is_thumbnail) {
            $whereClauses[] = "thumbnail_name!=''";
        }

        $incrementDownloadCount = 1;

        $hookArguments = [
            'attachmentsController' => &$this,
            'attachmentData' => &$attachmentData,
            'isThumbnail' => $is_thumbnail,
            'tableData' => &$tablesData,
            'whereClauses' => &$whereClauses,
            'incrementDownloadCount' => &$incrementDownloadCount,
        ];

        $hookArguments = hooksRun('controller_attachments_download_start', $hookArguments);

        $attachmentData = $this->showcase->attachments_repository->queryAttachment(
            $whereClauses,
            array_keys($tablesData),
        );

        if ($attachmentData['field_id'] !== 0) {
            // todo, check field permissions
        }

        if (empty($attachmentData['attachment_name'])) {
            $this->error($lang->error_invalidattachment);
        }

        $hookArguments['attachmentData'] = &$attachmentData;

        $attachmentID = (int)$attachmentData['attachment_id'];

        $attachmentUserID = (int)$attachmentData['user_id'];

        if (
            !$this->showcase->user_permissions[UserPermissions::CanView] ||
            !$this->showcase->user_permissions[UserPermissions::CanViewEntries] ||
            (
                $this->showcase->current_user_id !== $attachmentUserID &&
                !$this->showcase->user_permissions[UserPermissions::CanViewAttachments]
            )
        ) {
            $this->error($lang->error_invalidattachment);
        }

        $attachmentFileExtension = my_strtolower(get_extension($attachmentData['attachment_name']));

        $attachmentMimeType = my_strtolower($attachmentData['mime_type']);

        foreach ($this->showcase->attachment_types as $attachmentTypeID => $attachmentTypeData) {
            if (!($attachmentTypeData['file_extension'] === $attachmentFileExtension &&
                $attachmentTypeData['mime_type'] === $attachmentMimeType)) {
                unset($attachmentTypeData);
            } else {
                break;
            }
        }

        if (empty($attachmentTypeData)) {
            $this->error($lang->error_invalidattachment);
        }

        $hookArguments['attachmentTypeData'] = &$attachmentTypeData;

        $attachmentStatus = (int)$attachmentData['status'];

        // Don't check the permissions on preview
        if ($this->showcase->current_user_id !== $attachmentUserID) {
            if (!$this->showcase->user_permissions[UserPermissions::CanDownloadAttachments] && !$is_thumbnail) {
                $this->errorNoPermission();
            }

            // Error if attachment is invalid or not visible
            if (!$this->showcase->user_permissions[ModeratorPermissions::CanManageAttachments] &&
                (
                    $attachmentStatus !== $this->showcase->attachments_manager::STATUS_VISIBLE ||
                    $entry_data->status !== $this->showcase->entries_manager::STATUS_VISIBLE
                )
            ) {
                $this->error($lang->error_invalidattachment);
            }
        }

        // Only increment the download count if this is not a thumbnail
        if ($incrementDownloadCount && !$is_thumbnail && $this->showcase->current_user_id !== $attachmentUserID) {
            if (!is_member($attachmentTypeData['allowed_groups'])) {
                $this->errorNoPermission();
            }

            $this->showcase->attachments_repository->updateAttachment(
                ['downloads' => $attachmentData['downloads'] + $incrementDownloadCount],
                $attachmentID
            );
        }

        // basename isn't UTF-8 safe. This is a workaround.
        $attachmentName = ltrim(basename(' ' . $attachmentData['attachment_name']));

        $absoluteUploadsPath = mk_path_abs($this->showcase->config['attachments_uploads_path']);

        $hookArguments = hooksRun('controller_attachments_download_intermediate', $hookArguments);

        if ($is_thumbnail) {
            if (!file_exists($absoluteUploadsPath . '/' . $attachmentData['thumbnail_name'])) {
                $this->error($lang->error_invalidattachment);
            }

            $imageType = match ($attachmentFileExtension) {
                'gif' => 'image/gif',
                'bmp' => 'image/bmp',
                'png' => 'image/png',
                'jpg', 'jpeg', 'jpe' => 'image/jpeg',
                default => 'image/unknown',
            };

            header("Content-disposition: filename=\"{$attachmentName}\"");

            header('Content-type: ' . $imageType);

            header('Content-length: ' . filesize($absoluteUploadsPath . '/' . $attachmentData['thumbnail_name']));

            $handle = fopen($absoluteUploadsPath . '/' . $attachmentData['thumbnail_name'], 'rb');

            while (!feof($handle)) {
                echo fread($handle, 8192);
            }

            fclose($handle);

            exit;
        } else {
            if (!file_exists($absoluteUploadsPath . '/' . $attachmentData['file_name'])) {
                $this->error($lang->error_invalidattachment);
            }

            $filetype = $attachmentMimeType;

            switch ($attachmentMimeType) {
                case 'application/pdf':
                case 'image/bmp':
                case 'image/gif':
                case 'image/jpeg':
                case 'image/pjpeg':
                case 'image/png':
                case 'text/plain':
                    if (!empty($attachmentTypeData['force_download'])) {
                        $disposition = 'attachment';
                    } else {
                        $disposition = 'inline';
                    }

                    break;

                default:
                    if (!$filetype) {
                        $filetype = 'application/force-download';
                    }

                    $disposition = 'attachment';
            }

            //ini_set('display_errors', 0);

            require_once ROOT . '/System/ExceptionInvalidRangeHeader.php';
            require_once ROOT . '/System/ExceptionNonExistentFile.php';
            require_once ROOT . '/System/ExceptionUnreadableFile.php';
            require_once ROOT . '/System/ExceptionUnsatisfiableRange.php';
            require_once ROOT . '/System/PartialFileServlet.php';
            require_once ROOT . '/System/RangeHeader.php';

            try {
                $rangeHeader = RangeHeader::createFromHeaderString(
                    RangeHeader::getRequestHeader('Range')
                );

                (new PartialFileServlet($rangeHeader))->sendFile(
                    $absoluteUploadsPath . '/' . $attachmentData['file_name'],
                    $filetype,
                    (int)$attachmentData['file_size'],
                    $attachmentName,
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
                attachmentLogInsert([
                    'user_id' => $this->showcase->current_user_id,
                    'attachment_id' => $attachmentID,
                    'ipaddress' => $mybb->session->packedip,
                    'dateline' => TIME_NOW,
                ]);
            }

            exit;
        }
    }

    #[NoReturn] public function success(array $params = [], int $status_code = 200): void
    {
        global $lang;

        header("Content-type: application/json; charset={$lang->settings['charset']}");

        http_response_code($status_code);

        echo json_encode($params);

        exit;
    }

    #[NoReturn] public function error(string $error = '', int $status_code = 500): void
    {
        global $lang;

        header("Content-type: application/json; charset={$lang->settings['charset']}");

        http_response_code($status_code);

        echo json_encode(['error' => empty($error) ? $lang->unknown_error : $error]);

        exit;
    }

    #[NoReturn] public function errorNoPermission(int $status_code = 401): void
    {
        global $lang;

        $this->error($lang->error_nopermission_guest_1, $status_code);
    }
}