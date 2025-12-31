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

use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\fieldTypeMatchText;

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
                if ($this->fieldDefinition['parse'] || $this->showcaseObject->renderObject->highlightTerms) {
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