<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use datacache;
use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyLanguage;
use MyShowcase\Enum\AttachmentsDisplayType;
use MyShowcase\Enum\AttachmentsMainRenderType;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\FilterFields;
use MyShowcase\Enum\PrefixRequirements;
use MyShowcase\Enum\SortFields;
use MyShowcase\Enum\WatermarkLocations;
use MyShowcase\Service\CacheManager;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

readonly class SelectableArrays
{
    public function __construct(
        private MyLanguage $language,
        private readonly null|DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database = null,
        private readonly ?datacache $cache = null,
    ) {
    }

    public function fieldsetsList(): array
    {
        return array_map(function (array $fieldsetData): string {
            return $fieldsetData['set_name'];
        }, (new CacheManager($this->database, $this->cache))->get(CacheDataTypes::Fieldsets));
    }

    public function filterFieldsList(): array
    {
        return [
            FilterFields::None => $this->language->myshowcase_admin_summary_create_update_main_filter_force_field_none,
            FilterFields::UserID => $this->language->myshowcase_admin_summary_create_update_main_filter_force_field_user_id,
        ];
    }

    public function sortFieldsList(): array
    {
        return [
            SortFields::Dateline => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_dateline ?? '',
            SortFields::Username => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_username ?? '',
            SortFields::Views => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_views ?? '',
            SortFields::CommentsApproved => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_comments_approved ?? '',
        ];
    }

    public function prefixTypeList(): array
    {
        return [
            PrefixRequirements::Disable => $this->language->myshowcase_admin_summary_create_update_entries_allow_prefixes_disable,
            PrefixRequirements::Allow => $this->language->myshowcase_admin_summary_create_update_entries_allow_prefixes_allow,
            PrefixRequirements::Require => $this->language->myshowcase_admin_summary_create_update_entries_allow_prefixes_require,
        ];
    }

    public function attachmentsMainRenderList(): array
    {
        return [
            AttachmentsMainRenderType::None => $this->language->myshowcase_admin_summary_create_update_attachments_main_render_none,
            AttachmentsMainRenderType::AllFiles => $this->language->myshowcase_admin_summary_create_update_attachments_main_render_all,
            AttachmentsMainRenderType::AllImages => $this->language->myshowcase_admin_summary_create_update_attachments_main_render_images,
            AttachmentsMainRenderType::FirstImage => $this->language->myshowcase_admin_summary_create_update_attachments_main_render_first_image,
        ];
    }

    public function watermarkLocationsList(): array
    {
        return [
            WatermarkLocations::LowerLeft => $this->language->myshowcase_admin_summary_create_update_attachments_watermark_location_lower_left,
            WatermarkLocations::LowerRight => $this->language->myshowcase_admin_summary_create_update_attachments_watermark_location_lower_right,
            WatermarkLocations::Center => $this->language->myshowcase_admin_summary_create_update_attachments_watermark_location_center,
            WatermarkLocations::UpperLeft => $this->language->myshowcase_admin_summary_create_update_attachments_watermark_location_upper_left,
            WatermarkLocations::UpperRight => $this->language->myshowcase_admin_summary_create_update_attachments_watermark_location_upper_right,
        ];
    }

    public function attachmentsDisplayTypeList(): array
    {
        return [
            AttachmentsDisplayType::Links => $this->language->myshowcase_admin_summary_create_update_attachments_display_type_links,
            AttachmentsDisplayType::Thumbnails => $this->language->myshowcase_admin_summary_create_update_attachments_display_type_thumbnails,
            AttachmentsDisplayType::FullImages => $this->language->myshowcase_admin_summary_create_update_attachments_display_type_full_images,
        ];
    }
}