<?php

declare(strict_types=1);

namespace MyShowcase\Event\Listener;

use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\ShowcaseContext;

/** Deletes the comments of a deleted entry. */
readonly class EntryDeletedCommentListener
{
    public function __construct(
        private ShowcaseContext $context,
    ) {
    }

    public function __invoke(EntryDeletedEvent $event): void
    {
        $comment_ids = array_map(
            static fn(array $comment_data): int => (int)$comment_data['comment_id'],
            iterator_to_array(
                $this->context->comments_repository->queryComments(
                    ["comment_data.entry_id='{$event->getEntryID()}'"],
                    'comment_data.comment_id'
                ),
                false
            )
        );

        foreach ($comment_ids as $comment_id) {
            $this->context->comments_repository->deleteComment($comment_id);
        }
    }
}
