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
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\RenderManager;

interface FieldsInterface
{
    public function isViewableInEntry(): bool;

    public function isViewableInForm(): bool;

    public function setExtraData(string $name, string|bool|int|array|null $value): self;

    public function getExtraData(string $name): string|bool|int|array|null;

    public function valueSet(string|int|bool $userValue): self;

    public function valuesBuild(
        AttachmentsManager $attachments_manager,
        AttachmentsRepository $attachments_repository,
        EntryEntity $entry,
        int $collection_id,
        array $input = []
    ): void;

    public function attachmentsFetch(
        AttachmentsManager $attachments_manager,
        AttachmentsRepository $attachments_repository,
        EntryEntity $entry
    ): void;

    public function getUserValue(): string;

    public function getUserValueDisplay(): string;

    public function getFieldDescription(): string;

    public function fieldAcceptsMultipleValues(): bool;

    public function getAttachments(): Collection;

    public function addAttachment(array $attachment_data): void;

    public function attachmentsBuild(RenderManager $render_manager, EntryEntity $entry): void;

    public function getSelectableValues(): Collection;

    public function fetchSelectableValues(int $collection_id, bool $verify_fill_permission = false): void;

    public function hasOptions(): bool;

    public function getLabel(): string;
}