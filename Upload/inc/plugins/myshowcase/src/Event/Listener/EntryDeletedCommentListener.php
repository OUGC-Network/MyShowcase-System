<?php

declare(strict_types=1);

namespace MyShowcase\Event\Listener;

use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Repository\CommentsRepository;

class EntryDeletedCommentListener
{
    public function __construct(
        private readonly CommentsRepository $comments_repository,
    ) {
    }

    public function __invoke(EntryDeletedEvent $event): void
    {
        $entry_id = $event->getEntryID();

        foreach (
            $this->comments_repository->queryComments(
                ["entry_id='{$entry_id}'"],
                'comment_id'
            ) as $comment_id => $comment_data
        ) {
            $this->comments_repository->deleteComment($comment_id);
        }
    }
}
