<?php

declare(strict_types=1);

/*
 * NEW5B item 1: FieldFactory::create() must build a field object for every html type of FieldHtmlTypes, without a fatal error
 * on load. This test loads every field class through the plugin's autoloader, then calls create() once per type and checks
 * that the object is of the class the factory maps it to.
 *
 *     php tests/status/field_factory_create.php
 *
 * No MyBB and no database. Exit code 1 if a check fails.
 */

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'MyShowcase\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $file = __DIR__ . '/../../Upload/inc/plugins/myshowcase/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });

    $failures = 0;
    $checks = 0;

    $check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
        $checks++;

        if ($condition) {
            echo "PASS {$message}\n";

            return;
        }

        $failures++;
        echo "FAIL {$message}\n";
    };

    $types = (new ReflectionClass(\MyShowcase\Enum\FieldHtmlTypes::class))->getConstants();
    $check($types !== [], 'FieldHtmlTypes lists the html types (' . count($types) . ')');

    $factory = null;

    try {
        $factory = new \MyShowcase\Fields\FieldFactory();
        $check(true, 'FieldFactory loads');
    } catch (\Throwable $exception) {
        $check(false, 'FieldFactory loads [' . $exception->getMessage() . ']');
    }

    foreach ($types as $name => $value) {
        if ($factory === null) {
            break;
        }

        try {
            $field = $factory->create(['html_type' => $value, 'field_id' => 1, 'name' => 'check', 'field_type' => $value]);
            $check($field instanceof \MyShowcase\Fields\FieldsInterface, "create() builds a field for {$name} ({$value})");
        } catch (\Throwable $exception) {
            $check(false, "create() builds a field for {$name} ({$value}) [" . $exception->getMessage() . ']');
        }
    }

    echo "\n" . ($checks - $failures) . " passed, {$failures} failed (field_factory_create, {$checks} checks)\n";
    exit($failures === 0 ? 0 : 1);
}
