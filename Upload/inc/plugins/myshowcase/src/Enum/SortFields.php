<?php

namespace MyShowcase\Enum;

enum SortFields
{
    public const Dateline = 'dateline';
    public const Username = 'username';
    public const UserId = 'user_id';
    public const Views = 'views';
    public const CommentsApproved = 'comments_approved';
    public const LastComment = 'last_comment';
    public const CommentParentIdentifier = 'parent_comment_id';
}