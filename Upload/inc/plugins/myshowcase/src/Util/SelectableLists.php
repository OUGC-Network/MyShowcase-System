<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use datacache;
use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyBB;
use MyBB\Extensions\Theme\Repository;
use MyLanguage;
use MyShowcase\Entity\PermissionEntity;
use MyShowcase\Enum\AttachmentsDisplayType;
use MyShowcase\Enum\AttachmentsMainRenderType;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\FilterFields;
use MyShowcase\Enum\SortFields;
use MyShowcase\Enum\WatermarkLocations;
use MyShowcase\Service\CacheManager;
use MyShowcase\Service\ShowcaseService;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyBB\app;

readonly class SelectableLists
{
    public function __construct(
        private MyLanguage $language,
        private null|DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database = null,
        private ?MyBB $mybb = null,
    ) {
    }

    public function collectionsList(): array
    {
        return array_map(function (array $collectionData): string {
            return $collectionData['name'];
        }, (new CacheManager($this->database, $this->mybb->cache))->get(CacheDataTypes::Collections));
    }

    public function timeIntervalList(string $language_prefix, string $section): array
    {
        return [
            PermissionEntity::TIME_INTERVAL_TYPE_SECONDS => $this->language->{$language_prefix . ($section ? '_' . $section : '') . '_interval_type_seconds'} ?? $language_prefix . ($section ? '_' . $section : '') . '_interval_type_seconds',
            PermissionEntity::TIME_INTERVAL_TYPE_MINUTES => $this->language->{$language_prefix . ($section ? '_' . $section : '') . '_interval_type_minutes'} ?? $language_prefix . ($section ? '_' . $section : '') . '_interval_type_minutes',
            PermissionEntity::TIME_INTERVAL_TYPE_HOURS => $this->language->{$language_prefix . ($section ? '_' . $section : '') . '_interval_type_hours'} ?? $language_prefix . ($section ? '_' . $section : '') . '_interval_type_hours',
            PermissionEntity::TIME_INTERVAL_TYPE_DAYS => $this->language->{$language_prefix . ($section ? '_' . $section : '') . '_interval_type_days'} ?? $language_prefix . ($section ? '_' . $section : '') . '_interval_type_days',
        ];
    }

    public function themesList(): array
    {
        $extension_repository = app(Repository::class);

        $packages = $extension_repository->getAll();

        return ['' => $this->language->myshowcase_admin_summary_create_update_custom_theme_id_none] + array_combine(
                array_keys($packages),
                array_keys($packages),
            );
    }

    public function filterFieldsList(): array
    {
        return [
            FilterFields::None => $this->language->myshowcase_admin_summary_create_update_main_filter_force_field_none,
            FilterFields::UserID => $this->language->myshowcase_admin_summary_create_update_main_filter_force_field_user_id,
        ];
    }

    public function sortFieldsEntryList(): array
    {
        return [
            SortFields::Dateline => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_dateline ?? '',
            SortFields::Username => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_username ?? '',
            SortFields::UserId => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_user_identifier ?? '',
            SortFields::Views => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_views ?? '',
            SortFields::CommentsApproved => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_comments_approved ?? '',
            SortFields::LastComment => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_last_comment ?? '',
        ];
    }

    public function sortFieldsCommentList(): array
    {
        return [
            SortFields::Dateline => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_dateline ?? '',
            //SortFields::CommentParentIdentifier => $this->language->myshowcase_admin_summary_create_update_main_filter_sort_field_user_identifier ?? '',
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

    /**
     * The global templates and every template set (folder of view/templates). A stored prefix whose folder no longer
     * exists is listed as missing so that the form keeps it until another choice is saved.
     */
    public function templateSetsList(string $current = ''): array
    {
        $list = ['' => $this->language->myshowcase_admin_summary_create_update_custom_template_prefix_global ?? 'Global templates (none)'];

        foreach (ShowcaseService::templateSets() as $set) {
            $list[$set] = $set;
        }

        if ($current !== '' && !isset($list[$current])) {
            $list[$current] = $this->language->sprintf(
                $this->language->myshowcase_admin_summary_create_update_custom_template_prefix_missing ?? '{1} (missing)',
                $current
            );
        }

        return $list;
    }

    public function attachmentsDisplayTypeList(): array
    {
        return [
            AttachmentsDisplayType::Links => $this->language->myshowcase_admin_summary_create_update_attachments_display_type_links,
            AttachmentsDisplayType::Thumbnails => $this->language->myshowcase_admin_summary_create_update_attachments_display_type_thumbnails,
            AttachmentsDisplayType::FullImages => $this->language->myshowcase_admin_summary_create_update_attachments_display_type_full_images,
        ];
    }

    /**
     * Generate a list of the posticons.
     *
     * @return array The list of posticons.
     */
    public function postIconsList(): array
    {
        global $theme;

        $icons_cache = (array)$this->mybb->cache->read('posticons');

        $icon_objects = [];

        foreach ($icons_cache as $icon_data) {
            $icon_objects[$icon_data['name']] = $icon_data;
        }

        krsort($icon_objects);

        $icons_list = [];

        foreach ($icon_objects as $icon_data) {
            $icon_data['path'] = str_replace('{theme}', $theme['imgdir'], $icon_data['path']);

            $icon_data['path'] = $this->mybb->get_asset_url($icon_data['path']);

            $icons_list[] = $icon_data;
        }

        return $icons_list;
    }
}