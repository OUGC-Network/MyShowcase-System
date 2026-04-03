<?php

namespace MyShowcase\Enum;

enum Routes
{
    public const Main = 'main';
    public const EntryCreate = 'entry_create';
    public const EntryCreateUploadApi = 'entry_create_upload_api';
    public const EntryUpdate = 'entry_update';
    public const EntryUpdateApi = 'entry_update_api';
    public const EntryView = 'entry_view';
    public const EntryViewApi = 'entry_view_api';
    public const EntryUpdateUploadApi = 'entry_update_upload_api';
    public const CommentCreate = 'comment_create';
    public const CommentUpdate = 'comment_update';
    public const CommentUpdateApi = 'comment_update_api';
    public const CommentView = 'comment_view';
    public const AttachmentDownload = 'attachment_download';
    public const AttachmentThumbnail = 'thumbnail_thumbnail';
    public const Search = 'search';
    public const SearchResults = 'search_results';
}