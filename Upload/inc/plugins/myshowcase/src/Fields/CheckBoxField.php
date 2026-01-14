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
use MyShowcase\Entity\Entry;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Service\AttachmentsManager;

class CheckBoxField implements FieldsInterface
{
    use FieldTrait;
    use MultipleFieldTrait;

    public function __construct(
        private readonly AttachmentsRepository $attachments_repository,
        private readonly EntriesManager $entries_manager,
        private readonly AttachmentsManager $attachments_manager,
        private readonly int $fieldset_id,
        private readonly array $parser_options,
        private readonly string $search_highlight,
        public readonly array $fieldDefinition,
        private readonly Entry $entry_data,
        protected string $entryFieldValue = '',
        public string $multipleSeparator = ',',
        public string $multipleConcatenator = ', ',
    ) {
        $this->attachments = new Collection();

        $this->selectable_values = new Collection();
    }
}