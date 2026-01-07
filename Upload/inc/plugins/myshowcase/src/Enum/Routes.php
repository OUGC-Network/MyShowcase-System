<?php

namespace MyShowcase\Enum;

enum Routes
{
    public const Main = 'main';
    public const Search = 'main_search';
    public const EntryCreate = 'entry_create';
    public const EntryCreateUpload = 'entry_create_upload';
    public const EntryView = 'entry_view';
    public const EntryUpdate = 'entry_update';
    public const EntryUpdateUpload = 'entry_update_upload';
    public const Comment = 'comment';
    public const CommentCreate = 'comment_create';
    public const CommentView = 'comment_view';
    public const CommentUpdate = 'comment_update';
    public const AttachmentViewFromPostHash = 'attachment_view_from_post_hash';
    public const AttachmentView = 'attachment_view';
    public const ThumbnailView = 'thumbnail_view';
}