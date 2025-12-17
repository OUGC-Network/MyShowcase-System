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
use InvalidArgumentException;

class PartialFileServlet
{
    /**
     * @param RangeHeader|null $range Range header on which the transmission will be based
     */
    public function __construct(
        /**
         * The range header on which the data transmission will be based
         *
         * @var RangeHeader|null
         */
        private readonly ?RangeHeader $range = null
    ) {
    }

    /**
     * Send part of the data in a seekable stream resource to the output buffer
     *
     * @param resource $fp Stream resource to read data from
     * @param int|null $start Position in the stream to start reading
     * @param int|null $length Number of bytes to read
     * @param int $chunkSize Maximum bytes to read from the file in a single operation
     */
    private function sendDataRange($fp, ?int $start, ?int $length, int $chunkSize = 8192): void
    {
        //https://stackoverflow.com/a/38430606
        if (is_resource($fp) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Argument must be a valid resource type. %s given.',
                    gettype($fp)
                )
            );
        }

        if ($start > 0) {
            fseek($fp, $start);
        }

        while ($length) {
            $read = ($length > $chunkSize) ? $chunkSize : $length;

            $length -= $read;

            echo fread($fp, $read);
        }
    }

    /**
     * Send the headers that are included regardless of whether a range was requested
     *
     * @param string $fileName
     * @param int $contentLength
     * @param string $contentType
     * @param string $disposition
     */
    private function sendDownloadHeaders(
        string $fileName,
        int $contentLength,
        string $contentType,
        string $disposition
    ): void {
        header('Content-Type: ' . $contentType);

        header('Content-Length: ' . $contentLength);

        header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');

        header('Accept-Ranges: bytes');
    }

    /**
     * Send data from a file based on the current Range header
     *
     * @param string $filePath Local file system path to serve
     * @param string $contentType MIME type of the data stream
     */
    public function sendFile(
        string $filePath,
        string $contentType = 'application/octet-stream',
        int $fileSize = 0,
        string $fileName = '',
        string $disposition = 'attachment'
    ): void {
        // Make sure the file exists and is a file, otherwise we are wasting our time
        $localPath = realpath($filePath);

        if ($localPath === false || !is_file($localPath)) {
            throw new ExceptionNonExistentFile(
                $filePath . ' does not exist or is not a file'
            );
        }

        // Make sure we can open the file for reading
        if (!$fp = fopen($localPath, 'r')) {
            throw new ExceptionUnreadableFile(
                'Failed to open ' . $localPath . ' for reading'
            );
        }

        if ($fileSize === 0) {
            $fileSize = filesize($localPath);
        }

        if ($fileName === '') {
            $fileName = basename($localPath);
        }

        if ($contentType === '') {
            $contentType = '';
        }

        if ($this->range == null) {
            // No range requested, just send the whole file
            header('HTTP/1.1 200 OK');

            $this->sendDownloadHeaders($fileName, $fileSize, $contentType, $disposition);

            fpassthru($fp);
        } else {
            // Send the request range
            header('HTTP/1.1 206 Partial Content');

            header('Content-Range: ' . $this->range->getContentRangeHeader($fileSize));

            $this->sendDownloadHeaders($fileName, $this->range->getLength($fileSize), $contentType, $disposition);

            $this->sendDataRange(
                $fp,
                $this->range->getStartPosition($fileSize),
                $this->range->getLength($fileSize)
            );
        }

        fclose($fp);
    }
}