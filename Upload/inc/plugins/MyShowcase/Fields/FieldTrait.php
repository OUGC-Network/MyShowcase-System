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

trait FieldTrait
{
    public function setUserValue(string|int $userValue): FieldsInterface
    {
        $this->entryFieldValue = $userValue;

        return $this;
    }

    public function getUserValue(): string|int
    {
        return $this->entryFieldValue ?? '';
    }

    public function getFieldHeader(): string
    {
        global $lang;

        return $this->fieldData['field_label'] ?? ($lang->{'myshowcase_field_' . $this->fieldData['field_key']} ?? '');
    }

    public function getFieldDescription(): string
    {
        global $lang;

        $fieldDescription = $this->fieldData['description'] ?? ($lang->{'myshowcase_field_' . $this->fieldData['field_key'] . 'Description'} ?? '');

        if ($fieldDescription) {
            if ($this->fieldData['allow_multiple_values']) {
                // todo, add description for comma/line multiple separator
            }

            $fieldDescription = $this->showcaseObject->renderObject->templateGetTwig(
                $this->templatePrefixCreateUpdate . 'Description',
                [
                    'fieldDescription' => &$fieldDescription,
                ]
            );
        }

        return $fieldDescription;
    }

    public function fieldAcceptsMultipleValues(): bool
    {
        return /*\MyShowcase\Plugin\Core\fieldTypeMatchText($this->fieldData['field_type']) &&*/ $this->fieldData['allow_multiple_values'];
    }

    public function renderMain(): string
    {
        if (!$this->fieldData['display_in_main_page'] || !is_member($this->fieldData['allowed_groups_view'])) {
            return '';
        }

        _dump('123');
    }

    public function renderEntry(): string
    {
        if (!$this->fieldData['display_in_view_page'] || !is_member($this->fieldData['allowed_groups_view'])) {
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
                if ($this->fieldData['parse'] || $this->showcaseObject->renderObject->highlightTerms) {
                    $userValue = $this->showcaseObject->parseMessage(
                        $userValue,
                        $this->showcaseObject->parserOptions
                    );
                } else {
                    $userValue = htmlspecialchars_uni($userValue);
                }

                $userValue = eval(
                $this->showcaseObject->renderObject->templateGet(
                    $this->templatePrefixEntry . $this->templateName . 'Value'
                )
                );
            }

            $userValues = implode($this->multipleConcatenator, $userValues);

            return $this->showcaseObject->renderObject->templateGetTwig(
                $this->templatePrefixEntry . $this->templateName,
                [
                    'fieldHeader' => $fieldHeader,
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