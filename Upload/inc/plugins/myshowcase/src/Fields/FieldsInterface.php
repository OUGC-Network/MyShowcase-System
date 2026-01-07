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

interface FieldsInterface
{
    public function isViewableInEntry(): bool;

    public function valueSet(string|int|bool $userValue): self;

    public function valuesBuild(Entry $entry_data): void;

    public function attachmentsFetch(Entry $entry_data): void;

    public function attachmentsGet(): Collection;

    public function getUserValue(): string;

    public function getUserValueDisplay(): string;

    public function getFieldDescription(): string;

    public function fieldAcceptsMultipleValues(): bool;
}