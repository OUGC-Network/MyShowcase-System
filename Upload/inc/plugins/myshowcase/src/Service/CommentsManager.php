<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Event\Listener\EntryDeletedCommentListener;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Entity\Comment;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;

class CommentsManager
{
    public const STATUS_PENDING = 0;

    public const STATUS_VISIBLE = 1;

    public const STATUS_DELETED = 2;

    private const  FIRST_PAGE = 1;

    private int $total_comments = 0;

    private int $total_pages = 1;

    private array $where_clauses = [];

    private array $query_fields = [
        'comment_id',
        'comment_slug',
        'showcase_id',
        'entry_id',
        'user_id',
        'parent_comment_id',
        'post_hash',
        'ipaddress',
        'comment',
        'dateline',
        'status',
        'edit_user_id',
    ];

    private array $query_options = ['order_by' => 'dateline', 'order_dir' => 'asc', 'limit_start' => 0];

    private array $comments = [];

    public function __construct(
        private readonly int $current_user_id,
        private readonly array $user_permissions,
        private readonly EntriesRepository $entries_repository,
        private readonly CommentsRepository $comments_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly EventDispatcher $event_dispatcher,
        private int $current_page = self::FIRST_PAGE,
        private readonly int $comments_per_page = 10,
    ) {
        $this->event_dispatcher->addListener(
            EntryDeletedEvent::NAME,
            new EntryDeletedCommentListener($comments_repository)
        );
    }

    public function setCurrentPage(int $page): void
    {
        if ($page > 0) {
            $this->current_page = $page;
        }
    }

    public function getTotalComments(): int
    {
        return $this->total_comments;
    }

    /**
     * @return iterable<Comment>
     */
    public function getTopLevelComments(): iterable
    {
        foreach ($this->comments as $comment_id => $comment_data) {
            if (empty($comment_data->parent_comment_id)) {
                yield $comment_data;
            }
        }
    }

    public function getCurrentPage(): int
    {
        return $this->current_page;
    }

    /**
     * @throws RandomException
     */
    public function slugGenerate(?int $length = null): string
    {
        if (empty($length)) {
            $length = getSetting('slugLength');
        }

        global $db;

        $generated_slug = '';

        $unique_found = false;

        while (!$unique_found) {
            $generated_slug = my_strtolower(bin2hex(random_bytes($length)));

            $unique_found = !$this->comments_repository->queryComment(
                ["comment_slug='{$db->escape_string($generated_slug)}'"]
            );
        }

        return $generated_slug;
    }

    public function fetchComments(int $entry_id): array
    {
        $this->where_clauses = [
            "entry_id='{$entry_id}'",
        ];

        if (!$this->user_permissions[ModeratorPermissions::CanManageEntries] &&
            !$this->user_permissions[ModeratorPermissions::CanManageComments]) {
            $this->where_clauses[] = "(status='" . self::STATUS_VISIBLE . "' OR (user_id='{$this->current_user_id}' AND status='" . self::STATUS_PENDING . "'))";
        }

        $this->query_options['limit'] = $this->comments_per_page;

        hooksRun('comment_fetch_start', $this);

        $this->total_comments = $this->comments_repository->queryCommentCount($this->where_clauses);

        $this->total_pages = (int)ceil($this->total_comments / $this->comments_per_page);

        if ($this->current_page > $this->total_pages) {
            $this->setCurrentPage(self::FIRST_PAGE);
        }

        if ($this->current_page) {
            $this->query_options['limit_start'] = ($this->current_page - 1) * $this->comments_per_page;
        } else {
            $this->setCurrentPage(self::FIRST_PAGE);
        }

        hooksRun('comment_fetch_intermediate', $this);

        $entry_objects = [];

        foreach (
            $this->comments_repository->queryComments(
                $this->where_clauses,
                $this->query_fields,
                $this->query_options
            ) as $comment_data
        ) {
            $entry_objects[] = $comment_data;
        }

        hooksRun('comment_fetch_end', $this);

        return $entry_objects;
    }
}