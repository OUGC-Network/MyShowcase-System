<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use DateTime;
use MyShowcase\Enum\Routes;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\ShowcaseContext;
use Random\RandomException;
use Symfony\Component\String\AbstractUnicodeString;
use Symfony\Component\String\Slugger\AsciiSlugger;

use function MyBB\View\template;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

readonly class QuotesUtil
{
    public function __construct(protected ?ShowcaseContext $context = null)
    {
    }

    /**
     * Parses quote MyCode.
     *
     * @param string $message The message to be parsed
     * @return string The parsed message.
     */
    public function mycode_parse_quotes(string $message): string
    {
        $pattern_callback = '/\[quote\b(?:\s+username=["\']([^"\']+)["\']|\s+slug=["\']([^"\']+)["\']|\s+datetime=["\']([^"\']*)["\'])*\s*](.+?)\[\/quote](\r\n?|\n?)/si';

        do {
            $previous_message = $message;

            $message = preg_replace_callback($pattern_callback, function (array $matches) {
                $username = $matches[1];
                $slug = $matches[2];
                $datetime = $matches[3];
                $message = $matches[4];

                $quote = [
                    'username' => htmlspecialchars_uni($username),
                    'linkback' => false,
                    'date' => '',
                    'message' => trim(
                        preg_replace(
                            '#(^<br(\s?)(\/?)>|<br(\s?)(\/?)>$)#i',
                            '',
                            $message
                        )
                    ),
                ];

                if (!$quote['message']) {
                    return '';
                }

                $delete_quote = true;

                if (!empty($slug)) {
                    $quote['url'] = $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                            Routes::CommentView,
                            [
                                'comment_public_id' => $slug,
                            ],
                        );

                    $quote['linkback'] = true;

                    $delete_quote = false;
                }

                if (!empty($datetime)) {
                    $datetime = new DateTime($datetime);

                    $dateline = $datetime->getTimestamp();

                    if ($dateline < TIME_NOW) {
                        $quote['date'] = my_date('relative', $dateline);
                    }

                    $delete_quote = false;
                }

                return template('@parser/mycodes/quote.twig', [
                    'quote' => $quote,
                    'delete_quote' => $delete_quote
                ]);
            }, $message, -1, $count_callback);

            if (!$message) {
                $message = $previous_message;
                break;
            }
        } while ($count_callback);

        return $message;
    }
}