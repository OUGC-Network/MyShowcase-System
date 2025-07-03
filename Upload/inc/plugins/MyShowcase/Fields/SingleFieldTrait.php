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

use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;

use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;

trait SingleFieldTrait
{
    public function renderCreateUpdate(string $alternativeBackground, int $fieldTabIndex): string
    {
        if (!$this->fieldData['display_in_create_update_page'] || !is_member($this->fieldData['allowed_groups_fill'])) {
            return '';
        }

        global $mybb, $lang;

        $templatesContext = [
            'fieldData' => $this->fieldData,
            'fieldTabIndex' => $fieldTabIndex,
            'inputName' => $this->fieldData['field_key'],
        ];

        $templatesContext['inputID'] = $this->fieldData['field_key'] . '_input';

        $userValue = htmlspecialchars_uni($mybb->get_input($this->fieldData['field_key']));

        $templatesContext['fieldHeader'] = $this->getFieldHeader();

        $templatesContext['fieldDescription'] = $this->getFieldDescription();

        $templatesContext['patternElement'] = '';

        if ($this->fieldData['regular_expression']) {
            $templatesContext['patternElement'] = 'pattern="' . $this->fieldData['regular_expression'] . '"';
        }

        $defaultValue = '';

        if ($this->fieldData['default_value']) {
            $defaultValue = strip_tags($this->fieldData['default_value']);
        }

        $templatesContext['requiredElement'] = '';

        if ($this->fieldData['is_required']) {
            $templatesContext['requiredElement'] = 'required="required"';
        }

        $templatesContext['editorCodeButtons'] = $templatesContext['editorSmilesInserter'] = '';

        if (in_array($this->fieldData['html_type'], [
            FieldHtmlTypes::Text,
            FieldHtmlTypes::Search,
            FieldHtmlTypes::TextArea,
        ])) {
            if ($this->fieldData['enable_editor']) {
                $this->showcaseObject->renderObject->buildEditor(
                    $templatesContext['editorCodeButtons'],
                    $templatesContext['editorSmilesInserter'],
                    $templatesContext['inputID']
                );
            }
        }

        $templatesContext['acceptElement'] = $templatesContext['captureElement'] = '';

        if ($this->fieldData['html_type'] === FieldHtmlTypes::File) {
            $attachmentTypes = cacheGet(CACHE_TYPE_ATTACHMENT_TYPES)[$this->showcaseObject->showcase_id] ?? [];

            $acceptElement = implode(
                ',',
                array_merge(
                    array_column($attachmentTypes, 'mime_type'),
                    array_column($attachmentTypes, 'file_extension')
                )
            );

            $templatesContext['acceptElement'] = " accept=\"{$acceptElement}\"";

            switch ($this->fieldData['file_capture']) {
                case 1:
                    $templatesContext['captureElement'] = 'capture="user"';

                    break;
                case 2:
                    $templatesContext['captureElement'] = 'capture="environment"';

                    break;
            }
        }

        $templatesContext['inputSize'] = 40;

        if ($this->fieldData['html_type'] === FieldHtmlTypes::Select) {
            $templatesContext['inputSize'] = 5;
        }

        $templatesContext['minimumLength'] = $templatesContext['maximumLength'] = '';

        if (in_array($this->fieldData['html_type'], [
            FieldHtmlTypes::Date,
            FieldHtmlTypes::Month,
            FieldHtmlTypes::Week,
            FieldHtmlTypes::Time,
            FieldHtmlTypes::DateTimeLocal,
            FieldHtmlTypes::Number,
            FieldHtmlTypes::Range,
        ])) {
            $templatesContext['minimumLength'] = (int)$this->fieldData['minimum_length'];

            $templatesContext['maximumLength'] = (int)$this->fieldData['maximum_length'];
        }

        $templatesContext['minimumValue'] = $templatesContext['maximumValue'] = '';

        if (in_array($this->fieldData['html_type'], [
            FieldHtmlTypes::Date,
            FieldHtmlTypes::Month,
            FieldHtmlTypes::Week,
            FieldHtmlTypes::Time,
            FieldHtmlTypes::DateTimeLocal,
            FieldHtmlTypes::Number,
            FieldHtmlTypes::Range,
        ])) {
            $templatesContext['minimumValue'] = (int)$this->fieldData['minimum_length'];

            $templatesContext['maximumValue'] = (int)$this->fieldData['maximum_length'];
        }

        $templatesContext['fieldPlaceholder'] = htmlspecialchars_uni($this->fieldData['placeholder']);

        $templatesContext['fieldItems'] = $this->showcaseObject->renderObject->templateGetTwig(
            $this->templatePrefixCreateUpdate . $this->templateName,
            $templatesContext,
        );

        return $this->showcaseObject->renderObject->templateGetTwig(
            $this->templatePrefixCreateUpdate,
            $templatesContext,
        );
    }
}