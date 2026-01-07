<?php

declare(strict_types=1);

namespace MyShowcase\Event\Listener;

use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Repository\AttachmentsRepository;

readonly class EntryDeletedAttachmentListener
{
    public function __construct(
        private AttachmentsRepository $attachments_repository,
    ) {
    }

    public function __invoke(EntryDeletedEvent $event): void
    {
        $entry_id = $event->getEntryID();

        foreach (
            $this->attachments_repository->queryAttachments(["entry_id='{$entry_id}'"]
            ) as $attachmentID => $attachmentData
        ) {
            $this->attachments_repository->deleteAttachment($attachmentID);
        }
    }
}
