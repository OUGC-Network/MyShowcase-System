<?php

/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase\src\Util\buildShowcase.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Util;

use MyShowcase\Container;
use MyShowcase\Showcase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

readonly class BuildShowcase
{
    public function getShowcase(?int $showcaseId = null, ?string $routeIdentifier = null): Showcase
    {
        $container = (new Container($showcaseId, $routeIdentifier))->container;

        /** @var ContainerBuilder $container */
        return $container->get('showcase');
    }
}