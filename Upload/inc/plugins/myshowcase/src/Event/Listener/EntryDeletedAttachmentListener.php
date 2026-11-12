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
        $entry_id = (int)$event->getEntryID();

        // The ids are read first: the attachments are deleted while the list would still be open.
        $attachment_ids = [];

        foreach ($this->attachments_repository->queryAttachments(["entry_id='{$entry_id}'"]) as $attachment_data) {
            $attachment_ids[] = (int)$attachment_data['attachment_id'];
        }

        foreach ($attachment_ids as $attachment_id) {
            $this->attachments_repository->deleteAttachment($attachment_id);
        }
    }
}
