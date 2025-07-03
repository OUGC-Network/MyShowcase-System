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

use MyShowcase\System\Showcase;

use function MyShowcase\Plugin\Functions\fieldTypeMatchText;

class SelectUsersField implements FieldsInterface
{
    use FieldTrait;
    use SingleFieldTrait;

    public function __construct(
        public Showcase $showcaseObject,
        protected array $fieldData,
        protected string $entryFieldValue = '',
        public string $templatePrefixEntry = 'pageViewDataField',
        public string $templatePrefixCreateUpdate = 'pageEntryCreateUpdateDataField',
        public string $templateName = 'SelectUsers',
        public string $multipleSeparator = ',',
        public string $multipleConcatenator = ', ',
    ) {
    }
}