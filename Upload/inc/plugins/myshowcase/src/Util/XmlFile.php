<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use DOMDocument;
use PluginLibrary;

/**
 * A thin layer over PluginLibrary's xml_export() and xml_import(). PluginLibrary stays the XML engine; this class only
 * makes up for what it does not do:
 *
 * write(): removes the characters XML 1.0 cannot hold and counts them, writes "\r" as &#13; so it survives a parser's
 * line-end normalisation, and writes a null as the one-element array ['__null__' => true] (PluginLibrary drops a null).
 *
 * read(): refuses a DOCTYPE, checks the document is well formed before PluginLibrary sees it, keeps PluginLibrary's own
 * PHP 8 notices out of the way (only those, and only while it runs), turns its "Unknown" failure on a very large file
 * into a clear message, and restores the nulls.
 */
class XmlFile
{
    /** Key of the array that stands for a null value. */
    public const NULL_KEY = '__null__';

    /** Characters XML 1.0 allows: tab, line feed, carriage return and everything from space up except the specials. */
    private const INVALID_XML_CHARACTERS = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    /** Stands for "\r" between the data and PluginLibrary; free to use because every other control character is removed first. */
    private const CARRIAGE_RETURN_TOKEN = "\x01";

    public function __construct(private readonly PluginLibrary $library)
    {
    }

    /**
     * @param array<int|string, mixed> $data
     * @param int $removed_characters Set to how many characters were removed because XML cannot hold them.
     */
    public function write(
        array $data,
        string $comment = 'MyShowcase export :: {time}',
        string $end_comment = 'End of MyShowcase export.',
        int &$removed_characters = 0
    ): string {
        $removed_characters = 0;

        $xml = $this->library->xml_export($this->prepare($data, $removed_characters), false, $comment, $end_comment);

        // PluginLibrary writes a string as text or as CDATA, whichever is shorter. Inside CDATA a character reference
        // is plain text, so there the CDATA section is closed around it.
        $xml = preg_replace_callback(
            '/<!\[CDATA\[.*?\]\]>/s',
            static fn(array $match): string => str_replace(
                self::CARRIAGE_RETURN_TOKEN,
                ']]>&#13;<![CDATA[',
                $match[0]
            ),
            $xml
        );

        return str_replace(self::CARRIAGE_RETURN_TOKEN, '&#13;', $xml);
    }

    /**
     * @return array<int|string, mixed>
     * @throws XmlFileException
     */
    public function read(string $xml): array
    {
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }

        $this->rejectDoctype($xml);

        $this->checkWellFormed($xml);

        $error = null;

        $data = $this->callLibraryQuietly(fn() => $this->library->xml_import($xml, $error));

        if (!is_array($data)) {
            if (is_array($error) && ($error['line'] ?? -1) > 0) {
                throw new XmlFileException(
                    XmlFileException::REASON_SYNTAX,
                    'The XML could not be read: ' . ($error['message'] ?? 'unknown error'),
                    (int)$error['line'],
                    (string)($error['code'] ?? '')
                );
            }

            if (is_array($error) && ($error['line'] ?? 0) === -1) {
                throw new XmlFileException(
                    XmlFileException::REASON_NOT_EXPORT,
                    'The XML is well formed but is not a PluginLibrary export.'
                );
            }

            // The XML is well formed, yet PluginLibrary gave up without a position or a reason ("Unknown"). That is what
            // a document too large for its parser looks like (about 10 MB).
            throw new XmlFileException(
                XmlFileException::REASON_TOO_LARGE,
                'The XML is too large to be read (' . strlen($xml) . ' bytes).'
            );
        }

        return $this->restore($data);
    }

    /**
     * @throws XmlFileException
     */
    private function rejectDoctype(string $xml): void
    {
        // A DTD can only appear in the prolog, before the root element, so that is the only place looked at: the same
        // text inside the data (for example a message that contains "<!DOCTYPE html>" in a CDATA section) is just data.
        $position = 0;

        $length = strlen($xml);

        while ($position < $length) {
            $position += strspn($xml, " \t\r\n", $position);

            if (substr($xml, $position, 2) === '<?') {
                $end = strpos($xml, '?>', $position);

                $end_length = 2;
            } elseif (substr($xml, $position, 4) === '<!--') {
                $end = strpos($xml, '-->', $position);

                $end_length = 3;
            } elseif (strncasecmp(substr($xml, $position, 9), '<!DOCTYPE', 9) === 0) {
                throw new XmlFileException(
                    XmlFileException::REASON_DOCTYPE,
                    'The XML contains a DOCTYPE, which is not allowed.'
                );
            } else {
                return;
            }

            if ($end === false) {
                return;
            }

            $position = $end + $end_length;
        }
    }

    /**
     * @throws XmlFileException
     */
    private function checkWellFormed(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);

        libxml_clear_errors();

        $document = new DOMDocument();

        // No LIBXML_NOENT and no LIBXML_DTDLOAD: entities are not expanded and no DTD is loaded; NONET forbids the network.
        $loaded = $document->loadXML($xml, LIBXML_NONET);

        $errors = libxml_get_errors();

        libxml_clear_errors();

        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $first = $errors[0] ?? null;

            $lines = explode("\n", $xml);

            throw new XmlFileException(
                XmlFileException::REASON_SYNTAX,
                'The XML is not well formed' . ($first ? ': ' . trim($first->message) : '.'),
                $first->line ?? 0,
                trim($lines[($first->line ?? 1) - 1] ?? '')
            );
        }

        if ($document->documentElement === null || $document->documentElement->nodeName !== 'array') {
            throw new XmlFileException(
                XmlFileException::REASON_NOT_EXPORT,
                'The XML is well formed but is not a PluginLibrary export.'
            );
        }
    }

    /**
     * Runs $call with PluginLibrary's warnings, notices and deprecations ignored. Only those raised from PluginLibrary's
     * own file are ignored; everything else goes to the handler that was set before, and that handler is put back.
     */
    private function callLibraryQuietly(callable $call): mixed
    {
        $library_file = (new \ReflectionClass($this->library))->getFileName();

        $quiet_levels = E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED;

        $previous = set_error_handler(
            static function (int $level, string $message, string $file, int $line) use (&$previous, $library_file, $quiet_levels): bool {
                if ($file === $library_file && ($level & $quiet_levels)) {
                    return true;
                }

                // false lets PHP's normal handling continue (or the previous handler is called by PHP's chain).
                return $previous !== null ? (bool)$previous($level, $message, $file, $line) : false;
            }
        );

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function prepare(mixed $value, int &$removed_characters): mixed
    {
        if ($value === null) {
            return [self::NULL_KEY => true];
        }

        if (is_array($value)) {
            $prepared = [];

            foreach ($value as $key => $item) {
                $prepared[is_string($key) ? $this->cleanString($key, $removed_characters) : $key] = $this->prepare(
                    $item,
                    $removed_characters
                );
            }

            return $prepared;
        }

        if (is_string($value)) {
            return $this->cleanString($value, $removed_characters);
        }

        return $value;
    }

    private function cleanString(string $value, int &$removed_characters): string
    {
        $value = mb_scrub($value, 'UTF-8');

        $value = preg_replace(self::INVALID_XML_CHARACTERS, '', $value, -1, $count);

        $removed_characters += $count;

        return str_replace("\r", self::CARRIAGE_RETURN_TOKEN, $value);
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function restore(array $data): array
    {
        if (array_keys($data) === [self::NULL_KEY]) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = array_keys($value) === [self::NULL_KEY] ? null : $this->restore($value);
            }
        }

        return $data;
    }
}
