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

use MyShowcase\System\FieldHtmlTypes;
use MyShowcase\System\Render;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;

trait FieldTrait
{
    private string $user_value = '';

    private string $user_value_display = '';

    private array $attachments = [];

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

    public function valueSet(string|int $userValue): FieldsInterface
    {
        $this->entryFieldValue = $userValue;

        return $this;
    }

    public function valuesBuild(): FieldsInterface
    {
        if (!$this->fieldDefinition['display_in_view_page'] ||
            !is_member($this->fieldDefinition['allowed_groups_view'])) {
            return $this;
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
                                    "set_id='{$this->showcaseObject->config['field_set_id']}'",
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

                if ($this->fieldDefinition['parse'] || $this->showcaseObject->renderObject->keywords_highlight) {
                    $userValues[$userValueKey] = $this->showcaseObject->parseMessage(
                        $userValues[$userValueKey] ?? '',
                        $this->showcaseObject->parserOptions
                    );

                    $user_value_display[$userValueKey] = $this->showcaseObject->parseMessage(
                        $user_value_display[$userValueKey] ?? '',
                        $this->showcaseObject->parserOptions
                    );
                }
            }

            $this->user_value = implode($this->multipleConcatenator, $userValues);

            $this->user_value_display = implode($this->multipleConcatenator, $user_value_display);
        }

        if (!$this->user_value && $this->showcaseObject->config['display_empty_fields']) {
            global $lang;

            $this->user_value = $lang->myShowcaseEntryFieldValueEmpty;
        }

        if (!$this->user_value_display && $this->showcaseObject->config['display_empty_fields']) {
            global $lang;

            $this->user_value_display = $lang->myShowcaseEntryFieldValueEmpty;
        }

        $this->attachmentsBuild();

        return $this;
    }

    public function attachmentsBuild(): self
    {
        $this->attachments = $this->showcaseObject->attachmentsGetFromField($this->fieldDefinition['field_id']);

        return $this;
    }

    public function attachmentsGet(): array
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

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function getAttachments(array &$entryFields): string
    {
        return $this->showcaseObject->renderObject->entryBuildAttachments(
            $this->attachments,
            $entryFields,
            Render::POST_TYPE_ENTRY_FIELD
        );
    }

    public function getFieldHeader(): string
    {
        global $lang;

        return $this->fieldDefinition['field_label'] ?? ($lang->{'myshowcase_fieldset_field_' . $this->fieldDefinition['field_key']} ?? '');
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

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function renderEntry(): string
    {
        if (!$this->fieldDefinition['display_in_view_page'] || !is_member(
                $this->fieldDefinition['allowed_groups_view']
            )) {
            return '';
        }

        $userValue = $this->getUserValue();

        if ($userValue) {
            $fieldHeader = $this->getFieldHeader();

            if ($this->fieldAcceptsMultipleValues()) {
                $userValues = explode($this->multipleSeparator, $userValue);
            } else {
                $userValues = [$userValue];
            }

            foreach ($userValues as &$userValue) {
                if ($this->fieldDefinition['parse'] || $this->showcaseObject->renderObject->keywords_highlight) {
                    $userValue = $this->showcaseObject->parseMessage(
                        $userValue,
                        $this->showcaseObject->parserOptions
                    );
                } else {
                    $userValue = htmlspecialchars_uni($userValue);
                }
            }

            $userValues = implode($this->multipleConcatenator, $userValues);

            return $userValue;
            return $this->showcaseObject->renderObject->templateGet(
                $this->templatePrefixEntry . $this->templateName,
                [
                    'field_data.field_label' => $fieldHeader,
                    'entryFieldValue' => $this->getUserValue(),
                    'userValue' => $userValue,
                    'userValues' => $userValues,
                ]
            );
        } elseif ($this->showcaseObject->config['display_empty_fields']) {
            global $lang;

            return $lang->myShowcaseEntryFieldValueEmpty;
        }

        return '';
    }
}