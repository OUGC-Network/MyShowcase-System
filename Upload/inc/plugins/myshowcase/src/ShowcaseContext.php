<?php

declare(strict_types=1);

namespace MyShowcase;

use datacache;
use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyBB;
use MyLanguage;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\UserEntity;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Repository\AccessLogsRepository;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\Repository\PermissionsRepository;
use MyShowcase\Repository\ShowcasesRepository;
use MyShowcase\Repository\SubscriptionsRepository;
use MyShowcase\Routing\Routing;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\CacheManager;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Service\RenderManager;
use MyShowcase\Service\UsersManager;
use MyShowcase\Entity\ShowcaseEntity;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

class ShowcaseContext
{
    public UserEntity $current_user;

    public function __construct(
        public readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        public readonly MyBB $mybb,
        public readonly datacache $cache,
        public readonly MyLanguage $language,
        public readonly ?Routing $routing = null,
        public readonly ?EventDispatcher $event_dispatcher = null,
        public readonly ?ShowcasesRepository $showcases_repository = null,
        public readonly ?AccessLogsRepository $access_logs_repository = null,
        public readonly ?ModeratorsRepository $moderators_repository = null,
        public readonly ?EntriesRepository $entries_repository = null,
        public readonly ?SubscriptionsRepository $subscriptions_repository = null,
        public readonly ?CommentsRepository $comments_repository = null,
        public readonly ?AttachmentsRepository $attachments_repository = null,
        public readonly ?PermissionsRepository $permissions_repository = null,
        public readonly ?CacheManager $cache_manager = null,
        public ?UsersManager $users_manager = null,
        public readonly ?EntriesManager $entries_manager = null,
        public readonly ?CommentsManager $comments_manager = null,
        public readonly ?AttachmentsManager $attachments_manager = null,
        public readonly ?RenderManager $render_manager = null,
        public ?ShowcaseEntity $showcase = null,
        public Collection $custom_fields_collection = new Collection(),
        public array $fieldset_fields = []
    ) {
    }
}