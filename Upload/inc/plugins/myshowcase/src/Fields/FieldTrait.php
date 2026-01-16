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

namespace MyShowcase\Fields;

use MyShowcase\Collection\Collection;
use MyShowcase\Entity\Attachment;
use MyShowcase\Entity\Entry;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Service\Render;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\TABLES_DATA;

trait FieldTrait
{
    private Collection $attachments;
    private Collection $selectable_values;

    public string $attachments_rendered = '';

    public ?array $extra_data = [];

    private string $user_value = '';

    private string $user_value_display = '';

    public function setExtraData(string $name, string|bool|int|array|null $value): self
    {
        $this->extra_data[$name] = $value;

        return $this;
    }

    public function getExtraData(string $name): string|bool|int|array|null
    {
        if (!isset($this->extra_data[$name])) {
            return false;
        }

        return $this->extra_data[$name];
    }

    public function isViewableInEntry(): bool
    {
        if (empty($this->field_definition['is_enabled'])) {
            return false;
        }

        if (empty($this->field_definition['display_in_view_page'])) {
            return false;
        }

        return (bool)is_member($this->field_definition['allowed_groups_view']);
    }

    public function valueSet(string|int|bool $userValue): self
    {
        $this->entryFieldValue = $userValue;

        return $this;
    }

    public function valuesBuild(Entry $entry): void
    {
        if (!$this->field_definition['display_in_view_page'] ||
            !is_member($this->field_definition['allowed_groups_view'])) {
            return;
        }

        $user_value_display = [];

        if ($this->entryFieldValue) {
            if ($this->fieldAcceptsMultipleValues()) {
                $userValues = explode($this->multipleSeparator, $this->entryFieldValue);
            } else {
                $userValues = [$this->entryFieldValue];
            }

            foreach ($userValues as $userValueKey => $userValueValue) {
                switch ($this->field_definition['html_type']) {
                    case FieldHtmlTypes::CheckBox:
                    case FieldHtmlTypes::Radio:
                    case FieldHtmlTypes::Select:

                        $value_id = (int)$userValueValue;

                        foreach (
                            fieldDataGet(
                                [
                                    "field_data_id='{$value_id}'",
                                    "set_id='{$this->config['field_set_id']}'",
                                    "field_id='{$this->field_definition['field_id']}'"
                                ],
                                [
                                    'value',
                                    'display_style',
                                    'allowed_groups_view'
                                ],
                                ['order_by' => 'display_order']
                            ) as $field_data_id => $field_data_data
                        ) {
                            if (!is_member($field_data_data['allowed_groups_view'])) {
                                continue;
                            }

                            $userValues[$userValueKey] = $field_data_data['value'];

                            $user_value_display[$userValueKey] = $field_data_data['display_style'] ?? $field_data_data['value'];
                        }

                        break;
                }

                if (!$this->field_definition['parse'] && $this->field_definition['enable_search'] && isset($this->mybb->input['highlight'])) {
                    $userValues[$userValueKey] = postParser()->highlight_message(
                        $userValues[$userValueKey],
                        $this->mybb->input['highlight']
                    );

                    $user_value_display[$userValueKey] = postParser()->highlight_message(
                        $user_value_display[$userValueKey],
                        $this->mybb->input['highlight']
                    );
                } elseif ($this->field_definition['parse']) {
                    static $parser_options = null;

                    if ($parser_options === null) {
                        $parser_options = [
                            'allow_html' => !empty($this->field_definition['parser_allow_html_in_entries']),
                            'allow_mycode' => !empty($this->field_definition['parser_allow_mycode_in_entries']),
                            'allow_smilies' => !empty($this->field_definition['parser_allow_smiles_in_entries']),
                            'allow_imgcode' => !empty($this->field_definition['parser_allow_image_code_in_entries']),
                            'allow_videocode' => !empty($this->field_definition['parser_allow_video_code_in_entries']),
                            'me_username' => $entry->username,
                            'filter_badwords' => true,
                            'shorten_urls' => false,
                            'highlight' => false,
                        ];
                    }

                    if ($this->field_definition['enable_search'] && isset($this->mybb->input['highlight'])) {
                        $parser_options['highlight'] = $this->mybb->input['highlight'];
                    }

                    $userValues[$userValueKey] = postParser()->parse_message(
                        $userValues[$userValueKey] ?? '',
                        $parser_options
                    );

                    $user_value_display[$userValueKey] = postParser()->parse_message(
                        $user_value_display[$userValueKey] ?? '',
                        $parser_options
                    );
                }
            }

            $this->user_value = implode($this->multipleConcatenator, $userValues);

            $this->user_value_display = implode($this->multipleConcatenator, $user_value_display);
        }

        if (!$this->user_value && $this->config['field_set_id']) {
            global $lang;

            $this->user_value = $lang->myShowcaseEntryFieldValueEmpty;
        }

        if (!$this->user_value_display && $this->config['field_set_id']) {
            global $lang;

            $this->user_value_display = $lang->myShowcaseEntryFieldValueEmpty;
        }

        $this->attachmentsFetch($entry);
    }

    public function attachmentsFetch(Entry $entry): void
    {
        $where_clauses = ["field_id='{$this->field_definition['field_id']}'"];

        if (!empty($entry->entry_id)) {
            $where_clauses[] = "entry_id='{$entry->entry_id}'";
        } else {
            global $db;

            $where_clauses[] = "post_hash='{$db->escape_string($entry->post_hash)}'";
        }

        foreach (
            $this->attachments_repository->queryAttachments(
                $where_clauses,// todo, status clause
                [
                    'attachment_id',
                    'showcase_id',
                    'entry_id',
                    'comment_id',
                    'field_id',
                    'post_hash',
                    'attachment_hash',
                    'user_id',
                    'description',
                    'file_name',
                    'mime_type',
                    'file_size',
                    'attachment_name',
                    'downloads',
                    'dateline',
                    'status',
                    'thumbnail_name',
                    'thumbnail_dimensions',
                    'dimensions',
                    'edit_stamp',
                ],
            ) as $attachment_data
        ) {
            $this->attachments_manager->setAttachment($attachment_data);
        }
    }

    public function getUserValue(): string
    {
        return $this->user_value;
    }

    public function getUserValueDisplay(): string
    {
        return $this->user_value_display;
    }

    public function getFieldDescription(): string
    {
        global $lang;

        $fieldDescription = $this->field_definition['description'] ?? ($lang->{'myshowcase_fieldset_field_' . $this->field_definition['field_key'] . '_description'} ?? '');

        if ($fieldDescription) {
            if ($this->field_definition['allow_multiple_values']) {
                // todo, add description for comma/line multiple separator
            }
        }

        return $fieldDescription;
    }

    public function fieldAcceptsMultipleValues(): bool
    {
        return false;
        // todo, allow only html compatible fields
        return fieldTypeMatchText($this->field_definition['field_type']) &&
            $this->field_definition['allow_multiple_values'];
    }

    public function renderMain(): string
    {
        if (!$this->field_definition['display_in_main_page'] || !is_member(
                $this->field_definition['allowed_groups_view']
            )) {
            return '';
        }

        _dump('123');
    }

    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(array $attachment_data): void
    {
        $extra_data = [];

        foreach ($attachment_data as $field_key => $field_value) {
            if (!in_array($field_key, array_keys(TABLES_DATA['myshowcase_attachments']))) {
                $extra_data[$field_key] = $field_value;

                unset($attachment_data[$field_key]);
            }
        }

        if (!empty($extra_data)) {
            $attachment_data['extra_data'] = $extra_data;
        }

        if (!$this->attachments->contains($attachment_data)) {
            if (!empty($attachment_data['attachment_id'])) {
                $this->attachments[$attachment_data['attachment_id']] = new Attachment(...$attachment_data);
            } else {
                $this->attachments[] = new Attachment(...$attachment_data);
            }
        }
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
                    "set_id='{$this->config['field_set_id']}'",
                    "field_id='{$this->field_definition['field_id']}'"
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
                if ($field_data_id) {
                    $this->selectable_values[$field_data_id] = $field_data_data;
                } else {
                    $this->selectable_values[] = $field_data_data;
                }
            }
        }
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function attachmentsBuild(Render $render_manager): void
    {
        $render_manager->attachments_type = $render_manager::ATTACHMENT_TYPE_FIELD;

        $this->attachments_rendered = $render_manager->buildAttachments($this->entry, $this->getAttachments());
    }
}