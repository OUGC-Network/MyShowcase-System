<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use DateTime;
use MyShowcase\Enum\FieldHtmlTypes;

/**
 * The bounds of a field definition: minimum_value and maximum_value are the HTML min and max of the number, range,
 * date, datetime-local, month, week and time inputs (decision D124). An empty bound is no bound.
 */
final class FieldBoundUtil
{
    /** The HTML types whose input has a min and a max. */
    public const BOUNDED_HTML_TYPES = [
        FieldHtmlTypes::Number,
        FieldHtmlTypes::Range,
        FieldHtmlTypes::Date,
        FieldHtmlTypes::DateTimeLocal,
        FieldHtmlTypes::Month,
        FieldHtmlTypes::Week,
        FieldHtmlTypes::Time,
    ];

    public static function isBoundedType(string $html_type): bool
    {
        return in_array($html_type, self::BOUNDED_HTML_TYPES, true);
    }

    /** The bounded types as a message names them: "number, range, ... and time". */
    public static function typeNames(): string
    {
        $names = self::BOUNDED_HTML_TYPES;
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    /** Whether a non-empty bound has the format its HTML type uses (a whole number for number and range). */
    public static function formatIsValid(string $html_type, string $value): bool
    {
        return match ($html_type) {
            FieldHtmlTypes::Number, FieldHtmlTypes::Range => preg_match('/^-?\d{1,10}$/', $value) === 1
                && (int)$value >= -2147483648 && (int)$value <= 2147483647,
            FieldHtmlTypes::Date => self::isCalendarDate($value),
            FieldHtmlTypes::DateTimeLocal => preg_match('/^(\d{4}-\d{2}-\d{2})[T ]([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value, $parts) === 1
                && self::isCalendarDate($parts[1]),
            FieldHtmlTypes::Month => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1,
            FieldHtmlTypes::Week => self::isIsoWeek($value),
            FieldHtmlTypes::Time => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) === 1,
            default => false,
        };
    }

    /**
     * A bound or a value in one comparable string. A time may leave out its seconds (HH:MM means HH:MM:00), and a
     * datetime-local may use a space for the T. The format must be checked first.
     */
    public static function comparableKey(string $html_type, string $value): string
    {
        $normalizeTime = static fn(string $time): string => strlen($time) === 5 ? $time . ':00' : $time;

        return match ($html_type) {
            FieldHtmlTypes::Time => $normalizeTime($value),
            FieldHtmlTypes::DateTimeLocal => (static function (string $datetime) use ($normalizeTime): string {
                [$date, $time] = preg_split('/[T ]/', $datetime, 2) + ['', '00:00:00'];

                return $date . 'T' . $normalizeTime($time);
            })($value),
            default => $value,
        };
    }

    /** Whether the minimum is above the maximum: integers for number and range, the comparable form for the others. */
    public static function isInverted(string $html_type, string $minimum, string $maximum): bool
    {
        if (in_array($html_type, [FieldHtmlTypes::Number, FieldHtmlTypes::Range], true)) {
            return (int)$minimum > (int)$maximum;
        }

        return self::comparableKey($html_type, $minimum) > self::comparableKey($html_type, $maximum);
    }

    private static function isCalendarDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
    }

    /** A week the calendar has: the ISO week number round-trips through its date (52 or 53 weeks a year). */
    private static function isIsoWeek(string $value): bool
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        $date = (new DateTime())->setISODate((int)$parts[1], (int)$parts[2]);

        return $date->format('o') === $parts[1] && $date->format('W') === $parts[2];
    }
}
