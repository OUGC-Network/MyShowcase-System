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

use MyBB;
use MyShowcase\System\FieldHtmlTypes;

use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;

use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;

trait MultipleFieldTrait
{
    public function renderCreateUpdate(string $alternativeBackground, int $fieldTabIndex): string
    {
        global $mybb;

        $userValue = $this->getUserValue();

        $fieldHeader = $this->getFieldHeader();

        $fieldDescription = $this->getFieldDescription();

        $templatesContext = [
            'fieldData' => $this->fieldData,
            'fieldTabIndex' => $fieldTabIndex,
            'inputName' => $this->fieldData['field_key'] . '[]',
        ];

        if ($this->fieldAcceptsMultipleValues()) {
            $fieldItems = explode($this->multipleSeparator, $userValue);
        } else {
            $fieldItems = [$userValue];
        }

        $inputValues = $mybb->get_input($this->fieldData['field_key'], MyBB::INPUT_ARRAY);

        $templatesContext['requiredElement'] = '';

        if ($this->fieldData['is_required']) {
            $templatesContext['requiredElement'] = 'required="required"';
        }

        $fieldID = (int)$this->fieldData['field_id'];

        switch ($this->fieldData['html_type']) {
            case FieldHtmlTypes::CheckBox:
            case FieldHtmlTypes::Radio:
            case FieldHtmlTypes::Select:
                $fieldDataObjects = fieldDataGet(
                    ["field_id='{$fieldID}'"],
                    ['value'],
                    ['order_by' => 'display_order', 'allowed_groups_fill']
                );
        }

        if (empty($fieldDataObjects)) {
            return '';
        }

        $fieldItems = [];

        foreach ($fieldDataObjects as $fieldDataID => $fieldDataData) {
            if (!empty($fieldDataData['allowed_groups_fill']) && !is_member($fieldDataData['allowed_groups_fill'])) {
                continue;
            }

            $templatesContext['valueIdentifier'] = $fieldDataID;

            $templatesContext['valueName'] = htmlspecialchars_uni($fieldDataData['value']);

            $templatesContext['checkedElement'] = $templatesContext['selectedElement'] = '';

            if (!empty($inputValues[$this->fieldData['field_key']])) {
                $templatesContext['checkedElement'] = 'checked="checked"';

                $templatesContext['selectedElement'] = 'selected="selected"';
            }

            // todo, check box can be required per check box

            $fieldItems[] = eval(
            $this->showcaseObject->renderObject->templateGet(
                $this->templatePrefixCreateUpdate . $this->templateName . 'Item'
            )
            );
        }

        /*
        foreach ($fieldItems as &$userValue) {
            if ($this->fieldData['parse'] || $this->showcaseObject->renderObject->highlightTerms) {
                $userValue = $this->showcaseObject->parseMessage(
                    $userValue,
                    $this->showcaseObject->parserOptions
                );
            } else {
                $userValue = htmlspecialchars_uni($userValue);
            }

            $templatesContext['valueIdentifier'] = 1;

            $templatesContext['valueName'] = htmlspecialchars_uni($this->fieldData['field_key']);

            $checkedElement = $templatesContext['selectedElement'] = '';

            if (!empty($inputValues[$this->fieldData['field_key']])) {
                $checkedElement = 'checked="checked"';

                $templatesContext['selectedElement'] = 'selected="selected"';
            }

            // todo, check box can be required per check box

            $userValue = eval(
            $this->showcaseObject->renderObject->templateGet(
                $this->templatePrefixCreateUpdate . $this->templateName . 'Item'
            )
            );
        }*/

        $fieldItems = implode('', $fieldItems);

        $templatesContext['fieldPlaceholder'] = htmlspecialchars_uni($this->fieldData['placeholder']);

        $fieldItems = $this->showcaseObject->renderObject->templateGetTwig(
            $this->templatePrefixCreateUpdate . $this->templateName,
            $templatesContext
        );

        $templatesContext['acceptElement'] = '';

        return $this->showcaseObject->renderObject->templateGetTwig(
            $this->templatePrefixCreateUpdate,
            $templatesContext
        );
    }
}