<?php

declare(strict_types=1);

namespace MyShowcase\Event\Listener;

use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\ShowcaseContext;

/** Deletes the files of a deleted entry, its comments' files included, with their thumbnails. */
readonly class EntryDeletedAttachmentListener
{
    public function __construct(
        private ShowcaseContext $context,
    ) {
    }

    public function __invoke(EntryDeletedEvent $event): void
    {
        // queryAttachments() yields rows without keys; read the ids first, then delete.
        $attachment_ids = array_map(
            static fn(array $attachment_data): int => (int)$attachment_data['attachment_id'],
            iterator_to_array(
                $this->context->attachments_repository->queryAttachments(["entry_id='{$event->getEntryID()}'"], 'attachment_id'),
                false
            )
        );

        foreach ($attachment_ids as $attachment_id) {
            $this->context->attachments_repository->deleteAttachment($attachment_id);
        }
    }
}
