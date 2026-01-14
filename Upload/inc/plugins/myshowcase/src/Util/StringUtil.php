<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use Symfony\Component\String\AbstractUnicodeString;
use Symfony\Component\String\Slugger\AsciiSlugger;

class StringUtil
{
    private ?AsciiSlugger $slugger = null;

    public function __construct()
    {
    }

    public function createSlug(string $string): AbstractUnicodeString
    {
        if ($this->slugger === null) {
            $this->slugger = new AsciiSlugger();
        }

        return $this->slugger->slug($string);
    }
}