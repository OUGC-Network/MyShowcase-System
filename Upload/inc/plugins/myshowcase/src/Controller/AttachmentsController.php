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
use MyShowcase\Entity\Entry;
use MyShowcase\Entity\User;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\Routing\Routing;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Service\Render;
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
    public array $attachment_data;
    public bool $is_thumbnail = true;
    public int $download_count_increase = 1;

    public function __construct(
        private readonly Routing $routing,
        private readonly User $current_user,
        private readonly EntriesRepository $entries_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly CommentsRepository $comments_repository,
        private readonly ModeratorsRepository $moderators_repository,
        private readonly EntriesManager $entries_manager,
        private readonly AttachmentsManager $attachments_manager,
        private readonly CommentsManager $comments_manager,
        private readonly Render $render_manager,
        private readonly FieldFactory $field_factory,
        private readonly array $config,
        private readonly array $fieldset_fields,
        private readonly array $filter,
        protected readonly array $attachment_types,
    ) {
        parent::__construct(
            $routing,
            $this->current_user,
            $this->entries_repository,
            $this->comments_repository,
            $this->entries_manager,
            $this->comments_manager,
            $this->render_manager,
            $this->field_factory,
            $this->config,
            $this->fieldset_fields,
            $this->filter,
        );
    }

    /**
     * @throws RandomException
     */
    #[Route('/upload/{post_hash}', name: 'entry_create_upload', methods: ['POST'])]
    #[NoReturn] public function uploadAttachment(
        ?string $post_hash = null,
        ?string $entry_slug = null,
    ): void {
        global $mybb, $lang;

        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            $this->error($lang->invalid_post_code);
        }

        if (!$this->config['attachments_allow_in_entries'] ||
            !$this->current_user->hasPermission(UserPermissions::AttachmentsCanUpload)) {
            $this->errorNoPermission();
        }

        $field_key = $mybb->get_input('field_key');

        $field_id = null;

        $field_id = array_filter(
            $this->fieldset_fields,
            fn($field_data) => $field_data['field_key'] === $field_key
        )[0]['field_id'] ?? 0;

        if (!$field_key || empty($field_id)) {
            $this->errorNoPermission();
        }

        if (empty($_FILES[$field_key])) {
            $this->error($lang->myshowcase_create_update_attachment_error_missing_file);
        }

        $field_definition = $this->fieldset_fields[$field_id];

        if ($field_definition['html_type'] !== FieldHtmlTypes::File ||
            !is_member($field_definition['allowed_groups_fill'])) {
            $this->errorNoPermission();
        }

        $entry_id = null;

        if ($post_hash === null) {
            $entry_data = $this->entries_repository->queryEntry(
                ["entry_slug='{$entry_slug}'"],
                ['entry_id', 'post_hash', 'status', 'user_id'],
            );

            if (!$entry_data) {
                $this->errorNoPermission();
            }

            $this->entries_manager->addEntry($entry_data);

            $entry_data = $this->entries_manager->getEntryByID($entry_data['entry_id']);

            $entry_id = $entry_data->entry_id;
        } else {
            $entry_data = new Entry(post_hash: $post_hash, user_id: $this->current_user->user_id);
        }

        if ($mybb->request_method === 'post') {
            require_once MYBB_ROOT . 'inc/functions_upload.php';

            $upload_attachment_result = $this->attachments_manager->uploadAttachment(
                $_FILES[$field_key],
                $entry_data,
                field_id: $field_id,
                field_definition: $field_id ? $this->fieldset_fields[$field_id] : [],
                do_watermark: $mybb->get_input('attachment_watermark_file', MyBB::INPUT_BOOL),
            );

            if (isset($upload_attachment_result['error'])) {
                $this->error($upload_attachment_result['error']);
            } else {
                $this->success([
                    'id' => $upload_attachment_result['attachment_id'],
                    'path' => $upload_attachment_result['file_name'],
                    'url' => $this->config['showcase_url'] . '/' . $this->routing->generateUrl(
                            Routes::AttachmentDownload,
                            [
                                'attachment_hash' => $upload_attachment_result['attachment_hash'],
                                'attachment_name' => $upload_attachment_result['attachment_name'],
                            ],
                        ),
                    'filename' => basename($_FILES[$field_key]['name']),
                    'size' => $upload_attachment_result['file_size'],
                    'type' => $upload_attachment_result['mime_type'],
                    'status' => 'success', // error, pending
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
    ["is_enabled"]=>
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
            $fileName = $_SERVER['HTTP_UPLOAD_FILENAME'] ?? 'unknown';
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

        $where_clauses = ["showcase_id='{$this->config['showcase_id']}'"];

        if ($post_hash === null) {
            $where_clauses[] = "entry_slug='{$db->escape_string($entry_slug ?? '')}'";
        } else {
            $where_clauses[] = "post_hash='{$db->escape_string($post_hash)}'";
        }

        global $mybb;

        $field_key = $mybb->get_input('field_key');

        if ($field_key) {
            $field_id = array_filter(
                $this->fieldset_fields,
                fn($field_data) => $field_data['field_key'] === $field_key
            )[0]['field_id'] ?? 0;

            if (empty($field_id)) {
                $this->errorNoPermission();
            }

            $field_definition = $this->fieldset_fields[$field_id];

            if ($field_definition['html_type'] !== FieldHtmlTypes::File ||
                !is_member($field_definition['allowed_groups_fill'])) {
                $this->errorNoPermission();
            }
            //$where_clauses[] = "field_id='{$field_id}'";
        }

        $attachmentObjects = $this->attachments_repository->queryAttachments(
            $where_clauses,
            [
                'attachment_name',
                'mime_type',
                'file_size',
                //'field_id',
                'attachment_hash',
            ]
        );

        foreach ($attachmentObjects as $attachment_id => $attachment_data) {
            $attachmentObjects[$attachment_id]['url'] = $this->config['showcase_url'] . '/' . $this->routing->generateUrl(
                    Routes::AttachmentDownload,
                    [
                        'attachment_hash' => $attachment_data['attachment_hash'],
                        'attachment_name' => $attachment_data['attachment_name'],
                    ],
                );
            //$attachmentObjects[$attachment_id]['url'] = 'https://static.wikia.nocookie.net/sonic/images/1/10/SRCSonic.png/revision/latest?cb=20251202132714';
        }

        $this->success(array_values($attachmentObjects));
    }

    #[Route('/attachment/{attachment_hash}/{attachment_name}', name: 'attachment_view', methods: ['GET'])]
    #[NoReturn] public function downloadAttachment(
        string $attachment_hash,
        string $attachment_name,
    ): void {
        $this->is_thumbnail = false;

        $this->viewThumbnail($attachment_hash, $attachment_name);
    }

    #[Route('/thumbnail/{attachment_hash}/{attachment_name}', name: 'thumbnail_view', methods: ['GET'])]
    #[NoReturn] public function viewThumbnail(
        string $attachment_hash,
        string $attachment_name,
    ): void {
        global $mybb, $db;
        global $lang;

        $lang->load('messages');

        $where_clauses = [
            "showcase_id='{$this->config['showcase_id']}'",
            "attachment_hash='{$db->escape_string($attachment_hash)}'",
            "attachment_name='{$db->escape_string($attachment_name)}'",
        ];

        if ($this->is_thumbnail) {
            $where_clauses[] = "thumbnail_name!=''";
        }

        $this->attachment_data = $this->attachments_repository->queryAttachment(
            $where_clauses,
            array_keys(TABLES_DATA['myshowcase_attachments']),
        );

        $entry_data = $this->entries_repository->queryEntry(
            ["entry_id='{$this->attachment_data['entry_id']}'"],
            ['entry_id', 'user_id', 'post_hash', 'status']
        );

        $this->entries_manager->addEntry($entry_data);

        $entry_data = $this->entries_manager->getEntryByID($entry_data['entry_id']);

        hooksRun('controller_attachments_download_start', $this);

        if ($this->attachment_data['field_id'] !== 0) {
            // todo, check field permissions
        }

        if (empty($this->attachment_data['attachment_name'])) {
            $this->error($lang->error_invalidattachment);
        }

        if (
            !$this->current_user->hasPermission(UserPermissions::MainCanView) ||
            !$this->current_user->hasPermission(UserPermissions::EntriesCanView) ||
            (
                $this->current_user->user_id !== (int)$this->attachment_data['user_id'] &&
                !$this->current_user->hasPermission(UserPermissions::AttachmentsCanView)
            )
        ) {
            $this->error($lang->error_invalidattachment);
        }

        $attachment_file_extension = my_strtolower(get_extension($this->attachment_data['attachment_name']));

        $attachment_mime_type = my_strtolower($this->attachment_data['mime_type']);

        foreach ($this->attachment_types as $attachment_type_id => $attachment_type_data) {
            if (!($attachment_type_data['file_extension'] === $attachment_file_extension &&
                $attachment_type_data['mime_type'] === $attachment_mime_type)) {
                unset($attachment_type_data);
            } else {
                break;
            }
        }

        if (empty($attachment_type_data)) {
            $this->error($lang->error_invalidattachment);
        }

        // Don't check the permissions on preview
        if ($this->current_user->user_id !== (int)$this->attachment_data['user_id']) {
            if (!$this->current_user->hasPermission(UserPermissions::AttachmentsCanDownload) && !$this->is_thumbnail) {
                $this->errorNoPermission();
            }

            // Error if attachment is invalid or not visible
            if (!$this->current_user->hasPermission(UserPermissions::CanManageAttachments) &&
                (
                    (int)$this->attachment_data['status'] !== $this->attachments_manager::STATUS_VISIBLE ||
                    $entry_data->status !== $this->entries_manager::STATUS_VISIBLE
                )
            ) {
                $this->error($lang->error_invalidattachment);
            }
        }

        // Only increment the download count if this is not a thumbnail
        if ($this->download_count_increase && !$this->is_thumbnail && $this->current_user->user_id !== (int)$this->attachment_data['user_id']) {
            if (!is_member($attachment_type_data['allowed_groups'])) {
                $this->errorNoPermission();
            }

            $this->attachments_repository->updateAttachment(
                ['downloads' => $this->attachment_data['downloads'] + $this->download_count_increase],
                (int)$this->attachment_data['attachment_id']
            );
        }

        // basename isn't UTF-8 safe. This is a workaround.
        $attachment_file_name = ltrim(basename(' ' . $this->attachment_data['attachment_name']));

        $absolute_uploads_path = mk_path_abs($this->config['attachments_uploads_path']);

        hooksRun('controller_attachments_download_intermediate', $this);

        if ($this->is_thumbnail) {
            if (!file_exists($absolute_uploads_path . '/' . $this->attachment_data['thumbnail_name'])) {
                $this->error($lang->error_invalidattachment);
            }

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
                $this->error($lang->error_invalidattachment);
            }

            $file_type = $attachment_mime_type;

            switch ($attachment_mime_type) {
                case 'application/pdf':
                case 'image/bmp':
                case 'image/gif':
                case 'image/jpeg':
                case 'image/pjpeg':
                case 'image/png':
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
                attachmentLogInsert([
                    'user_id' => $this->current_user->user_id,
                    'attachment_id' => $this->attachment_data['attachment_id'],
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