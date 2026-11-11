<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase\src\Service\ShowcaseService.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Entity\ShowcaseEntity;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Repository\ShowcasesRepository;
use MyShowcase\Service\JsonSync\JsonLock;

use function MyShowcase\Plugin\Functions\showcaseGet;

/** Validates and writes showcases (myshowcase_config). */
final class ShowcaseService
{
    public function __construct(private readonly CacheManager $cacheManager)
    {
    }

    /** The form of a template prefix the database holds: lower case letters and digits only. */
    public static function normalizeTemplatePrefix(string $prefix): string
    {
        return (string)preg_replace('/[^\da-z]/i', '', my_strtolower($prefix));
    }

    /**
     * Checks the values that must be unique among showcases. Only the keys present in $data are checked.
     *
     * @return array<int, array{key: string, message: string}> Language key (of the admin language file) and English
     *         text of every problem.
     */
    public function validate(array $data, int $showcaseId = 0): array
    {
        $errors = [];

        $showcases = $this->cacheManager->get(CacheDataTypes::Config);

        $isTaken = static function (string $column, mixed $value) use ($showcases, $showcaseId): bool {
            foreach ($showcases as $showcaseData) {
                if (($showcaseData[$column] ?? null) === $value && $showcaseData['showcase_id'] !== $showcaseId) {
                    return true;
                }
            }

            return false;
        };

        if (isset($data['name']) && (!$data['name'] || $isTaken('name', $data['name']))) {
            $errors[] = [
                'key' => 'myshowcase_admin_error_duplicated_name',
                'message' => 'The selected name is already in use.'
            ];
        }

        if (!empty($data['route_identifier']) && $isTaken('route_identifier', $data['route_identifier'])) {
            $errors[] = [
                'key' => 'myShowcaseAdminErrorDuplicatedScriptFile',
                'message' => 'The route identifier is already in use.'
            ];
        }

        if (!empty($data['custom_template_prefix']) && $isTaken('custom_template_prefix', $data['custom_template_prefix'])) {
            $errors[] = [
                'key' => 'myShowcaseAdminErrorDuplicatedTemplatePrefix',
                'message' => 'The template prefix is already in use.'
            ];
        }

        return $errors;
    }

    /**
     * Inserts a showcase, or updates it when $showcaseId is given, and refreshes the Config cache.
     * Hook showcase_insert_update (ShowcasesRepository) fires as before.
     *
     * @return int The id of the showcase.
     */
    public function save(array $data, int $showcaseId = 0, bool $fromSync = false): int
    {
        global $db;

        if (!$fromSync) {
            $data = JsonLock::stripJson($data);

            $row = $showcaseId ? (showcaseGet(["showcase_id='{$showcaseId}'"], ['json_file', 'collection_id'], [], false)[$showcaseId] ?? null) : null;

            if ($row !== null && $row['json_file'] !== '') {
                $data = JsonLock::default()->enforce('showcase', $row, $data);
            }
        }

        if ($showcaseId) {
            $repository = new ShowcasesRepository($db, null, new ShowcaseEntity(showcase_id: $showcaseId));

            $repository->updateShowcase($data);
        } else {
            $showcaseId = (new ShowcasesRepository($db))->insertShowcase($data);
        }

        $this->cacheManager->update(CacheDataTypes::Config);

        return $showcaseId;
    }
}
