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
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Entity\ShowcaseEntity;

use function MyShowcase\Plugin\Functions\hooksRun;

/**
 * MyShowcase handling class, provides common structure to handle post data.
 *
 */
class DataHandler extends CoreDataHandler
{
    private int $entry_id = 0;

    public function __construct(
        private readonly int $showcase_id,
        private readonly CommentsRepository $comments_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly CommentsManager $comments_manager,
        private readonly ShowcaseEntity $config,
        public $method = self::METHOD_INSERT,
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
        public array $insert_data = [],
        /**
         * Array of data used to update a showcase.
         *
         * @var array
         */
        public array $update_data = [],
        public int $comment_id = 0,
        public array $return_data = [],
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
        $this->data = $data;
    }
}