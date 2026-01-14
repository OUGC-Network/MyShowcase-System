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
use MyShowcase\Service\Render;

interface FieldsInterface
{
    public function isViewableInEntry(): bool;

    public function setExtraData(string $name, string|bool|int|array|null $value): self;

    public function getExtraData(string $name): string|bool|int|array|null;

    public function valueSet(string|int|bool $userValue): self;

    public function valuesBuild(Entry $entry_data): void;

    public function attachmentsFetch(Entry $entry_data): void;

    public function getUserValue(): string;

    public function getUserValueDisplay(): string;

    public function getFieldDescription(): string;

    public function fieldAcceptsMultipleValues(): bool;

    public function getAttachments(): Collection;

    public function addAttachment(array $attachment_data): void;

    public function attachmentsBuild(Render $render_manager): void;

    public function getSelectableValues(): Collection;

    public function fetchSelectableValues(bool $verify_fill_permission = false): void;
}