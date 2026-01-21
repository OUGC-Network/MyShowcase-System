<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use MyShowcase\Repository\CommentsRepository;
use Random\RandomException;
use Symfony\Component\String\AbstractUnicodeString;
use Symfony\Component\String\Slugger\AsciiSlugger;

use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

class StringUtil
{
    private ?AsciiSlugger $slugger = null;

    public function __construct()
    {
    }

    public function generateSlug(string $string): AbstractUnicodeString
    {
        if ($this->slugger === null) {
            $this->slugger = new AsciiSlugger();
        }

        return $this->slugger->slug($string);
    }

    public function generateEntrySlug(string $string): string
    {
        $slug = $this->generateSlug($string)
            ->lower()
            ->toString();

        $maximum_length = DATA_TABLE_STRUCTURE['myshowcase_data']['entry_slug_custom']['size'];

        if (mb_strlen($slug) > $maximum_length) {
            $slug = my_substr($slug, 0, $maximum_length);

            $last_sep_position = mb_strrpos($slug, '-');

            if ($last_sep_position !== false) {
                $slug = mb_substr($slug, 0, $last_sep_position);
            }
        }

        return $slug;
    }

    /**
     * @throws RandomException
     */
    public function generateCommentSlug(?int $length = null): string
    {
        if (empty($length)) {
            $length = getSetting('slugLength');
        }

        return my_strtolower(bin2hex(random_bytes($length)));
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