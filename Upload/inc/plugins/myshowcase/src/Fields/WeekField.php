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
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\Entry;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Showcase;

class WeekField implements FieldsInterface
{
    use FieldTrait;
    use SingleFieldTrait;

    public function __construct(
        public readonly MyBB $mybb,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly EntriesManager $entries_manager,
        private readonly AttachmentsManager $attachments_manager,
        private readonly array $config,
        public Showcase $showcaseObject,
        public readonly array $field_definition,
        private readonly Entry $entry,
        protected string $entryFieldValue = '',
        public string $multipleSeparator = ',',
        public string $multipleConcatenator = ', ',
    ) {
        $this->attachments = new Collection();

        $this->selectable_values = new Collection();
    }
}