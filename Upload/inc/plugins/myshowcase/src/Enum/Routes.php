<?php

namespace MyShowcase\Enum;

enum Routes
{
    public const Main = 'main';
    public const EntryCreate = 'entry_create';
    public const EntryUpdate = 'entry_update';
    public const EntryView = 'entry_view';
    public const CommentCreate = 'comment_create';
    public const CommentUpdate = 'comment_update';
    public const CommentView = 'comment_view';
    public const AttachmentUpload = 'attachment_upload';
    public const AttachmentDownload = 'attachment_download';
    public const AttachmentThumbnail = 'thumbnail_thumbnail';
    public const Search = 'search';
    public const SearchResults = 'search_results';
}