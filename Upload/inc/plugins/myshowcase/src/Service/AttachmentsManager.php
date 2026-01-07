<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Entity\Attachment;
use MyShowcase\Entity\Entry;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Event\Listener\EntryDeletedAttachmentListener;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class AttachmentsManager
{
    public const STATUS_PENDING = 0;

    public const STATUS_VISIBLE = 1;

    public const STATUS_DELETED = 2;

    public const ATTACHMENT_THUMBNAIL_SMALL = 1;

    public const WATERMARK_LOCATION_LOWER_LEFT = 1;

    public const WATERMARK_LOCATION_LOWER_RIGHT = 2;

    public const WATERMARK_LOCATION_CENTER = 3;

    public const WATERMARK_LOCATION_UPPER_LEFT = 4;

    public const WATERMARK_LOCATION_UPPER_RIGHT = 5;

    public const UPLOAD_ERROR_INI_SIZE = 1;

    public const UPLOAD_ERROR_FORM_SIZE = 2;

    public const UPLOAD_ERROR_PARTIAL = 3;

    public const UPLOAD_ERROR_NO_FILE = 4;

    public const UPLOAD_ERROR_NO_TMP_DIR = 6;

    public const UPLOAD_ERROR_CANT_WRITE = 7;

    public const UPLOAD_ERROR_INVALID = 8;

    public const UPLOAD_ERROR_FAILED = 9;

    public const ERROR_THUMBNAIL_FAILED = 3;

    public const ERROR_THUMBNAIL_TOO_SMALL = 4;

    private const  FIRST_PAGE = 1;

    private int $total_attachments = 0;

    private array $attachments = [];

    public function __construct(
        private readonly int $showcase_id,
        private readonly array $user_permissions,
        private readonly array $attachment_types,
        private readonly EntriesRepository $entries_repository,
        private readonly CommentsRepository $comments_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly EventDispatcher $event_dispatcher,
        private readonly string $attachments_uploads_path,
        private readonly bool $moderate_attachments_upload,
        private readonly bool $moderate_attachments_update,
        private readonly int $attachments_thumbnails_width,
        private readonly int $attachments_thumbnails_height,
        private readonly string $attachments_watermark_file,
        private readonly int $attachments_watermark_location,
    ) {
        $this->event_dispatcher->addListener(
            EntryDeletedEvent::NAME,
            new EntryDeletedAttachmentListener($attachments_repository)
        );
    }

    public function setAttachment(array $attachment_data): void
    {
        $this->attachments[$attachment_data['attachment_id']] = new Attachment(...$attachment_data);
    }

    public function getTotalAttachments(): int
    {
        return $this->total_attachments;
    }

    /**
     * @return iterable<Attachment>
     */
    public function getAttachments(): iterable
    {
        foreach ($this->attachments as $attachment_id => $attachment_data) {
            if (empty($attachment_data->parent_attachment_id)) {
                yield $attachment_data;
            }
        }
    }

    public function getAttachmentsEntryIDs(): array
    {
        $entry_ids = [];

        foreach ($this->attachments as $attachment_id => $attachment_data) {
            if (!empty($attachment_data->entry_id)) {
                $entry_ids[$attachment_data->entry_id] = $attachment_data->entry_id;
            }
        }

        return $entry_ids;
    }

    public function getAttachmentsByEntryID(int $entry_id): array
    {
        $attachment_objects = [];

        foreach ($this->attachments as $attachment_id => $attachment_data) {
            if ($attachment_data->entry_id === $entry_id) {
                $attachment_objects[$attachment_data->entry_id] = $attachment_data;
            }
        }

        return $attachment_objects;
    }

    public function getAttachmentByEntryID(int $entry_id): Attachment|bool
    {
        foreach ($this->attachments as $attachment_id => $attachment_data) {
            if ($attachment_data->entry_id === $entry_id) {
                return $attachment_data;
            }
        }

        return false;
    }

    public function getAttachmentByHash(string $attachment_hash): Attachment|bool
    {
        foreach ($this->attachments as $attachment_id => $attachment_data) {
            if ($attachment_data->attachment_hash === $attachment_hash) {
                return $attachment_data;
            }
        }

        return false;
    }

    /**
     * @throws RandomException
     */
    public function uploadAttachment(
        array $attachment_data,
        Entry $entry_data,
        ?int $field_id = null,
        ?array $field_definition = null,
        bool $is_update = false, // todo ?
        bool $do_watermark = false, // todo ?
    ): array
    {
        global $db, $lang;

        $return_data = [];

        if (isset($attachment_data['error']) && $attachment_data['error'] !== 0) {
            $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed . $lang->error_uploadfailed_detail;

            $return_data['error'] .= match ($attachment_data['error']) {
                self::UPLOAD_ERROR_INI_SIZE => $lang->error_uploadfailed_php1,
                self::UPLOAD_ERROR_FORM_SIZE => $lang->error_uploadfailed_php2,
                self::UPLOAD_ERROR_PARTIAL => $lang->error_uploadfailed_php3,
                self::UPLOAD_ERROR_NO_FILE => $lang->error_uploadfailed_php4,
                self::UPLOAD_ERROR_NO_TMP_DIR => $lang->error_uploadfailed_php6,
                self::UPLOAD_ERROR_CANT_WRITE => $lang->error_uploadfailed_php7,
                default => $lang->sprintf($lang->error_uploadfailed_phpx, $attachment_data['error']),
            };

            return $return_data;
        }

        if (!is_uploaded_file($attachment_data['tmp_name']) || empty($attachment_data['tmp_name'])) {
            $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed . $lang->error_uploadfailed_php4;

            return $return_data;
        }

        $attachment_extension = my_strtolower(get_extension($attachment_data['name']));

        $attachment_mime = my_strtolower($attachment_data['type']);

        $attachment_type_data = false;

        foreach ($this->attachment_types as $type_id => $type_data) {
            if ($type_data['file_extension'] === $attachment_extension &&
                $type_data['mime_type'] === $attachment_mime) {
                $attachment_type_data = $type_data;

                break;
            }
        }

        if ($attachment_type_data === false) {
            $return_data['error'] = $lang->error_attachtype;

            return $return_data;
        }

        if (!empty($field_id)) {
            if (empty($field_definition)) {
                $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed; // todo, update lang

                return $return_data;
            }

            if ($attachment_data['size'] < $field_definition['minimum_size'] * 1024 && !empty($field_definition['minimum_size'])) {
                $return_data['error'] = $lang->sprintf(
                    $lang->myshowcase_create_update_attachment_error_size_too_low,
                    htmlspecialchars_uni($attachment_data['name']),
                    get_friendly_size($field_definition['minimum_size'] * 1024)
                );

                return $return_data;
            }

            if ($attachment_data['size'] > $field_definition['maximum_size'] * 1024 && !empty($field_definition['maximum_size'])) {
                $return_data['error'] = $lang->sprintf(
                    $lang->myshowcase_create_update_attachment_error_size_too_large,
                    htmlspecialchars_uni($attachment_data['name']),
                    get_friendly_size($field_definition['maximum_size'] * 1024)
                );

                return $return_data;
            }
        }

        if ($attachment_data['size'] > $attachment_type_data['maximum_size'] * 1024 && !empty($attachment_type_data['maximum_size'])) {
            $return_data['error'] = $lang->sprintf(
                $lang->error_attachsize,
                htmlspecialchars_uni($attachment_data['name']),
                $attachment_type_data['maximum_size']
            );

            return $return_data;
        }

        if ($this->user_permissions[UserPermissions::AttachmentsUploadQuote] > 0) {
            $user_total_usage = $this->attachments_repository->queryAttachmentSum(["user_id='{$entry_data->user_id}'"]);

            $user_total_usage = $user_total_usage + $attachment_data['size'];

            if ($user_total_usage > ($this->user_permissions[UserPermissions::AttachmentsUploadQuote] * 1024)) {
                $return_data['error'] = $lang->sprintf(
                    $lang->error_reachedattachquota,
                    get_friendly_size($this->user_permissions[UserPermissions::AttachmentsUploadQuote] * 1024)
                );

                return $return_data;
            }
        }

        if (!empty($entry_data->entry_id)) {
            $where_clause = "entry_id='{$entry_data->entry_id}'";
        } else {
            $where_clause = "post_hash='{$db->escape_string($entry_data->post_hash)}'";
        }

        $existing_attachment = $this->queryAttachment(
            [
                "file_name='{$db->escape_string($attachment_data['name'])}'",
                $where_clause
            ],
        );

        $existing_attachment_id = (int)($existing_attachment['attachment_id'] ?? 0);

        if ($existing_attachment_id && !$is_update) {
            $return_data['error'] = $lang->error_alreadyuploaded;

            return $return_data;
        }

        // Check if the attachment directory (YYYYMM) exists, if not, create it
        $directory_month_name = gmdate('Ym');

        if (!is_dir($this->attachments_uploads_path . '/' . $directory_month_name)) {
            mkdir($this->attachments_uploads_path . '/' . $directory_month_name);

            if (!is_dir($this->attachments_uploads_path . '/' . $directory_month_name)) {
                $directory_month_name = '';
            }
        }

        // If safe_mode is enabled, don't attempt to use the monthly directories as it won't work
        if (ini_get('safe_mode') || my_strtolower(ini_get('safe_mode')) === 'on') {
            $directory_month_name = '';
        }

        // All seems to be good, lets move the attachment!

        if (!empty($entry_data->entry_id)) {
            $file_name = "attachment_{$entry_data->user_id}_{$entry_data->entry_id}_" . TIME_NOW . '_' . generateUUIDv4(
                ) . '.attach';
        } else {
            $file_name = "attachment_{$entry_data->user_id}_{$entry_data->post_hash}_" . TIME_NOW . '_' . generateUUIDv4(
                ) . '.attach';
        }

        $file_upload_result = $this->uploadFile($attachment_data, $file_name, $directory_month_name);

        // Failed to create the attachment in the monthly directory, just throw it in the main directory
        if ($file_upload_result['error'] && $directory_month_name) {
            $file_upload_result = $this->uploadFile($attachment_data, $file_name);
        }

        if ($directory_month_name) {
            $file_name = $directory_month_name . '/' . $file_name;
        }

        if ($file_upload_result['error']) {
            $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed . $lang->error_uploadfailed_detail;

            switch ($file_upload_result['error']) {
                case self::UPLOAD_ERROR_INVALID:
                    $return_data['error'] .= $lang->error_uploadfailed_nothingtomove;

                    break;
                case self::UPLOAD_ERROR_FAILED:
                    $return_data['error'] .= $lang->error_uploadfailed_movefailed;

                    break;
            }

            return $return_data;
        }

        // Let's just double-check that it exists
        if (!file_exists($this->attachments_uploads_path . '/' . $file_name)) {
            $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed . $lang->error_uploadfailed_detail . $lang->error_uploadfailed_lost;

            return $return_data;
        }

        $insert_data = [
            'showcase_id' => $this->showcase_id,
            'attachment_hash' => generateUUIDv4(),
            'user_id' => $entry_data->user_id,
            'file_name' => $file_name,
            'mime_type' => my_strtolower($file_upload_result['type']),
            'file_size' => $file_upload_result['size'],
            'attachment_name' => $file_upload_result['original_filename'],
            'dateline' => TIME_NOW,
            'status' => self::STATUS_VISIBLE,
            'field_id' => $field_id,
            /*'cdn_file' => 0,*/
        ];

        $return_data['attachment_hash'] = $insert_data['attachment_hash'];

        $return_data['file_name'] = $insert_data['file_name'];

        $return_data['file_size'] = $insert_data['file_size'];

        if (!empty($entry_data->entry_id)) {
            $insert_data['entry_id'] = $entry_data->entry_id;
        } else {
            $insert_data['post_hash'] = $entry_data->post_hash;
        }

        if ($is_update) {
            $insert_data['edit_stamp'] = TIME_NOW;
        }

        if (!$is_update && (
                $this->moderate_attachments_upload ||
                $this->user_permissions[UserPermissions::ModerateAttachmentsUpload]
            )) {
            $insert_data['status'] = self::STATUS_PENDING;
        } elseif ($is_update && (
                $this->moderate_attachments_update ||
                $this->user_permissions[UserPermissions::ModerateAttachmentsUpdate]
            )) {
            $insert_data['status'] = self::STATUS_PENDING;
        }

        // If we're uploading an image, check the MIME type compared to the image type and attempt to generate a thumbnail
        if (in_array($attachment_extension, ['gif', 'png', 'jpg', 'jpeg', 'jpe', 'webp'])) {
            $full_image_dimensions = getimagesize($this->attachments_uploads_path . '/' . $file_name);

            if (!is_array($full_image_dimensions)) {
                delete_uploaded_file($this->attachments_uploads_path . '/' . $file_name);

                $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed;

                return $return_data;
            }

            if (!empty($attachment_type_data['image_minimum_dimensions_width'])) {
                if ($full_image_dimensions[0] < $attachment_type_data['image_minimum_dimensions_width'] ||
                    $full_image_dimensions[1] < $attachment_type_data['image_minimum_dimensions_height']) {
                    delete_uploaded_file($this->attachments_uploads_path . '/' . $file_name);

                    $return_data['error'] = $lang->sprintf(
                        $lang->myShowcaseAttachmentsUploadErrorMinimumDimensions,
                        htmlspecialchars_uni($insert_data['attachment_name']),
                        $attachment_type_data['image_minimum_dimensions_width'],
                        $attachment_type_data['image_minimum_dimensions_height']
                    );

                    return $return_data;
                }
            }

            if (!empty($attachment_type_data['image_maximum_dimensions_width'])) {
                if ($full_image_dimensions[0] > $attachment_type_data['image_maximum_dimensions_width'] ||
                    $full_image_dimensions[1] > $attachment_type_data['image_maximum_dimensions_height']) {
                    delete_uploaded_file($this->attachments_uploads_path . '/' . $file_name);

                    $return_data['error'] = $lang->sprintf(
                        $lang->myShowcaseAttachmentsUploadErrorMaximumDimensions,
                        htmlspecialchars_uni($insert_data['attachment_name']),
                        $attachment_type_data['image_maximum_dimensions_width'],
                        $attachment_type_data['image_maximum_dimensions_height']
                    );

                    return $return_data;
                }
            }

            // Check a list of known MIME types to establish what kind of image we're uploading
            $image_mime = match ($insert_data['mime_type']) {
                'image/gif' => IMAGETYPE_GIF,
                'image/jpeg', 'image/x-jpg', 'image/x-jpeg', 'image/pjpeg', 'image/jpg' => IMAGETYPE_JPEG,
                'image/png', 'image/x-png' => IMAGETYPE_PNG,
                'image/bmp', 'image/x-bmp', 'image/x-windows-bmp' => IMAGETYPE_BMP,
                'image/webp' => IMAGETYPE_WEBP,
                default => 0,
            };

            // todo, https://github.com/JamesHeinrich/phpThumb

            if (function_exists('finfo_open')) {
                $file_info = finfo_open(FILEINFO_MIME);

                $attachment_mime = my_strtolower(
                    explode(
                        ';',
                        finfo_file($file_info, $this->attachments_uploads_path . '/' . $file_name)
                    )[0] ?? ''
                );

                finfo_close($file_info);
            } elseif (function_exists('mime_content_type')) {
                $attachment_mime = my_strtolower(
                    mime_content_type(
                        MYBB_ROOT . $this->attachments_uploads_path . '/' . $file_name
                    )
                );
            }

            $return_data['mime_type'] = $attachment_mime;

            // we check again just in case
            if ($attachment_type_data['mime_type'] !== $attachment_mime) {
                $return_data['error'] = $lang->error_attachtype;

                return $return_data;
            }

            if ($full_image_dimensions[2] !== $image_mime || !$image_mime) {
                delete_uploaded_file($this->attachments_uploads_path . '/' . $file_name);

                $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorInvalidType;

                return $return_data;
            }

            require_once MYBB_ROOT . 'inc/functions_image.php';

            if ($this->attachments_thumbnails_width > 0 &&
                $this->attachments_thumbnails_height > 0 &&
                in_array($image_mime, [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG])) {
                $thumbnail_image_data = generate_thumbnail(
                    $this->attachments_uploads_path . '/' . $file_name,
                    $this->attachments_uploads_path,
                    str_replace('.attach', "_thumb.{$attachment_extension}", $file_name),
                    $this->attachments_thumbnails_width,
                    $this->attachments_thumbnails_height
                );

                // todo, maybe should just ignore ?
                if ($thumbnail_image_data['code'] === self::ERROR_THUMBNAIL_FAILED) {
                    delete_uploaded_file($this->attachments_uploads_path . '/' . $file_name);

                    $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed;

                    return $return_data;
                }

                // we only generate thumbnails for large images
                if ($thumbnail_image_data['code'] !== self::ERROR_THUMBNAIL_TOO_SMALL) {
                    if (empty($thumbnail_image_data['filename'])) {
                        delete_uploaded_file($this->attachments_uploads_path . '/' . $file_name);

                        $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorThumbnailFailure;

                        return $return_data;
                    }

                    $insert_data['thumbnail_name'] = $return_data['thumbnail_name'] = $thumbnail_image_data['filename'];

                    $thumbnail_image_dimensions = getimagesize(
                        $this->attachments_uploads_path . '/' . $thumbnail_image_data['filename']
                    );

                    if (!is_array($thumbnail_image_dimensions)) {
                        delete_uploaded_file($this->attachments_uploads_path . '/' . $file_name);

                        $return_data['error'] = $lang->myShowcaseAttachmentsUploadErrorThumbnailFailure;

                        return $return_data;
                    }

                    $insert_data['thumbnail_dimensions'] = $return_data['thumbnail_dimensions'] = "{$thumbnail_image_dimensions[0]}x{$thumbnail_image_dimensions[1]}";
                }

                if ($thumbnail_image_data['code'] === self::ERROR_THUMBNAIL_TOO_SMALL) {
                    $insert_data['thumbnail_dimensions'] = $return_data['thumbnail_dimensions'] = self::ATTACHMENT_THUMBNAIL_SMALL;
                }
            }

            $watermark_image_path = MYBB_ROOT . $this->attachments_watermark_file;

            //if requested and enabled, watermark the master image
            if ($this->user_permissions[UserPermissions::CanWaterMarkAttachments] &&
                $do_watermark &&
                file_exists($watermark_image_path)) {
                //get watermark image object
                switch (strtolower(get_extension($this->attachments_watermark_file))) {
                    case 'gif':
                        $watermark_image_object = imagecreatefromgif($watermark_image_path);
                        break;
                    case 'jpg':
                    case 'jpeg':
                    case 'jpe':
                        $watermark_image_object = imagecreatefromjpeg($watermark_image_path);
                        break;
                    case 'png':
                        $watermark_image_object = imagecreatefrompng($watermark_image_path);
                        break;
                }

                if (!empty($watermark_image_object)) {
                    //get watermark size
                    $watermark_image_width = imagesx($watermark_image_object);

                    $watermark_image_height = imagesy($watermark_image_object);

                    //get size of base image
                    $image_full_details = getimagesize($this->attachments_uploads_path . '/' . $file_name);

                    //set watermark location
                    switch ($this->attachments_watermark_location) {
                        case self::WATERMARK_LOCATION_LOWER_LEFT:
                            $watermark_position_x = 5;

                            $watermark_position_y = $image_full_details[1] - $watermark_image_height - 5;
                            break;
                        case self::WATERMARK_LOCATION_LOWER_RIGHT:
                            $watermark_position_x = $image_full_details[0] - $watermark_image_width - 5;

                            $watermark_position_y = $image_full_details[1] - $watermark_image_height - 5;
                            break;
                        case self::WATERMARK_LOCATION_CENTER:
                            $watermark_position_x = $image_full_details[0] / 2 - $watermark_image_width / 2;

                            $watermark_position_y = $image_full_details[1] / 2 - $watermark_image_height / 2;
                            break;
                        case self::WATERMARK_LOCATION_UPPER_LEFT:
                            $watermark_position_x = 5;

                            $watermark_position_y = 5;
                            break;
                        case self::WATERMARK_LOCATION_UPPER_RIGHT:
                            $watermark_position_x = $image_full_details[0] - $watermark_image_width - 5;

                            $watermark_position_y = 5;
                            break;
                    }

                    //get base image object
                    switch ($image_mime) {
                        case IMAGETYPE_GIF:
                            $watermarked_attachment_file = imagecreatefromgif(
                                $this->attachments_uploads_path . '/' . $file_name
                            );
                            break;
                        case IMAGETYPE_JPEG:
                            $watermarked_attachment_file = imagecreatefromjpeg(
                                $this->attachments_uploads_path . '/' . $file_name
                            );
                            break;
                        case IMAGETYPE_PNG:
                            $watermarked_attachment_file = imagecreatefrompng(
                                $this->attachments_uploads_path . '/' . $file_name
                            );
                            break;
                    }

                    if (!empty($watermarked_attachment_file) && isset($watermark_position_x) && isset($watermark_position_y)) {
                        imagealphablending($watermarked_attachment_file, true);

                        imagealphablending($watermark_image_object, true);

                        imagecopy(
                            $watermarked_attachment_file,
                            $watermark_image_object,
                            $watermark_position_x,
                            $watermark_position_y,
                            0,
                            0,
                            min($watermark_image_width, $image_full_details[0]),
                            min($watermark_image_height, $image_full_details[1])
                        );

                        //remove watermark from memory
                        imagedestroy($watermark_image_object);

                        //write modified file

                        $source_attachment_file = fopen($this->attachments_uploads_path . '/' . $file_name, 'w');

                        if ($source_attachment_file) {
                            ob_start();

                            switch ($image_mime) {
                                case IMAGETYPE_GIF:
                                    imagegif($watermarked_attachment_file);
                                    break;
                                case IMAGETYPE_JPEG:
                                    imagejpeg($watermarked_attachment_file);
                                    break;
                                case IMAGETYPE_PNG:
                                    imagepng($watermarked_attachment_file);
                                    break;
                            }

                            $content = ob_get_clean();

                            ob_end_clean();

                            fwrite($source_attachment_file, $content);

                            fclose($source_attachment_file);

                            imagedestroy($watermarked_attachment_file);
                        }
                    }
                }
            }

            $return_data['dimensions'] = $insert_data['dimensions'] = "{$full_image_dimensions[0]}x{$full_image_dimensions[1]}";
        }

        $insert_data = hooksRun('upload_update_attachment_insert', $insert_data);

        if ($existing_attachment_id && $is_update) {
            $this->attachments_repository->updateAttachment($insert_data, $existing_attachment_id);
        } else {
            $this->attachments_repository->insertAttachment($insert_data);
        }

        $return_data['attachment_id'] = $existing_attachment_id;

        return $return_data;
    }

//todo review hooks here

    /**
     * Actually move a file to the uploads directory
     *
     * @param array $file_data The PHP $_FILE array for the file
     * @param string $uploads_path The path to save the file in
     * @param string $file_name The file_name for the file (if blank, current is used)
     */
    public function uploadFile(array $file_data, string $file_name = '', string $uploads_path = ''): array
    {
        $return_data = [];

        if (empty($file_data['name']) || $file_data['name'] === 'none' || $file_data['size'] < 1) {
            $return_data['error'] = self::UPLOAD_ERROR_INVALID;

            return $return_data;
        }

        if (!$file_name) {
            $file_name = $file_data['name'];
        }

        $return_data['original_filename'] = preg_replace('#/$#', '', $file_data['name']);

        $file_name = preg_replace('#/$#', '', $file_name);

        if (!move_uploaded_file(
            $file_data['tmp_name'],
            $this->attachments_uploads_path . '/' . $uploads_path . '/' . $file_name
        )) {
            $return_data['error'] = self::UPLOAD_ERROR_FAILED;

            return $return_data;
        }

        my_chmod($this->attachments_uploads_path . '/' . $uploads_path . '/' . $file_name, '0644');

        $return_data['file_name'] = $file_name;

        $return_data['path'] = $this->attachments_uploads_path . '/' . $uploads_path;

        $return_data['type'] = $file_data['type'];

        $return_data['size'] = $file_data['size'];

        return hooksRun('upload_file', $return_data);
    }
}