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
use function MyShowcase\Plugin\Functions\commentInsert;
use function MyShowcase\Plugin\Functions\commentsGet;
use function MyShowcase\Plugin\Functions\commentUpdate;
use function MyShowcase\Plugin\Functions\fieldGetObject;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\entryGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;

use function MyShowcase\Plugin\Functions\postParser;
use function MyShowcase\Plugin\Functions\slugGenerateComment;

use const MyShowcase\Plugin\Core\COMMENT_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_SOFT_DELETED;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_VISIBLE;
use const MyShowcase\Plugin\Core\DATA_HANDLER_METHOD_INSERT;
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
        public $language_prefix = 'myshowcasedata',
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

        $entryData = $this->data;

        if (isset($entryData['entry_id']) && (int)$entryData['entry_id'] !== $this->showcaseObject->entryID) {
            $this->set_error('invalid entry identifier');
        }

        if (isset($entryData['entry_slug_custom'])) {
            $slugLength = my_strlen($this->data['entry_slug_custom']);

            if ($slugLength < 1) {
                $this->set_error('the slug is too short');
            }

            if ($slugLength > DATA_TABLE_STRUCTURE['myshowcase_data']['entry_slug_custom']['size']) {
                $this->set_error('the slug is too large');
            }
        }

        if (!empty($entryData['user_id']) && empty(get_user($this->data['user_id'])['uid'])) {
            $this->set_error('invalid user identifier');
        }

        if (isset($entryData['views']) && $entryData['views'] < 0) {
            $this->set_error('invalid views count');
        }

        if (isset($entryData['comments']) && $entryData['comments'] < 0) {
            $this->set_error('invalid comments count');
        }

        if (isset($entryData['status']) && !in_array(
                $this->data['status'],
                [
                    ENTRY_STATUS_PENDING_APPROVAL,
                    ENTRY_STATUS_VISIBLE,
                    ENTRY_STATUS_SOFT_DELETED
                ]
            )) {
            $this->set_error('invalid status');
        }

        if (!empty($entryData['edit_user_id']) && empty(get_user($this->data['edit_user_id'])['uid'])) {
            $this->set_error('invalid edit user identifier');
        }

        if (isset($entryData['dateline']) && $entryData['dateline'] < 0) {
            $this->set_error('invalid create stamp');
        }

        if (isset($entryData['edit_stamp']) && $entryData['edit_stamp'] < 0) {
            $this->set_error('invalid edit stamp');
        }

        global $lang;

        foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldData) {
            $fieldObject = fieldGetObject($this->showcaseObject, $fieldData);

            $fieldKey = $fieldData['field_key'];

            if (!$fieldData['enabled'] ||
                !isset($entryData[$fieldKey]) ||
                !is_member($fieldData['allowed_groups_fill'])) {
                // todo, send user to data handler to check instead of current user
                continue;
            }

            if ($fieldData['is_required'] && empty($entryData[$fieldKey])) {
                $this->set_error('missing_field', [$lang->{'myshowcase_field_' . $fieldKey} ?? $fieldKey]);
            }
            /*
             * array(1) {
  [0]=>
  array(32) {
    ["html_type"]=>
    string(8) "checkbox"
    ["field_type"]=>
    string(7) "tinyint"
    ["allow_multiple_values"]=>
    int(1)
    ["regular_expression"]=>
    string(7) "varchar"
    ["display_in_create_update_page"]=>
    int(1)
    ["display_in_view_page"]=>
    int(1)
    ["display_in_main_page"]=>
    int(1)
    ["minimum_length"]=>
    int(0)
    ["maximum_length"]=>
    int(1)
    ["step_size"]=>
    int(0)
  }
}

             */

            if (empty($entryData[$fieldKey]) && $fieldData['default_value'] !== '') {
                $entryData[$fieldKey] = $fieldData['default_value'];
            }

            _dump($fieldObject);

            if (fieldTypeMatchInt($fieldData['field_type'])) {
                if (!is_numeric($entryData[$fieldKey])) {
                    $this->set_error('invalid_type', [$lang->{'myshowcase_field_' . $fieldKey} ?? $fieldKey]);
                }
            } elseif (fieldTypeMatchChar($fieldData['field_type']) ||
                fieldTypeMatchText($fieldData['field_type'])) {
                if (!is_scalar($entryData[$fieldKey])) {
                    $this->set_error('invalid_type', [$lang->{'myshowcase_field_' . $fieldKey} ?? $fieldKey]);
                }

                if ($fieldData['html_type'] !== FieldHtmlTypes::Date) {
                    $fieldValueLength = my_strlen($entryData[$fieldKey]);

                    if ($fieldValueLength > $fieldData['maximum_length'] ||
                        $fieldValueLength < $fieldData['minimum_length']) {
                        $this->set_error(
                            'invalid_length',
                            [
                                $lang->{'myshowcase_field_' . $fieldKey} ?? $fieldKey,
                                $fieldValueLength,
                                $fieldData['minimum_length'] . '-' . $fieldData['maximum_length']
                            ]
                        );

                        if ($fieldValueLength > $this->showcaseObject->config['maximum_text_field_length'] &&
                            $this->showcaseObject->config['maximum_text_field_length'] > 0) {
                            $this->set_error(
                                'message_too_long',
                                [
                                    $lang->{'myshowcase_field_' . $fieldKey} ?? $fieldKey,
                                    $fieldValueLength,
                                    $this->showcaseObject->config['maximum_text_field_length']
                                ]
                            );
                        }
                    }
                }
            }

            if ($fieldData['filter_on_save']) {
                _dump('filter_on_save', $fieldData['filter_on_save']);
            }
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

        if (!$isUpdate && empty($this->insertData['entry_hash'])) {
            $this->insertData['entry_hash'] = generateUUIDv4();
        }

        $hookArguments = hooksRun('data_handler_entry_insert_update_start', $hookArguments);

        if ($isUpdate) {
            $this->entry_id = $this->showcaseObject->dataUpdate($this->insertData);

            if (!isset($this->insertData['entry_hash'])) {
                $commentData = entryGet(
                    $this->showcaseObject->showcase_id,
                    ["entry_id='{$this->entry_id}'"],
                    ['entry_hash']
                );

                $this->returnData['entry_hash'] = $commentData['entry_hash'];
            }
        } else {
            $this->entry_id = $this->showcaseObject->dataInsert($this->insertData);

            $this->returnData['entry_hash'] = $this->insertData['entry_hash'];
        }

        // Assign any uploaded attachments with the specific entry_hash to the newly created entry.
        foreach (
            attachmentGet(
                ["entry_hash='{$db->escape_string($this->returnData['entry_hash'])}'"]
            ) as $attachmentID => $attachmentData
        ) {
            attachmentUpdate(['entry_id' => $this->entry_id, 'entry_hash' => ''], $attachmentID);
        }

        $this->returnData['entry_id'] = $this->entry_id;

        if (isset($this->insertData['entry_slug'])) {
            $this->returnData['entry_slug'] = $entryData['entry_slug'];
        } else {
            $this->returnData['entry_slug'] = $this->showcaseObject->dataGet(
                ["entry_id='{$this->entry_id}'"],
                ['entry_slug'],
                ['limit' => 1]
            )['entry_slug'];
        }

        if (isset($this->insertData['status'])) {
            $this->returnData['status'] = $entryData['status'];
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

        if (isset($commentData['entry_id']) && (int)$commentData['entry_id'] !== $this->showcaseObject->entryID) {
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
                    'entry_id' => $this->showcaseObject->entryID
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