<?php

/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase\src\Util\validationHelpers.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Util;

readonly class ValidationHelpers
{
    public function validateIpAddress(string $ipAddress, bool $rejectPrivateReserved = false): bool
    {
        return !empty($ipAddress) && filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            $rejectPrivateReserved ? FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE : 0
        );
    }
}

