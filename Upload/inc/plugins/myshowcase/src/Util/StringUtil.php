<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use Symfony\Component\String\AbstractUnicodeString;
use Symfony\Component\String\Slugger\AsciiSlugger;

use function MyShowcase\Plugin\Functions\postParser;

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

    /**
     * Counts a message's characters removing special code.
     * Based off Zinga Burga's "Thread Tooltip Preview" plugin threadtooltip_getpreview() function.
     *
     * @param string $message Message to count.
     * @return string Characters count
     */
    public function stripCodes(string $message): string
    {
        // Attempt to remove any quotes
        $message = preg_replace_callback(array(
            '#\[quote=([\"\']|&quot;|)(.*?)(?:\\1)(.*?)(?:[\"\']|&quot;)?\](.*?)\[/quote\](\r\n?|\n?)#si',
            '#\[quote\](.*?)\[\/quote\](\r\n?|\n?)#si',
            '#\[quote\]#si',
            '#\[\/quote\]#si'
        ), function ($matches) {
            return '';
        }, $message);

        // Attempt to remove any MyCode
        $message = postParser()->parse_message($message, array(
            'allow_html' => false,
            'allow_mycode' => true,
            'allow_smilies' => false,
            'allow_imgcode' => true,
            'filter_badwords' => true,
            'nl2br' => false
        ));

        // before stripping tags, try converting some into spaces
        $message = preg_replace(array(
            '~\<(?:img|hr).*?/\>~si',
            '~\<li\>(.*?)\</li\>~si'
        ), array(' ', "\n* $1"), $message);

        $message = unhtmlentities(strip_tags($message));

        // Remove all spaces?
        $message = trim_blank_chrs($message);
        $message = preg_replace('/\s+/', '', $message);

        // convert \xA0 to spaces (reverse &nbsp;)
        $message = trim(
            preg_replace(array('~ {2,}~', "~\n{2,}~"),
                array(' ', "\n"),
                strtr($message, array("\xA0" => ' ', "\r" => '', "\t" => ' ')))
        );

        // newline fix for browsers which don't support them
        return preg_replace("~ ?\n ?~", " \n", $message);
    }
}