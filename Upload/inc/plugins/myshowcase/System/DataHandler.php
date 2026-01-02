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

declare(strict_types=1);

namespace MyShowcase\System;

use DataHandler as CoreDataHandler;

use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\attachmentUpdate;
use function MyShowcase\Plugin\Functions\cleanSlug;
use function MyShowcase\Plugin\Functions\commentInsert;
use function MyShowcase\Plugin\Functions\commentsGet;
use function MyShowcase\Plugin\Functions\commentUpdate;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\entryGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;
use function MyShowcase\Plugin\Functions\slugGenerateComment;
use function MyShowcase\Plugin\Functions\slugGenerateEntry;

use const MyShowcase\Plugin\Core\COMMENT_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_SOFT_DELETED;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_VISIBLE;
use const MyShowcase\Plugin\Core\DATA_HANDLER_METHOD_INSERT;
use const MyShowcase\Plugin\Core\DATA_HANDLER_METHOD_UPDATE;
use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_SOFT_DELETED;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_VISIBLE;

/**
 * MyShowcase handling class, provides common structure to handle post data.
 *
 */
class DataHandler extends CoreDataHandler
{
    public function __construct(
        protected Showcase &$showcaseObject,
        public $method = DATA_HANDLER_METHOD_INSERT,
        /**
         * The language file used in the data handler.
         *
         * @var string
         */
        public string $language_file = 'datahandler_myshowcase',
        /**
         * The prefix for the language variables used in the data handler.
         *
         * @var string
         */
        public $language_prefix = 'myshowcase_datahandler',
        /**
         * What are we performing?
         * new = New showcase entry
         * edit = Editing an entry
         */
        public string $action = '',
        /**
         * Array of data inserted in to a showcase.
         *
         * @var array
         */
        public array $insertData = [],
        /**
         * Array of data used to update a showcase.
         *
         * @var array
         */
        public array $updateData = [],
        public int $entry_id = 0,
        public int $comment_id = 0,
        public array $returnData = [],
    ) {
        $hookArguments = [
            'dataHandler' => &$this,
        ];

        parent::__construct($method);

        $hookArguments = hooksRun('data_handler_construct_end', $hookArguments);
    }

    /**
     * Sets the data to be used for the data handler
     *
     * @param array $data The data.
     * @return void
     */
    public function dataSet(array $data): void
    {
        $hookArguments = [
            'dataHandler' => &$this,
        ];

        $this->data = $data;

        $hookArguments = hooksRun('data_handler_data_set_end', $hookArguments);
    }

    /**
     * Validate a showcase.
     *
     * @return bool True when valid, false when invalid.
     */
    public function entryValidate(): bool
    {
        $hookArguments = [
            'dataHandler' => &$this,
        ];

        $hookArguments = hooksRun('data_handler_entry_validate_start', $hookArguments);

        $entry_data = &$this->data;

        if (isset($entry_data['entry_id']) && (int)$entry_data['entry_id'] !== $this->showcaseObject->entry_id) {
            $this->set_error('invalid_entry');
        }

        if (isset($entry_data['entry_slug_custom'])) {
            $slugLength = my_strlen($this->data['entry_slug_custom']);

            if ($slugLength < 1) {
                $this->set_error('custom_slug_too_short', 1);
            }

            if ($slugLength > DATA_TABLE_STRUCTURE['myshowcase_data']['entry_slug_custom']['size']) {
                $this->set_error(
                    'custom_slug_too_long',
                    DATA_TABLE_STRUCTURE['myshowcase_data']['entry_slug_custom']['size']
                );
            }
        }

        if (!empty($entry_data['user_id']) && empty(get_user($this->data['user_id'])['uid'])) {
            $this->set_error('invalid_user');
        }

        if (isset($entry_data['views']) && $entry_data['views'] < 0) {
            $this->set_error('invalid_views');
        }

        if (isset($entry_data['comments']) && $entry_data['comments'] < 0) {
            $this->set_error('invalid_comments');
        }

        if (isset($entry_data['status']) && !in_array(
                $this->data['status'],
                [
                    ENTRY_STATUS_PENDING_APPROVAL,
                    ENTRY_STATUS_VISIBLE,
                    ENTRY_STATUS_SOFT_DELETED
                ]
            )) {
            $this->set_error('invalid_status');
        }

        if (!empty($entry_data['edit_user_id']) && empty(get_user($this->data['edit_user_id'])['uid'])) {
            $this->set_error('invalid_edit_user');
        }

        if (isset($entry_data['dateline']) && $entry_data['dateline'] < 0) {
            $this->set_error('invalid_dateline');
        }

        if (isset($entry_data['edit_stamp']) && $entry_data['edit_stamp'] < 0) {
            $this->set_error('invalid_edit_stamp');
        }

        global $lang;

        foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
            if (!$fieldDefinition['enabled'] ||
                $fieldDefinition['read_only'] ||
                !isset($entry_data[$fieldDefinition['field_key']]) ||
                !is_member($fieldDefinition['allowed_groups_fill'])) {
                // todo, send user to data handler to check instead of current user
                continue;
            }

            if ($fieldDefinition['regular_expression'] &&
                preg_match($fieldDefinition['regular_expression'], '') !== false &&
                preg_match($fieldDefinition['regular_expression'], $entry_data[$fieldDefinition['field_key']]) === 1) {
                $this->set_error(
                    'invalid_value',
                    [$lang->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                );
            }

            if ($fieldDefinition['is_required'] && empty($entry_data[$fieldDefinition['field_key']])) {
                if ($fieldDefinition['html_type'] === FieldHtmlTypes::File) {
                    $entry_attachments = $this->showcaseObject->attachmentsGetFromField($fieldID);

                    if (empty($entry_attachments)) {
                        $this->set_error(
                            'required_field',
                            [$lang->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                        );
                    }
                } else {
                    $this->set_error(
                        'required_field',
                        [$lang->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                    );
                }
            }

            if (empty($entry_data[$fieldDefinition['field_key']]) && $fieldDefinition['default_value'] !== '') {
                $entry_data[$fieldDefinition['field_key']] = $fieldDefinition['default_value'];
            }

            if (fieldTypeMatchInt($fieldDefinition['field_type'])) {
                if (is_array($entry_data[$fieldDefinition['field_key']])) {
                    foreach ($entry_data[$fieldDefinition['field_key']] as $fieldValue) {
                        if (!is_numeric($fieldValue)) {
                            $this->set_error(
                                'invalid_value',
                                [$lang->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                            );
                        }
                    }
                } elseif (!is_numeric($entry_data[$fieldDefinition['field_key']])) {
                    $this->set_error(
                        'invalid_value',
                        [$lang->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                    );
                }
            } elseif (fieldTypeMatchChar($fieldDefinition['field_type']) ||
                fieldTypeMatchText($fieldDefinition['field_type'])) {
                if (!is_scalar($entry_data[$fieldDefinition['field_key']])) {
                    $this->set_error(
                        'invalid_value',
                        [$lang->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label']]
                    );
                }

                if ($fieldDefinition['html_type'] !== FieldHtmlTypes::Date) {
                    if ($fieldDefinition['minimum_length'] &&
                        my_strlen($entry_data[$fieldDefinition['field_key']]) < $fieldDefinition['minimum_length']) {
                        $this->set_error(
                            'invalid_length_minimum',
                            [
                                $lang->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label'],
                                my_strlen($entry_data[$fieldDefinition['field_key']]),
                                my_number_format($fieldDefinition['minimum_length']),
                                my_number_format($fieldDefinition['maximum_length']),
                            ]
                        );
                    }

                    if ($fieldDefinition['maximum_length'] &&
                        my_strlen($entry_data[$fieldDefinition['field_key']]) > $fieldDefinition['maximum_length']) {
                        $this->set_error(
                            'invalid_length_maximum',
                            [
                                $lang->{'myshowcase_fieldset_field_' . $fieldDefinition['field_key']} ?? $fieldDefinition['field_label'],
                                my_strlen($entry_data[$fieldDefinition['field_key']]),
                                my_number_format($fieldDefinition['minimum_length']),
                                my_number_format($fieldDefinition['maximum_length']),
                            ]
                        );
                    }
                }
            }
        }

        foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
            if (!$fieldDefinition['enabled'] ||
                !isset($entry_data[$fieldDefinition['field_key']]) ||
                !is_member($fieldDefinition['allowed_groups_fill'])) {
                continue;
            }

            if ($fieldDefinition['filter_on_save']) {
                $entry_data[$fieldDefinition['field_key']] = match ($fieldDefinition['filter_on_save']) {
                    FilterTypes::UUID => generateUUIDv4(),
                    default => $entry_data[$fieldDefinition['field_key']],
                };
            }
        }

        // it doesn't matter if this changes, only the entry_slug should remain the same always
        if ($this->method !== DATA_HANDLER_METHOD_UPDATE) {
            $entry_slug_custom = [];

            foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
                if (!$fieldDefinition['enable_slug']) {
                    continue;
                }

                if (is_array($entry_data[$fieldDefinition['field_key']])) {
                    foreach ($entry_data[$fieldDefinition['field_key']] as $field_data_id) {
                        $field_data_id = (int)$field_data_id;

                        if (!isset($this->showcaseObject->fieldSetCache[$field_data_id])) {
                            continue;
                        }

                        foreach (
                            fieldDataGet(
                                [
                                    "field_data_id='{$field_data_id}'"
                                ],
                                ['value', 'allowed_groups_fill'],
                                ['order_by' => 'display_order']
                            ) as $field_data_id => $field_data_data
                        ) {
                            if (is_member($field_data_data['allowed_groups_fill'])) {
                                $entry_slug_custom[] = $field_data_data['value'];
                            }
                        }
                    }
                } elseif (is_member($fieldDefinition['allowed_groups_fill'])) {
                    $entry_slug_custom[] = $entry_data[$fieldDefinition['field_key']];
                }
            }

            $entry_slug_custom = cleanSlug(implode('-', $entry_slug_custom));

            $entry_data['entry_slug_custom'] = $entry_slug_custom;
        }

        $this->set_validated();

        $hookArguments = hooksRun('data_handler_entry_validate_end', $hookArguments);

        if ($this->get_errors()) {
            return false;
        }

        return true;
    }

    /**
     * Insert a showcase into the database.
     *
     * @return array Array of new showcase details, entry_id and visibility.
     */
    public function entryInsert(bool $isUpdate = false): array
    {
        global $db;

        $hookArguments = [
            'dataHandler' => &$this,
            'isUpdate' => &$isUpdate,
        ];

        $entryData = &$this->data;

        if (!$this->get_validated()) {
            die('The entry needs to be validated before inserting it into the DB.');
        }

        if (count($this->get_errors()) > 0) {
            die('The entry is not valid.');
        }

        foreach ($entryData as $key => $value) {
            $this->insertData[$key] = $value;
        }

        $hookArguments = hooksRun('data_handler_entry_insert_update_start', $hookArguments);

        if (!isset($this->insertData['entry_slug']) && $this->method === 'insert') {
            $this->insertData['entry_slug'] = slugGenerateEntry($this->showcaseObject->showcase_id);
        }

        if ($isUpdate) {
            $this->entry_id = $this->showcaseObject->dataUpdate($this->insertData);
        } else {
            $this->entry_id = $this->showcaseObject->dataInsert($this->insertData);
        }

        // Assign any uploaded attachments with the specific post_hash to the newly created entry.
        if (!empty($this->insertData['post_hash'])) {
            foreach (
                attachmentGet(
                    ["post_hash='{$db->escape_string($this->insertData['post_hash'])}'"]
                ) as $attachmentID => $attachmentData
            ) {
                attachmentUpdate(['entry_id' => $this->entry_id], $attachmentID);
            }

            $this->returnData['post_hash'] = $this->insertData['post_hash'];
        }

        $this->returnData['entry_id'] = $this->entry_id;

        if (isset($this->insertData['entry_slug'])) {
            $this->returnData['entry_slug'] = $this->insertData['entry_slug'];
        } else {
            $this->returnData['entry_slug'] = $this->showcaseObject->dataGet(
                ["entry_id='{$this->entry_id}'"],
                ['entry_slug'],
                ['limit' => 1]
            )['entry_slug'];
        }

        if (isset($this->insertData['entry_slug_custom'])) {
            $this->returnData['entry_slug_custom'] = $this->insertData['entry_slug_custom'];
        } else {
            $this->returnData['entry_slug_custom'] = $this->showcaseObject->dataGet(
                ["entry_id='{$this->entry_id}'"],
                ['entry_slug_custom'],
                ['limit' => 1]
            )['entry_slug_custom'];
        }

        if (isset($this->insertData['status'])) {
            $this->returnData['status'] = $this->insertData['status'];
        }

        $hookArguments = hooksRun('data_handler_entry_insert_update_end', $hookArguments);

        return $this->returnData;
    }

    /**
     * Updates a showcase that is already in the database.
     *
     */
    public function updateEntry(): array
    {
        return $this->entryInsert(true);
    }

    public function commentValidate(): bool
    {
        $hookArguments = [
            'dataHandler' => &$this,
        ];

        $hookArguments = hooksRun('data_handler_comment_validate_start', $hookArguments);

        $commentData = $this->data;

        if (isset($commentData['showcase_id']) && (int)$commentData['showcase_id'] !== $this->showcaseObject->showcase_id) {
            $this->set_error('invalid showcase identifier');
        }

        if (isset($commentData['entry_id']) && (int)$commentData['entry_id'] !== $this->showcaseObject->entry_id) {
            $this->set_error('invalid entry identifier');
        }

        if (!empty($commentData['user_id']) && empty(get_user($this->data['user_id'])['uid'])) {
            $this->set_error('invalid user identifier');
        }

        if (isset($commentData['comment']) || $this->method === DATA_HANDLER_METHOD_INSERT) {
            if (getSetting('parserMyCodeAffectsLength')) {
                $commentLength = my_strlen($this->data['comment']);
            } else {
                $commentLength = my_strlen(postParser()->text_parse_message($this->data['comment']));
            }

            if ($commentLength < $this->showcaseObject->config['comments_minimum_length']) {
                $this->set_error('the message is too short');
            }

            if ($commentLength > $this->showcaseObject->config['comments_maximum_length']) {
                $this->set_error('the message is too large');
            }
        }

        if (isset($commentData['status']) && !in_array(
                $this->data['status'],
                [
                    COMMENT_STATUS_PENDING_APPROVAL,
                    COMMENT_STATUS_VISIBLE,
                    COMMENT_STATUS_SOFT_DELETED
                ]
            )) {
            $this->set_error('invalid status');
        }

        if (!empty($commentData['edit_user_id']) && empty(get_user($this->data['edit_user_id'])['uid'])) {
            $this->set_error('invalid edit user identifier');
        }

        $this->set_validated();

        $hookArguments = hooksRun('data_handler_comment_validate_end', $hookArguments);

        if ($this->get_errors()) {
            return false;
        }

        return true;
    }

    public function commentInsert(bool $isUpdate = false, int $commentID = 0): array
    {
        global $db;

        if (!$this->get_validated()) {
            die('The comment needs to be validated before inserting it into the DB.');
        }

        if (count($this->get_errors()) > 0) {
            die('The comment is not valid.');
        }

        $this->comment_id = $commentID;

        $hookArguments = [
            'dataHandler' => &$this,
            'isUpdate' => &$isUpdate,
        ];

        if ($isUpdate && !isset($this->returnData['comment_slug'])) {
            $this->returnData['comment_slug'] = commentsGet(
                ["comment_id='{$this->comment_id}'"],
                ['comment_slug'],
                ['limit' => 1]
            )['comment_slug'];
        } elseif (!$isUpdate) {
            $this->returnData['comment_slug'] = $this->data['comment_slug'] = slugGenerateComment();
        }

        foreach ($this->data as $key => $value) {
            $this->insertData[$key] = $value;
        }

        $hookArguments = hooksRun('data_handler_comment_insert_update_start', $hookArguments);

        if ($isUpdate) {
            commentUpdate($this->insertData, $this->comment_id);
        } else {
            $this->comment_id = commentInsert(
                array_merge($this->insertData, [
                    'showcase_id' => $this->showcaseObject->showcase_id,
                    'entry_id' => $this->showcaseObject->entry_id
                ])
            );
        }

        $this->returnData['comment_id'] = $this->comment_id;

        // Assign any uploaded attachments with the specific post_hash to the newly created comment.
        if (isset($this->data['post_hash'])) {
            foreach (
                attachmentGet(
                    ["post_hash='{$db->escape_string($this->data['post_hash'])}'"]
                ) as $attachmentID => $attachmentData
            ) {
                attachmentUpdate(['comment_id' => $this->comment_id, 'post_hash' => ''], $attachmentID);
            }
        }

        if (isset($this->data['status'])) {
            $this->returnData['status'] = $this->data['status'];
        }

        $hookArguments = hooksRun('data_handler_comment_insert_update_end', $hookArguments);

        return $this->returnData;
    }

    public function commentUpdate(int $commentID): array
    {
        return $this->commentInsert(true, $commentID);
    }
}