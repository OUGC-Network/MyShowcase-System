<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

class Comment
{
    public ?string $rendered_comment;

    public function __construct(
        public readonly int $comment_id,
        public readonly string $comment_slug,
        public readonly int $showcase_id,
        public readonly int $entry_id,
        public readonly int $parent_comment_id,
        public readonly int $user_id,
        public readonly string $post_hash,
        public readonly string $ipaddress,
        public readonly ?string $comment,
        public readonly int $dateline,
        public readonly int $status,
        public readonly int $edit_user_id,
    ) {
    }

    public function setRenderComment(string $rendered_comment): void
    {
        $this->rendered_comment = $rendered_comment;
    }
}