<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyLanguage;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\AttachmentEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Entity\UserEntity;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Event\Listener\EntryDeletedAttachmentListener;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\UuidUtil;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\stringKeysOnly;
use function MyShowcase\Plugin\Functions\castTableFieldValue;
use function MyShowcase\Plugin\Functions\hooksRun;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class AttachmentsManager
{
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
    private const FIRST_PAGE = 1;
    private readonly AttachmentsRepository $attachments_repository;
    private int $total_attachments = 0;
    private Collection $attachments;
    private array $insert_data = [];
    private array $return_data = [];
    private array $return_data_file = [];

    public function __construct(
        public readonly ShowcaseContext $context,
        //private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        //private readonly MyLanguage $language,
        //private readonly User $current_user,
        //private readonly array $attachment_types,
        //private readonly EventDispatcher $event_dispatcher,
        //private readonly ConfigManager $config,
    )
    {
        $this->attachments = new Collection();

        $this->context->event_dispatcher->addListener(
            EntryDeletedEvent::NAME,
            new EntryDeletedAttachmentListener($this->context->attachments_repository)
        );
    }

    public function addAttachment(array $attachment_data): void
    {
        foreach (TABLES_DATA['myshowcase_attachments'] as $field_name => $field_definition) {
            if (isset($attachment_data[$field_name])) {
                $attachment_data[$field_name] = castTableFieldValue(
                    $attachment_data[$field_name],
                    $field_definition['type']
                );
            }
        }

        if ($this->attachments->contains($attachment_data)) {
            return;
        }

        $this->attachments[$attachment_data['attachment_id']] = new AttachmentEntity(...stringKeysOnly($attachment_data));
    }

    public function getTotalAttachments(): int
    {
        return $this->total_attachments;
    }

    /**
     * @return Collection<AttachmentEntity>
     */
    public function getAttachments(): Collection
    {
        return $this->attachments;
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

    public function getAttachmentByEntryID(int $entry_id): AttachmentEntity|bool
    {
        foreach ($this->attachments as $attachment_id => $attachment_data) {
            if ($attachment_data->entry_id === $entry_id) {
                return $attachment_data;
            }
        }

        return false;
    }

    /**
     * Attachment types allowed in this showcase, from the attachment types cache.
     */
    private function attachmentTypes(): array
    {
        return $this->context->cache_manager->get(CacheDataTypes::AttachmentTypes)[$this->context->showcase->showcase_id] ?? [];
    }

    public function getAttachmentByHash(string $attachment_hash): AttachmentEntity|bool
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
        EntryEntity $entry,
        ?int $field_id = null,
        ?array $field_definition = null,
        bool $is_update = false, // todo ?
        bool $do_watermark = false, // todo ?
    ): array
    {
        if (isset($attachment_data['error']) && $attachment_data['error'] !== 0) {
            $error_message = $this->context->language->myshowcase_attachments_upload_error_upload_failed . $this->context->language->error_uploadfailed_detail;

            $error_message .= match ($attachment_data['error']) {
                self::UPLOAD_ERROR_INI_SIZE => $this->context->language->error_uploadfailed_php1,
                self::UPLOAD_ERROR_FORM_SIZE => $this->context->language->error_uploadfailed_php2,
                self::UPLOAD_ERROR_PARTIAL => $this->context->language->error_uploadfailed_php3,
                self::UPLOAD_ERROR_NO_FILE => $this->context->language->error_uploadfailed_php4,
                self::UPLOAD_ERROR_NO_TMP_DIR => $this->context->language->error_uploadfailed_php6,
                self::UPLOAD_ERROR_CANT_WRITE => $this->context->language->error_uploadfailed_php7,
                default => $this->context->language->sprintf(
                    $this->context->language->error_uploadfailed_phpx,
                    $attachment_data['error']
                ),
            };

            return ['error' => $error_message];
        }

        if (!is_uploaded_file($attachment_data['tmp_name']) || empty($attachment_data['tmp_name'])) {
            return ['error' => $this->context->language->myshowcase_attachments_upload_error_upload_failed . $this->context->language->error_uploadfailed_php4];
        }

        $attachment_extension = my_strtolower(get_extension($attachment_data['name']));

        $attachment_mime = my_strtolower($attachment_data['type']);

        $attachment_type_data = false;

        foreach ($this->attachmentTypes() as $type_id => $type_data) {
            if ($type_data['file_extension'] === $attachment_extension &&
                $type_data['mime_type'] === $attachment_mime) {
                $attachment_type_data = $type_data;

                break;
            }
        }

        if ($attachment_type_data === false) {
            return ['error' => $this->context->language->error_attachtype];
        }

        if (!empty($field_id)) {
            if (empty($field_definition)) {
                return ['error' => $this->context->language->myshowcase_attachments_upload_error_upload_failed]; // todo, update lang
            }

            if ($attachment_data['size'] < $field_definition['minimum_size'] * 1024 && !empty($field_definition['minimum_size'])) {
                return [
                    'error' => $this->context->language->sprintf(
                        $this->context->language->myshowcase_create_update_attachment_error_size_too_low,
                        htmlspecialchars_uni($attachment_data['name']),
                        get_friendly_size($field_definition['minimum_size'] * 1024)
                    )
                ];
            }

            if ($attachment_data['size'] > $field_definition['maximum_size'] * 1024 && !empty($field_definition['maximum_size'])) {
                return [
                    'error' => $this->context->language->sprintf(
                        $this->context->language->myshowcase_create_update_attachment_error_size_too_large,
                        htmlspecialchars_uni($attachment_data['name']),
                        get_friendly_size($field_definition['maximum_size'] * 1024)
                    )
                ];
            }
        }

        if ($attachment_data['size'] > $attachment_type_data['maximum_size'] * 1024 && !empty($attachment_type_data['maximum_size'])) {
            return [
                'error' => $this->context->language->sprintf(
                    $this->context->language->error_attachsize,
                    htmlspecialchars_uni($attachment_data['name']),
                    $attachment_type_data['maximum_size']
                )
            ];
        }

        if ($this->context->current_user->hasPermission(UserPermissions::AttachmentsLimitUploadQuotaAmount) > 0) {
            $user_total_usage = $this->context->attachments_repository->queryAttachmentSum(
                ["user_id='{$entry->user_id}'"]
            );

            $user_total_usage = $user_total_usage + $attachment_data['size'];

            if ($user_total_usage > ($this->context->current_user->hasPermission(
                        UserPermissions::AttachmentsLimitUploadQuotaAmount
                    ) * 1024)) {
                return [
                    'error' => $this->context->language->sprintf(
                        $this->context->language->error_reachedattachquota,
                        get_friendly_size(
                            $this->context->current_user->hasPermission(
                                UserPermissions::AttachmentsLimitUploadQuotaAmount
                            ) * 1024
                        )
                    )
                ];
            }
        }

        if (!empty($entry->entry_id)) {
            $where_clause = "entry_id='{$entry->entry_id}'";
        } else {
            $where_clause = "post_hash='{$this->context->database->escape_string($entry->post_hash)}'";
        }

        $existing_attachment = $this->context->attachments_repository->queryAttachment(
            [
                "file_name='{$this->context->database->escape_string($attachment_data['name'])}'",
                $where_clause
            ],
        );

        $existing_attachment_id = (int)($existing_attachment['attachment_id'] ?? 0);

        if ($existing_attachment_id && !$is_update) {
            return ['error' => $this->context->language->error_alreadyuploaded];
        }

        // Check if the attachment directory (YYYYMM) exists, if not, create it
        $directory_month_name = gmdate('Ym');

        if (!is_dir($this->context->showcase->attachments_uploads_path . '/' . $directory_month_name)) {
            mkdir($this->context->showcase->attachments_uploads_path . '/' . $directory_month_name);

            if (!is_dir($this->context->showcase->attachments_uploads_path . '/' . $directory_month_name)) {
                $directory_month_name = '';
            }
        }

        // If safe_mode is is_enabled, don't attempt to use the monthly directories as it won't work
        if (ini_get('safe_mode') || my_strtolower(ini_get('safe_mode')) === 'on') {
            $directory_month_name = '';
        }

        // All seems to be good, lets move the attachment!

        if (!empty($entry->entry_id)) {
            $file_name = "attachment_{$entry->user_id}_{$entry->entry_id}_" . TIME_NOW . '_' . (new UuidUtil(
                ))->generateUuidV4() . '.attach';
        } else {
            $file_name = "attachment_{$entry->user_id}_{$entry->post_hash}_" . TIME_NOW . '_' . (new UuidUtil(
                ))->generateUuidV4() . '.attach';
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
            $error_message = $this->context->language->myshowcase_attachments_upload_error_upload_failed . $this->context->language->error_uploadfailed_detail;

            switch ($file_upload_result['error']) {
                case self::UPLOAD_ERROR_INVALID:
                    $error_message .= $this->context->language->error_uploadfailed_nothingtomove;

                    break;
                case self::UPLOAD_ERROR_FAILED:
                    $error_message .= $this->context->language->error_uploadfailed_movefailed;

                    break;
            }

            return ['error' => $error_message];
        }

        // Let's just double-check that it exists
        if (!file_exists($this->context->showcase->attachments_uploads_path . '/' . $file_name)) {
            return ['error' => $this->context->language->myshowcase_attachments_upload_error_upload_failed . $this->context->language->error_uploadfailed_detail . $this->context->language->error_uploadfailed_lost];
        }

        $this->insert_data = [
            'showcase_id' => $this->context->showcase->showcase_id,
            'attachment_hash' => (new UuidUtil())->generateUuidV4(),
            'user_id' => $entry->user_id,
            'file_name' => $file_name,
            'mime_type' => my_strtolower($file_upload_result['type']),
            'file_size' => $file_upload_result['size'],
            'attachment_name' => $file_upload_result['original_filename'],
            'dateline' => TIME_NOW,
            'status' => ContentStatuses::VisibleApproved,
            'field_id' => $field_id,
            /*'cdn_file' => 0,*/
        ];

        $this->return_data['attachment_hash'] = $this->insert_data['attachment_hash'];

        $this->return_data['file_name'] = $this->insert_data['file_name'];

        $this->return_data['file_size'] = $this->insert_data['file_size'];

        if (!empty($entry->entry_id)) {
            $this->insert_data['entry_id'] = $entry->entry_id;
        } else {
            $this->insert_data['post_hash'] = $entry->post_hash;
        }

        if ($is_update) {
            $this->insert_data['update_dateline'] = TIME_NOW;
        }

        if (!$is_update && $this->context->current_user->hasPermission(UserPermissions::AttachmentsModerateNew)) {
            $this->insert_data['status'] = ContentStatuses::AwaitingApproval;
        } elseif ($is_update && $this->context->current_user->hasPermission(
                UserPermissions::AttachmentsModerateUpdates
            )) {
            $this->insert_data['status'] = ContentStatuses::AwaitingApproval;
        }

        // If we're uploading an image, check the MIME type compared to the image type and attempt to generate a thumbnail
        if (in_array($attachment_extension, ['gif', 'png', 'jpg', 'jpeg', 'jpe', 'webp'])) {
            $full_image_dimensions = getimagesize(
                $this->context->showcase->attachments_uploads_path . '/' . $file_name
            );

            if (!is_array($full_image_dimensions)) {
                delete_uploaded_file($this->context->showcase->attachments_uploads_path . '/' . $file_name);

                return ['error' => $this->context->language->myshowcase_attachments_upload_error_upload_failed];
            }

            if (!empty($attachment_type_data['image_minimum_dimensions_width'])) {
                if ($full_image_dimensions[0] < $attachment_type_data['image_minimum_dimensions_width'] ||
                    $full_image_dimensions[1] < $attachment_type_data['image_minimum_dimensions_height']) {
                    delete_uploaded_file($this->context->showcase->attachments_uploads_path . '/' . $file_name);

                    return [
                        'error' => $this->context->language->sprintf(
                            $this->context->language->myshowcase_attachments_upload_error_minimum_dimensions,
                            htmlspecialchars_uni($this->insert_data['attachment_name']),
                            $attachment_type_data['image_minimum_dimensions_width'],
                            $attachment_type_data['image_minimum_dimensions_height']
                        )
                    ];
                }
            }

            if (!empty($attachment_type_data['image_maximum_dimensions_width'])) {
                if ($full_image_dimensions[0] > $attachment_type_data['image_maximum_dimensions_width'] ||
                    $full_image_dimensions[1] > $attachment_type_data['image_maximum_dimensions_height']) {
                    delete_uploaded_file($this->context->showcase->attachments_uploads_path . '/' . $file_name);

                    return [
                        'error' => $this->context->language->sprintf(
                            $this->context->language->myshowcase_attachments_upload_error_maximum_dimensions,
                            htmlspecialchars_uni($this->insert_data['attachment_name']),
                            $attachment_type_data['image_maximum_dimensions_width'],
                            $attachment_type_data['image_maximum_dimensions_height']
                        )
                    ];
                }
            }

            // Check a list of known MIME types to establish what kind of image we're uploading
            $image_mime = match ($this->insert_data['mime_type']) {
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
                        finfo_file($file_info, $this->context->showcase->attachments_uploads_path . '/' . $file_name)
                    )[0] ?? ''
                );

                finfo_close($file_info);
            } elseif (function_exists('mime_content_type')) {
                $attachment_mime = my_strtolower(
                    mime_content_type(
                        MYBB_ROOT . $this->context->showcase->attachments_uploads_path . '/' . $file_name
                    )
                );
            }

            $this->return_data['mime_type'] = $attachment_mime;

            // we check again just in case
            if ($attachment_type_data['mime_type'] !== $attachment_mime) {
                return ['error' => $this->context->language->error_attachtype];
            }

            if ($full_image_dimensions[2] !== $image_mime || !$image_mime) {
                delete_uploaded_file($this->context->showcase->attachments_uploads_path . '/' . $file_name);

                return ['error' => $this->context->language->myshowcase_attachments_upload_error_invalid_type];
            }

            require_once MYBB_ROOT . 'inc/functions_image.php';

            if ($this->context->showcase->attachments_thumbnails_width > 0 &&
                $this->context->showcase->attachments_thumbnails_height > 0 &&
                in_array($image_mime, [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG])) {
                $thumbnail_image_data = generate_thumbnail(
                    $this->context->showcase->attachments_uploads_path . '/' . $file_name,
                    $this->context->showcase->attachments_uploads_path,
                    str_replace('.attach', "_thumb.{$attachment_extension}", $file_name),
                    $this->context->showcase->attachments_thumbnails_width,
                    $this->context->showcase->attachments_thumbnails_height
                );

                // todo, maybe should just ignore ?
                if ($thumbnail_image_data['code'] === self::ERROR_THUMBNAIL_FAILED) {
                    delete_uploaded_file($this->context->showcase->attachments_uploads_path . '/' . $file_name);

                    return ['error' => $this->context->language->myshowcase_attachments_upload_error_upload_failed];
                }

                // we only generate thumbnails for large images
                if ($thumbnail_image_data['code'] !== self::ERROR_THUMBNAIL_TOO_SMALL) {
                    if (empty($thumbnail_image_data['filename'])) {
                        delete_uploaded_file($this->context->showcase->attachments_uploads_path . '/' . $file_name);

                        return ['error' => $this->context->language->myshowcase_attachments_upload_error_thumbnail_failure];
                    }

                    $this->return_data['thumbnail_name'] = $this->insert_data['thumbnail_name'] = $thumbnail_image_data['filename'];

                    $thumbnail_image_dimensions = getimagesize(
                        $this->context->showcase->attachments_uploads_path . '/' . $thumbnail_image_data['filename']
                    );

                    if (!is_array($thumbnail_image_dimensions)) {
                        delete_uploaded_file($this->context->showcase->attachments_uploads_path . '/' . $file_name);

                        return ['error' => $this->context->language->myshowcase_attachments_upload_error_thumbnail_failure];
                    }

                    $this->return_data['thumbnail_dimensions'] = $this->insert_data['thumbnail_dimensions'] = "{$thumbnail_image_dimensions[0]}x{$thumbnail_image_dimensions[1]}";
                }

                if ($thumbnail_image_data['code'] === self::ERROR_THUMBNAIL_TOO_SMALL) {
                    $this->return_data['thumbnail_dimensions'] = $this->insert_data['thumbnail_dimensions'] = self::ATTACHMENT_THUMBNAIL_SMALL;
                }
            }

            $watermark_image_path = MYBB_ROOT . $this->context->showcase->attachments_watermark_file;

            //if requested and is_enabled, watermark the master image
            if ($this->context->current_user->hasPermission(UserPermissions::AttachmentsWaterMarkImages) &&
                $do_watermark &&
                file_exists($watermark_image_path)) {
                //get watermark image object
                switch (strtolower(get_extension($this->context->showcase->attachments_watermark_file))) {
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
                    $image_full_details = getimagesize(
                        $this->context->showcase->attachments_uploads_path . '/' . $file_name
                    );

                    //set watermark location
                    switch ($this->context->showcase->attachments_watermark_location) {
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
                                $this->context->showcase->attachments_uploads_path . '/' . $file_name
                            );
                            break;
                        case IMAGETYPE_JPEG:
                            $watermarked_attachment_file = imagecreatefromjpeg(
                                $this->context->showcase->attachments_uploads_path . '/' . $file_name
                            );
                            break;
                        case IMAGETYPE_PNG:
                            $watermarked_attachment_file = imagecreatefrompng(
                                $this->context->showcase->attachments_uploads_path . '/' . $file_name
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

                        $source_attachment_file = fopen(
                            $this->context->showcase->attachments_uploads_path . '/' . $file_name,
                            'w'
                        );

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

            $this->return_data['dimensions'] = $this->insert_data['dimensions'] = "{$full_image_dimensions[0]}x{$full_image_dimensions[1]}";
        }

        hooksRun('attachment_upload_update', $this);

        if ($existing_attachment_id && $is_update) {
            $this->context->attachments_repository->updateAttachment($this->insert_data, $existing_attachment_id);

            $attachment_id = (int)$existing_attachment_id;
        } else {
            $attachment_id = (int)$this->context->attachments_repository->insertAttachment($this->insert_data);
        }

        $this->return_data['attachment_id'] = $attachment_id;

        $this->return_data['attachment_name'] = $this->insert_data['attachment_name'];

        $this->return_data['file_size'] = $this->insert_data['file_size'];

        $this->return_data['mime_type'] ??= $this->insert_data['mime_type'];

        return $this->return_data;
    }

    /**
     * Actually move a file to the uploads directory
     *
     * @param array $file_data The PHP $_FILE array for the file
     * @param string $uploads_path The path to save the file in
     * @param string $file_name The file_name for the file (if blank, current is used)
     */
    public function uploadFile(array $file_data, string $file_name = '', string $uploads_path = ''): array
    {
        if (empty($file_data['name']) || $file_data['name'] === 'none' || $file_data['size'] < 1) {
            return ['error' => self::UPLOAD_ERROR_INVALID];
        }

        if (!$file_name) {
            $file_name = $file_data['name'];
        }

        $this->return_data_file['original_filename'] = preg_replace('#/$#', '', $file_data['name']);

        $file_name = preg_replace('#/$#', '', $file_name);

        if (!move_uploaded_file(
            $file_data['tmp_name'],
            $this->context->showcase->attachments_uploads_path . '/' . $uploads_path . '/' . $file_name
        )) {
            return ['error' => self::UPLOAD_ERROR_FAILED];
        }

        my_chmod($this->context->showcase->attachments_uploads_path . '/' . $uploads_path . '/' . $file_name, '0644');

        $this->return_data_file['file_name'] = $file_name;

        $this->return_data_file['path'] = $this->context->showcase->attachments_uploads_path . '/' . $uploads_path;

        $this->return_data_file['type'] = $file_data['type'];

        $this->return_data_file['size'] = $file_data['size'];

        hooksRun('attachment_upload_file', $this);

        return $this->return_data_file;
    }
}