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

use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;

use const MyShowcase\Plugin\Core\TABLES_DATA;

trait FieldTrait
{
    private Collection $attachments;

    private string $user_value = '';

    private string $user_value_display = '';

    public function isViewableInEntry(): bool
    {
        if (empty($this->fieldDefinition['enabled'])) {
            return false;
        }

        if (empty($this->fieldDefinition['display_in_view_page'])) {
            return false;
        }

        return (bool)is_member($this->fieldDefinition['allowed_groups_view']);
    }

    public function valueSet(string|int|bool $userValue): self
    {
        $this->entryFieldValue = $userValue;

        return $this;
    }

    public function valuesBuild(Entry $entry_data): void
    {
        if (!$this->fieldDefinition['display_in_view_page'] ||
            !is_member($this->fieldDefinition['allowed_groups_view'])) {
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
                switch ($this->fieldDefinition['html_type']) {
                    case FieldHtmlTypes::CheckBox:
                    case FieldHtmlTypes::Radio:
                    case FieldHtmlTypes::Select:
                        $value_id = (int)$userValueValue;

                        foreach (
                            fieldDataGet(
                                [
                                    "field_data_id='{$value_id}'",
                                    "set_id='{$this->fieldset_id}'",
                                    "field_id='{$this->fieldDefinition['field_id']}'"
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
                                $userValues[$userValueKey] = '';

                                continue;
                            }

                            $userValues[$userValueKey] = $field_data_data['value'];

                            $user_value_display[$userValueKey] = $field_data_data['display_style'] ?? $field_data_data['value'];
                        }

                        break;
                }

                if ($this->fieldDefinition['parse'] || $this->search_highlight) {
                    $userValues[$userValueKey] = $this->entries_manager->parseMessage(
                        $userValues[$userValueKey] ?? '',
                        $this->parser_options
                    );

                    $user_value_display[$userValueKey] = $this->entries_manager->parseMessage(
                        $user_value_display[$userValueKey] ?? '',
                        $this->parser_options
                    );
                }
            }

            $this->user_value = implode($this->multipleConcatenator, $userValues);

            $this->user_value_display = implode($this->multipleConcatenator, $user_value_display);
        }

        if (!$this->user_value && $this->fieldset_id) {
            global $lang;

            $this->user_value = $lang->myShowcaseEntryFieldValueEmpty;
        }

        if (!$this->user_value_display && $this->fieldset_id) {
            global $lang;

            $this->user_value_display = $lang->myShowcaseEntryFieldValueEmpty;
        }

        $this->attachmentsFetch($entry_data);
    }

    public function attachmentsFetch(Entry $entry_data): void
    {
        $where_clauses = ["field_id='{$this->fieldDefinition['field_id']}'"];

        if (!empty($entry_data->entry_id)) {
            $where_clauses[] = "entry_id='{$entry_data->entry_id}'";
        } else {
            global $db;

            $where_clauses[] = "post_hash='{$db->escape_string($entry_data->post_hash)}'";
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

    public function attachmentsGet(): Collection
    {
        return $this->attachments;
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

        $fieldDescription = $this->fieldDefinition['description'] ?? ($lang->{'myshowcase_fieldset_field_' . $this->fieldDefinition['field_key'] . '_description'} ?? '');

        if ($fieldDescription) {
            if ($this->fieldDefinition['allow_multiple_values']) {
                // todo, add description for comma/line multiple separator
            }
        }

        return $fieldDescription;
    }

    public function fieldAcceptsMultipleValues(): bool
    {
        return false;
        // todo, allow only html compatible fields
        return fieldTypeMatchText($this->fieldDefinition['field_type']) &&
            $this->fieldDefinition['allow_multiple_values'];
    }

    public function renderMain(): string
    {
        if (!$this->fieldDefinition['display_in_main_page'] || !is_member(
                $this->fieldDefinition['allowed_groups_view']
            )) {
            return '';
        }

        _dump('123');
    }

    public function getAttachments2(): Collection
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
}