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

interface FieldsInterface
{
    public function isViewableInEntry(): bool;

    public function valueSet(string|int $userValue): FieldsInterface;

    public function valuesBuild(): FieldsInterface;

    public function attachmentsBuild(): self;

    public function attachmentsGet(): array;

    public function getUserValue(): string;

    public function getUserValueDisplay(): string;

    public function getFieldHeader(): string;

    public function getFieldDescription(): string;

    public function fieldAcceptsMultipleValues(): bool;

    public function renderEntry(): string;

    public function renderCreateUpdate(string $alternativeBackground, int $fieldTabIndex);

    public function getAttachments(array &$entryFields): string;
}