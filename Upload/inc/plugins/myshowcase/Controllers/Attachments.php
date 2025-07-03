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

use MyBB;
use JetBrains\PhpStorm\NoReturn;
use MyShowcase\System\UserPermissions;
use MyShowcase\System\ModeratorPermissions;
use MyShowcase\System\ExceptionInvalidRangeHeader;
use MyShowcase\System\ExceptionNonExistentFile;
use MyShowcase\System\ExceptionUnreadableFile;
use MyShowcase\System\ExceptionUnsatisfiableRange;
use MyShowcase\System\PartialFileServlet;
use MyShowcase\System\RangeHeader;

use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\attachmentLogInsert;
use function MyShowcase\Plugin\Functions\attachmentUpdate;
use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\hooksRun;

use const MyShowcase\ROOT;
use const MyShowcase\Plugin\Core\TABLES_DATA;
use const MyShowcase\Plugin\Core\ATTACHMENT_STATUS_VISIBLE;
use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_VISIBLE;

class Attachments extends Base
{
    #[NoReturn] public function downloadAttachment(
        string $entrySlug,
        string $entrySlugCustom,
        string $attachmentHash
    ): void {
        $this->viewThumbnail($entrySlug, $entrySlugCustom, $attachmentHash, false);
    }

    #[NoReturn] public function viewThumbnail(
        string $entrySlug,
        string $entrySlugCustom,
        string $attachmentHash,
        bool $isThumbnail = true,
    ): void {
        global $mybb, $db;
        global $lang;

        $lang->load('messages');

        $currentUserID = (int)$mybb->user['uid'];

        $entryData = $this->showcaseObject->dataGet(
            ["entry_slug='{$db->escape_string($entrySlug)}'"],
            ['user_id', 'entry_hash', 'status'],
            queryOptions: ['limit' => 1]
        );

        $this->showcaseObject->entryDataSet($entryData);

        $tableFields = TABLES_DATA['myshowcase_attachments'];

        $whereClauses = [
            "showcase_id='{$this->showcaseObject->showcase_id}'",
            "entry_id='{$this->showcaseObject->entryID}'",
            "attachment_hash='{$db->escape_string($attachmentHash)}'",
        ];

        if ($isThumbnail) {
            $whereClauses[] = "thumbnail_name!=''";
        }

        $incrementDownloadCount = 1;

        $hookArguments = [
            'attachmentsController' => &$this,
            'attachmentData' => &$attachmentData,
            'isThumbnail' => $isThumbnail,
            'tableFields' => &$tableFields,
            'whereClauses' => &$whereClauses,
            'incrementDownloadCount' => &$incrementDownloadCount,
        ];

        $hookArguments = hooksRun('controller_attachments_download_start', $hookArguments);

        $attachmentData = attachmentGet(
            $whereClauses,
            array_keys($tableFields),
            queryOptions: ['limit' => 1]
        );

        if (empty($attachmentData['attachment_name'])) {
            $this->error($lang->error_invalidattachment);
        }

        $hookArguments['attachmentData'] = &$attachmentData;

        $attachmentID = (int)$attachmentData['attachment_id'];

        $attachmentUserID = (int)$attachmentData['user_id'];

        if (
            !$this->showcaseObject->userPermissions[UserPermissions::CanView] ||
            !$this->showcaseObject->userPermissions[UserPermissions::CanViewEntries] ||
            (
                $currentUserID !== $attachmentUserID &&
                !$this->showcaseObject->userPermissions[UserPermissions::CanViewAttachments]
            )
        ) {
            $this->error($lang->error_invalidattachment);
        }

        $attachmentTypes = cacheGet(CACHE_TYPE_ATTACHMENT_TYPES)[$this->showcaseObject->showcase_id] ?? [];

        $attachmentFileExtension = my_strtolower(get_extension($attachmentData['attachment_name']));

        $attachmentMimeType = my_strtolower($attachmentData['mime_type']);

        foreach ($attachmentTypes as $attachmentTypeID => $attachmentTypeData) {
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
        if ($currentUserID !== $attachmentUserID) {
            if (!$this->showcaseObject->userPermissions[UserPermissions::CanDownloadAttachments] && !$isThumbnail) {
                $this->errorNoPermission();
            }

            // Error if attachment is invalid or not visible
            if (!$this->showcaseObject->userPermissions[ModeratorPermissions::CanManageAttachments] &&
                (
                    $attachmentStatus !== ATTACHMENT_STATUS_VISIBLE ||
                    (int)$this->showcaseObject->entryData['status'] !== ENTRY_STATUS_VISIBLE
                )
            ) {
                $this->error($lang->error_invalidattachment);
            }
        }

        // Only increment the download count if this is not a thumbnail
        if ($incrementDownloadCount && !$isThumbnail && $currentUserID !== $attachmentUserID) {
            if (!is_member($attachmentTypeData['allowed_groups'])) {
                $this->errorNoPermission();
            }

            attachmentUpdate(['downloads' => $attachmentData['downloads'] + $incrementDownloadCount], $attachmentID);
        }

        // basename isn't UTF-8 safe. This is a workaround.
        $attachmentName = ltrim(basename(' ' . $attachmentData['attachment_name']));

        $absoluteUploadsPath = mk_path_abs($this->showcaseObject->config['attachments_uploads_path']);

        $hookArguments = hooksRun('controller_attachments_download_intermediate', $hookArguments);

        if ($isThumbnail) {
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

            ini_set('display_errors', 0);

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
                    'user_id' => $currentUserID,
                    'attachment_id' => $attachmentID,
                    'ipaddress' => $mybb->session->packedip,
                    'dateline' => TIME_NOW,
                ]);
            }

            exit;
        }
    }

    #[NoReturn] public function error(string $error = ''): void
    {
        global $mybb, $lang;

        $error ??= $lang->unknown_error;

        if ($mybb->get_input('ajax', MyBB::INPUT_INT)) {
            header("Content-type: application/json; charset={$lang->settings['charset']}");

            echo json_encode(['errors' => [$error]]);

            exit;
        }

        echo $error;

        exit;
    }

    #[NoReturn] public function errorNoPermission(): void
    {
        global $mybb, $lang;

        if ($mybb->get_input('ajax', MyBB::INPUT_INT)) {
            header("Content-type: application/json; charset={$lang->settings['charset']}");

            echo json_encode(['errors' => [$lang->error_nopermission_user_ajax]]);

            exit;
        }

        $this->error($lang->error_nopermission_guest_1);
    }
}