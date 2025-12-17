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
        return '';
        _dump($this->templatePrefixCreateUpdate);
        if (!$this->fieldDefinition['display_in_create_update_page'] || !is_member(
                $this->fieldDefinition['allowed_groups_fill']
            )) {
            return '';
        }

        global $mybb, $lang;

        $templatesContext = [
            'fieldDefinition' => $this->fieldDefinition,
            'fieldTabIndex' => $fieldTabIndex,
            'inputName' => $this->fieldDefinition['field_key'],
        ];

        $templatesContext['inputID'] = $this->fieldDefinition['field_key'] . '_input';

        $userValue = htmlspecialchars_uni($mybb->get_input($this->fieldDefinition['field_key']));

        $templatesContext['fieldHeader'] = $this->getFieldHeader();

        $templatesContext['fieldDescription'] = $this->getFieldDescription();

        $templatesContext['patternElement'] = '';

        if ($this->fieldDefinition['regular_expression']) {
            $templatesContext['patternElement'] = 'pattern="' . $this->fieldDefinition['regular_expression'] . '"';
        }

        $defaultValue = '';

        if ($this->fieldDefinition['default_value']) {
            $defaultValue = strip_tags($this->fieldDefinition['default_value']);
        }

        $templatesContext['requiredElement'] = '';

        if ($this->fieldDefinition['is_required']) {
            $templatesContext['requiredElement'] = 'required="required"';
        }

        $templatesContext['editorCodeButtons'] = $templatesContext['editorSmilesInserter'] = '';

        if (in_array($this->fieldDefinition['html_type'], [
            FieldHtmlTypes::Text,
            //FieldHtmlTypes::Search,
            FieldHtmlTypes::TextArea,
        ])) {
            if ($this->fieldDefinition['enable_editor']) {
                $this->showcaseObject->renderObject->buildEditor(
                    $templatesContext['editorCodeButtons'],
                    $templatesContext['editorSmilesInserter'],
                    $templatesContext['inputID']
                );
            }
        }

        $templatesContext['acceptElement'] = $templatesContext['captureElement'] = '';

        /*if ($this->fieldDefinition['html_type'] === FieldHtmlTypes::File) {
            $attachmentTypes = cacheGet(CACHE_TYPE_ATTACHMENT_TYPES)[$this->showcaseObject->showcase_id] ?? [];

            $acceptElement = implode(
                ',',
                array_merge(
                    array_column($attachmentTypes, 'mime_type'),
                    array_column($attachmentTypes, 'file_extension')
                )
            );

            $templatesContext['acceptElement'] = " accept=\"{$acceptElement}\"";

            switch ($this->fieldDefinition['file_capture']) {
                case 1:
                    $templatesContext['captureElement'] = 'capture="user"';

                    break;
                case 2:
                    $templatesContext['captureElement'] = 'capture="environment"';

                    break;
            }
        }*/

        $templatesContext['inputSize'] = 40;

        if ($this->fieldDefinition['html_type'] === FieldHtmlTypes::Select) {
            $templatesContext['inputSize'] = 5;
        }

        $templatesContext['minimumLength'] = $templatesContext['maximumLength'] = '';

        if (in_array($this->fieldDefinition['html_type'], [
            //FieldHtmlTypes::Date,
            //FieldHtmlTypes::Month,
            //FieldHtmlTypes::Week,
            //FieldHtmlTypes::Time,
            //FieldHtmlTypes::DateTimeLocal,
            //FieldHtmlTypes::Number,
            //FieldHtmlTypes::Range,
        ])) {
            $templatesContext['minimumLength'] = (int)$this->fieldDefinition['minimum_length'];

            $templatesContext['maximumLength'] = (int)$this->fieldDefinition['maximum_length'];
        }

        $templatesContext['minimumValue'] = $templatesContext['maximumValue'] = '';

        if (in_array($this->fieldDefinition['html_type'], [
            //FieldHtmlTypes::Date,
            //FieldHtmlTypes::Month,
            //FieldHtmlTypes::Week,
            //FieldHtmlTypes::Time,
            //FieldHtmlTypes::DateTimeLocal,
            //FieldHtmlTypes::Number,
            //FieldHtmlTypes::Range,
        ])) {
            $templatesContext['minimumValue'] = (int)$this->fieldDefinition['minimum_length'];

            $templatesContext['maximumValue'] = (int)$this->fieldDefinition['maximum_length'];
        }

        $templatesContext['fieldPlaceholder'] = htmlspecialchars_uni($this->fieldDefinition['placeholder']);

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