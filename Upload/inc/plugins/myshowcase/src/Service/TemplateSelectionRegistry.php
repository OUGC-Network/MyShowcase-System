<?php

declare(strict_types=1);

namespace MyShowcase\Service;

/**
 * Holds the active request's TemplateSelection for Twig include resolution (TP-D1 B).
 */
final class TemplateSelectionRegistry
{
    private static ?TemplateSelection $current = null;

    public static function set(TemplateSelection $selection): void
    {
        self::$current = $selection;
    }

    public static function get(): ?TemplateSelection
    {
        return self::$current;
    }

    public static function resolveTwigTemplateName(string $logicalName): string
    {
        $selection = self::$current;

        if ($selection !== null) {
            return $selection->resolveTwigTemplateName($logicalName);
        }

        $logicalName = preg_replace('/\.twig$/i', '', $logicalName) ?? $logicalName;

        return '@ext.myshowcase/' . $logicalName . '.twig';
    }

    public static function templateExists(string $logicalName): bool
    {
        $selection = self::$current;

        if ($selection !== null) {
            return $selection->templateExists($logicalName);
        }

        $logicalName = preg_replace('/\.twig$/i', '', $logicalName) ?? $logicalName;

        return is_file(\MyShowcase\ROOT . '/view/templates/' . $logicalName . '.twig');
    }
}