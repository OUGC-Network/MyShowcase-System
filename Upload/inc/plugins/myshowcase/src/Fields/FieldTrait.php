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
use MyShowcase\Entity\AttachmentEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Enum\ContentTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\FormatTypes;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\RenderManager;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\stringKeysOnly;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\formatField;
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

    public function isViewableInMain(): bool
    {
        if (empty($this->field_definition['is_enabled'])) {
            return false;
        }

        if (empty($this->field_definition['display_in_main_page'])) {
            return false;
        }

        return (bool)is_member($this->field_definition['allowed_groups_view'] ?? '');
    }

    public function isViewableInEntry(): bool
    {
        if (empty($this->field_definition['is_enabled'])) {
            return false;
        }

        if (empty($this->field_definition['display_in_view_page'])) {
            return false;
        }

        return (bool)is_member($this->field_definition['allowed_groups_view'] ?? '');
    }

    public function isViewableInForm(): bool
    {
        if (empty($this->field_definition['is_enabled'])) {
            return false;
        }

        if (empty($this->field_definition['display_in_create_update_page'])) {
            return false;
        }

        return (bool)is_member($this->field_definition['allowed_groups_fill'] ?? '');
    }

    public function valueSet(string|int|bool $userValue): self
    {
        $this->field_value = (string)$userValue;

        return $this;
    }

    /** The raw value: what is stored, or what was submitted (an option id for option fields). */
    public function getValue(): string
    {
        return $this->field_value;
    }

    /** Whether the option is the value of the field (for checked/selected in the form). */
    public function isSelected(int|string $field_data_id): bool
    {
        return $this->field_value !== '' && in_array((string)$field_data_id, $this->valuesList(), true);
    }

    /** Templates read the field settings as properties (field_data.field_key), as they do on CustomFieldEntity. */
    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->field_definition);
    }

    public function __get(string $name): mixed
    {
        return $this->field_definition[$name] ?? null;
    }

    public function valuesBuild(
        AttachmentsManager $attachments_manager,
        AttachmentsRepository $attachments_repository,
        EntryEntity $entry,
        int $collection_id,
        array $input = []
    ): void {
        $this->user_value = $this->user_value_display = '';

        if ($this->field_value !== '') {
            $user_values = $user_values_display = [];

            $highlight = !empty($this->field_definition['enable_search']) && isset($input['highlight']) &&
            is_string($input['highlight']) ? $input['highlight'] : null;

            foreach ($this->valuesList() as $value) {
                if ($this->hasOptions()) {
                    // An option that no longer exists (or that the user may not see) shows nothing.
                    $option = $this->viewableOptions()[(int)$value] ?? null;

                    if ($option === null) {
                        continue;
                    }

                    $label = htmlspecialchars_uni((string)$option['value']);

                    $user_values[] = $label;

                    $user_values_display[] = (string)$option['display_style'] !== '' ? (string)$option['display_style'] : $label;

                    continue;
                }

                if (!empty($this->field_definition['parser_enable'])) {
                    $text = postParser()->parse_message($value, [
                        'allow_html' => !empty($this->field_definition['parser_allow_html']),
                        'allow_mycode' => !empty($this->field_definition['parser_allow_mycode']),
                        'allow_smilies' => !empty($this->field_definition['parser_allow_smiles']),
                        'allow_imgcode' => !empty($this->field_definition['parser_allow_image_code']),
                        'allow_videocode' => !empty($this->field_definition['parser_allow_video_code']),
                        'me_username' => (string)$entry->username,
                        'filter_badwords' => true,
                        'shorten_urls' => false,
                        'highlight' => $highlight ?? false,
                    ]);
                } else {
                    $format = (int)($this->field_definition['format'] ?? FormatTypes::noFormat);

                    $text = formatField($format, $value);

                    if ($format !== FormatTypes::htmlSpecialCharactersUni) {
                        $text = htmlspecialchars_uni($text);
                    }

                    if ($highlight !== null) {
                        $text = postParser()->highlight_message($text, $highlight);
                    }
                }

                $user_values[] = $text;

                $user_values_display[] = $text;
            }

            $this->user_value = implode($this->multipleConcatenator, $user_values);

            $this->user_value_display = implode($this->multipleConcatenator, $user_values_display);
        }

        if ($this->field_definition['html_type'] === FieldHtmlTypes::File) {
            $this->attachmentsFetch($attachments_manager, $attachments_repository, $entry);
        }
    }

    /** @return string[] The stored value split into its values (one value unless the field accepts several). */
    private function valuesList(): array
    {
        if ($this->fieldAcceptsMultipleValues()) {
            return array_values(array_filter(
                array_map('trim', explode($this->multipleSeparator, $this->field_value)),
                static fn(string $value): bool => $value !== ''
            ));
        }

        return [$this->field_value];
    }

    /** Whether the values of the field are ids of its options (myshowcase_field_data rows). */
    public function hasOptions(): bool
    {
        return in_array(
            $this->field_definition['html_type'],
            [FieldHtmlTypes::CheckBox, FieldHtmlTypes::Radio, FieldHtmlTypes::Select],
            true
        );
    }

    /**
     * Every option of the field, by field_data_id in display order, read once per request.
     *
     * @return array<int, array>
     */
    private function optionRows(): array
    {
        static $options_cache = [];

        $field_id = (int)$this->field_definition['field_id'];

        if (!isset($options_cache[$field_id])) {
            $options_cache[$field_id] = [];

            foreach (
                fieldDataGet(
                    ["field_id='{$field_id}'"],
                    ['field_data_id', 'value_id', 'value', 'display_style', 'allowed_groups_fill', 'allowed_groups_view'],
                    ['order_by' => 'display_order, field_data_id']
                ) as $field_data_id => $field_data_data
            ) {
                $options_cache[$field_id][(int)$field_data_id] = $field_data_data;
            }
        }

        return $options_cache[$field_id];
    }

    /** @return array<int, array> The options the current user may see. */
    private function viewableOptions(): array
    {
        return array_filter(
            $this->optionRows(),
            static fn(array $option): bool => (bool)is_member((string)$option['allowed_groups_view'])
        );
    }

    /** The label of the field: the collection language entry when there is one, else the stored label, else its key. */
    public function getLabel(): string
    {
        global $lang;

        $field_key = (string)$this->field_definition['field_key'];

        $language_key = 'myshowcase_collection_field_' . $field_key;

        if (isset($lang->$language_key) && $lang->$language_key !== '' && $lang->$language_key !== $language_key) {
            return (string)$lang->$language_key;
        }

        if (($this->field_definition['field_label'] ?? '') !== '') {
            return (string)$this->field_definition['field_label'];
        }

        return $field_key;
    }

    public function attachmentsFetch(
        AttachmentsManager $attachments_manager,
        AttachmentsRepository $attachments_repository,
        EntryEntity $entry
    ): void {
        $where_clauses = ["field_id='{$this->field_definition['field_id']}'"];

        if (!empty($entry->entry_id)) {
            $where_clauses[] = "entry_id='{$entry->entry_id}'";
        } else {
            global $db;

            $where_clauses[] = "post_hash='{$db->escape_string($entry->post_hash)}'";
        }

        foreach (
            $attachments_repository->queryAttachments(
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
                    'update_dateline',
                ],
            ) as $attachment_data
        ) {
            $attachments_manager->addAttachment($attachment_data);
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

        $fieldDescription = $this->field_definition['description'] ?? ($lang->{'myshowcase_collection_field_' . $this->field_definition['field_key'] . '_description'} ?? '');

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

        return '';
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
                $this->attachments[$attachment_data['attachment_id']] = new AttachmentEntity(...stringKeysOnly($attachment_data));
            } else {
                $this->attachments[] = new AttachmentEntity(...stringKeysOnly($attachment_data));
            }
        }
    }

    public function getSelectableValues(): Collection
    {
        return $this->selectable_values;
    }

    public function fetchSelectableValues(int $collection_id, bool $verify_fill_permission = false): void
    {
        foreach ($this->optionRows() as $field_data_id => $field_data_data) {
            if ($verify_fill_permission && !is_member((string)$field_data_data['allowed_groups_fill'])) {
                continue;
            }

            $this->selectable_values[$field_data_id] = $field_data_data;
        }
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function attachmentsBuild(RenderManager $render_manager, EntryEntity $entry): void
    {
        $render_manager->attachments_type = ContentTypes::CustomField;

        $this->attachments_rendered = $render_manager->buildAttachments($entry, $this->getAttachments());
    }
}