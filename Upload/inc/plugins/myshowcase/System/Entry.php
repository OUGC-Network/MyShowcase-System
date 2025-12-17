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

namespace MyShowcase\System;

class Entry
{
    private int $entry_id;
    private int $user_id;
    private string $post_hash;

    private function __construct(int $entry_id, int $user_id, string $post_hash)
    {
        $this->entry_id = $entry_id;
        $this->user_id = $user_id;
        $this->post_hash = $post_hash;
    }

    public static function create(int $entry_id, int $user_id, string $post_hash): Entry
    {
        return new self($entry_id, $user_id, $post_hash);
    }

    public function entry_id(): int
    {
        return $this->entry_id;
    }

    public function user_id(): int
    {
        return $this->user_id;
    }

    public function post_hash(): string
    {
        return $this->post_hash;
    }
}