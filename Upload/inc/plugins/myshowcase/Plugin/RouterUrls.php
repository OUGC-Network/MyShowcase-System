<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Plugin;

class RouterUrls
{
    public const Main = 'main';
    public const MainUnapproved = 'main_unapproved';
    public const Search = 'main_search';
    public const MainUser = 'main_user';
    public const MainPage = 'main_page';
    public const MainApprove = 'main_approve';
    public const MainUnapprove = 'main_unapprove';
    public const MainSoftDelete = 'main_soft_delete';
    public const MainRestore = 'main_restore';
    public const MainDelete = 'main_delete';
    public const EntryCreate = 'entry_create';
    public const EntryView = 'entry_view';
    public const EntryViewPage = 'entry_page';
    public const EntryUpdate = 'entry_update';
    public const EntryApprove = 'entry_approve';
    public const EntryUnapprove = 'entry_unapprove';
    public const EntrySoftDelete = 'entry_soft_delete';
    public const EntryRestore = 'entry_restore';
    public const EntryDelete = 'entry_delete';
    public const Comment = 'comment';
    public const CommentCreate = 'comment_create';
    public const CommentView = 'comment_view';
    public const CommentUpdate = 'comment_update';
    public const CommentApprove = 'comment_approve';
    public const CommentUnapprove = 'comment_unapprove';
    public const CommentSoftDelete = 'comment_soft_delete';
    public const CommentRestore = 'comment_restore';
    public const CommentDelete = 'comment_delete';
    public const AttachmentView = 'attachment_view';
    public const ThumbnailView = 'thumbnail_view';
}