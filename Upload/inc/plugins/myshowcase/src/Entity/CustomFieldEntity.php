<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use MyShowcase\Collection\Collection;

use function MyShowcase\Plugin\Functions\fieldDataGet;

final  class CustomFieldEntity
{
    private readonly Collection $selectable_values;

    public array $extra_data;

    public function __construct(
        public int $field_id = 0,
        public int $set_id = 0,
        public string $field_key = '',
        public string $field_label = '',
        public string $placeholder = '',
        public string $description = '',
        public string $html_type = '',
        public string $field_type = '',
        public int $file_capture = 0,
        public int $allow_multiple_values = 0,
        public string $regular_expression = '',
        public string $input_pattern = '',
        public int $display_in_create_update_page = 1,
        public int $display_in_view_page = 1,
        public int $display_in_main_page = 1,
        public int $minimum_length = 0,
        public int $maximum_length = 0,
        public int $minimum_size = 0,
        public int $maximum_size = 0,
        public int $step_size = 0,
        public int $input_size = 0,
        public string $allowed_groups_fill = '',
        public string $allowed_groups_view = '',
        public string $default_value = '',
        public int $default_type = 0,
        public int $display_order = 0,
        public int $render_order = 0,
        public string $format = '0',
        public int $filter_on_save = 0,
        public int $is_enabled = 0,
        public int $is_required = 0,
        public int $parser_enable = 0,
        public int $parser_allow_html = 0,
        public int $parser_allow_mycode = 0,
        public int $parser_allow_smiles = 0,
        public int $parser_allow_image_code = 0,
        public int $parser_allow_video_code = 0,
        public int $parser_affects_message_length = 0,
        public int $enable_search = 0,
        public int $enable_slug = 0,
        public int $enable_subject = 0,
        public int $enable_description = 0,
        public int $enable_editor = 0,
        public int $read_only = 0,
    ) {
        $this->selectable_values = new Collection();
    }

    public function setExtraData(string $name, mixed $data): void
    {
        $this->extra_data[$name] = $data;
    }

    public function getExtraData(string $name): array|false
    {
        if (isset($this->extra_data[$name])) {
            return $this->extra_data[$name];
        }

        return false;
    }

    public function isViewableInMain(): bool
    {
        return !empty($this->is_enabled) &&
            !empty($this->display_in_main_page) &&
            is_member($this->allowed_groups_view);
    }

    public function isViewableInEntry(): bool
    {
        return !empty($this->is_enabled) &&
            !empty($this->display_in_view_page) &&
            is_member($this->allowed_groups_view);
    }

    public function isViewableInForm(): bool
    {
        return !empty($this->is_enabled) &&
            !empty($this->display_in_create_update_page) &&
            is_member($this->allowed_groups_fill);
    }

    public function getSelectableValues(): Collection
    {
        return $this->selectable_values;
    }

    public function fetchSelectableValues(bool $verify_fill_permission = false): void
    {
        foreach (
            fieldDataGet(
                [
                    "set_id='{$this->set_id}'",
                    "field_id='{$this->field_id}'"
                ],
                [
                    'field_data_id',
                    'value_id',
                    'value',
                    'display_style',
                    'allowed_groups_fill',
                    'allowed_groups_view',
                ],
                ['order_by' => 'display_order']
            ) as $field_data_id => $field_data_data
        ) {
            if ($verify_fill_permission && !is_member($field_data_data['allowed_groups_fill'])) {
                continue;
            }

            if (!$this->selectable_values->contains($field_data_data)) {
                $this->selectable_values[$field_data_id] = $field_data_data;
            }
        }
    }
}