<?php

/***************************************************************************
 *
 *    NewPoints plugin (/inc/plugins/newpoints/system/Url.php)
 *    Author: Pirata Nervo
 *    Copyright: © 2009 Pirata Nervo
 *    Copyright: © 2024 Omar Gonzalez
 *
 *    Website: https://ougc.network
 *
 *    NewPoints is a complex but efficient points system for MyBB.
 *
 ***************************************************************************
 ****************************************************************************
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 ****************************************************************************/

declare(strict_types=1);

namespace MyShowcase\Util;

readonly class UrlUtil
{
    public function __construct(private string $url)
    {
    }

    public function build(array $parameters = []): string
    {
        $parsed_url = parse_url($this->url);

        $existing_params = [];

        if (isset($parsed_url['query'])) {
            parse_str($parsed_url['query'], $existing_params);
        }

        return ($parsed_url['path'] ?? '') .
            '?' .
            http_build_query($existing_params + $parameters);
    }

    public function get(): string
    {
        return $this->url;
    }
}