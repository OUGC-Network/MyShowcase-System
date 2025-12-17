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

//https://stackoverflow.com/a/4451376
class RangeHeader
{
    /**
     * Create a new instance from a Range header string
     *
     * @param string|null $header
     * @return RangeHeader|null
     */
    public static function createFromHeaderString(?string $header): ?RangeHeader
    {
        if ($header === null) {
            return null;
        }

        if (!preg_match('/^\s*(\S+)\s*(\d*)\s*-\s*(\d*)\s*(?:,|$)/', $header, $info)) {
            throw new ExceptionInvalidRangeHeader('Invalid header format');
        } elseif (strtolower($info[1]) !== 'bytes') {
            throw new ExceptionInvalidRangeHeader('Unknown range unit: ' . $info[1]);
        }

        return new self(
            $info[2] === '' ? null : $info[2],
            $info[3] === '' ? null : $info[3]
        );
    }

    /**
     * @param int|null $firstByte
     * @param int|null $lastByte
     * @throws ExceptionInvalidRangeHeader
     */
    public function __construct(
        /**
         * The first byte in the file to send (0-indexed), a null value indicates the last
         * $end bytes
         *
         * @var int|null
         */
        private readonly ?int $firstByte,

        /**
         * The last byte in the file to send (0-indexed), a null value indicates $start to
         * EOF
         *
         * @var int|null
         */
        private readonly ?int $lastByte,
    ) {
        if ($this->firstByte === null && $this->lastByte === null) {
            throw new ExceptionInvalidRangeHeader(
                'Both start and end position specifiers empty'
            );
        } elseif ($this->firstByte < 0 || $this->lastByte < 0) {
            throw new ExceptionInvalidRangeHeader(
                'Position specifiers cannot be negative'
            );
        } elseif ($this->lastByte !== null && $this->lastByte < $this->firstByte) {
            throw new ExceptionInvalidRangeHeader(
                'Last byte cannot be less than first byte'
            );
        }
    }

    /**
     * Get the start position when this range is applied to a file of the specified size
     *
     * @param int $fileSize
     * @return int|null
     */
    public function getStartPosition(int $fileSize): ?int
    {
        if ($this->firstByte === null) {
            return ($fileSize - 1) - $this->lastByte;
        }

        if ($fileSize <= $this->firstByte) {
            throw new ExceptionUnsatisfiableRange(
                'Start position is after the end of the file'
            );
        }

        return $this->firstByte;
    }

    /**
     * Get the end position when this range is applied to a file of the specified size
     *
     * @param int $fileSize
     * @return int|null
     */
    public function getEndPosition(int $fileSize): ?int
    {
        if ($this->lastByte === null) {
            return $fileSize - 1;
        }

        if ($fileSize <= $this->lastByte) {
            throw new ExceptionUnsatisfiableRange(
                'End position is after the end of the file'
            );
        }

        return $this->lastByte;
    }

    /**
     * Get the length when this range is applied to a file of the specified size
     *
     * @param int $fileSize
     * @return int|null
     */
    public function getLength(int $fileSize): ?int
    {
        return $this->getEndPosition($fileSize) - $this->getStartPosition($fileSize) + 1;
    }

    /**
     * Get a Content-Range header corresponding to this Range and the specified file
     * size
     *
     * @param int $fileSize
     * @return string
     */
    public function getContentRangeHeader(int $fileSize): string
    {
        return 'bytes ' . $this->getStartPosition($fileSize) . '-'
            . $this->getEndPosition($fileSize) . '/' . $fileSize;
    }

    /**
     * Get the value of a header in the current request context
     *
     * @param string $name Name of the header
     * @return string|null Returns null when the header was not sent or cannot be retrieved
     */
    public static function getRequestHeader(string $name): ?string
    {
        $name = strtoupper($name);

        // IIS/Some Apache versions and configurations
        if (isset($_SERVER['HTTP_' . $name])) {
            return trim($_SERVER['HTTP_' . $name]);
        }

        // Various other SAPIs
        foreach (apache_request_headers() as $header_name => $value) {
            if (strtoupper($header_name) === $name) {
                return trim($value);
            }
        }

        return null;
    }
}