<?php

declare(strict_types=1);

/*
 * NEW5B item 7 (decision D330): AccessLogsRepository::deleteAccessLog() read the columns access_log_name, thumbnail_name and
 * status, which the access-log table does not have. The read is not used by the delete, and the SQL error it caused was not
 * caught. This test runs the method against a recording database and checks that every column the method names exists in
 * the table of TABLES_DATA (Plugin/Core.php), and that the delete names the showcase and the access log.
 *
 *     php tests/status/access_log_delete.php
 *
 * No MyBB and no database: the database and the other types of the constructor are stubs. Exit code 1 if a check fails.
 */

namespace Symfony\Contracts\EventDispatcher {
    class Event
    {
    }
}

namespace MyShowcase\Plugin\Functions {
    // the hook runner of the plugin: no hook is registered in this test
    function hooksRun(string $name, mixed $argument = null): mixed
    {
        return $argument;
    }
}

namespace MyShowcase\Event {
    class EventDispatcher
    {
        public function dispatch(object $event, string $name = ''): object
        {
            return $event;
        }
    }
}

namespace MyShowcase\Entity {
    class ShowcaseEntity
    {
        public int $showcase_id = 2;
    }
}

namespace {
    class DB_SQLite
    {
        /** @var array<int, array{table: string, fields: string, where: string}> */
        public array $selects = [];

        /** @var array<int, array{table: string, where: string}> */
        public array $deletes = [];

        public function simple_select(string $table, string $fields = '*', string $where = '', array $options = []): string
        {
            $this->selects[] = ['table' => $table, 'fields' => $fields, 'where' => $where];

            return 'query';
        }

        public function fetch_array(mixed $query): array|false
        {
            return false;
        }

        public function delete_query(string $table, string $where = ''): void
        {
            $this->deletes[] = ['table' => $table, 'where' => $where];
        }
    }
}

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

    // The columns of myshowcase_access_logs, from the table definition of the plugin (Plugin/Core.php).
    $core = (string)file_get_contents(__DIR__ . '/../../Upload/inc/plugins/myshowcase/Plugin/Core.php');
    $start = strpos($core, "'myshowcase_access_logs' => [");
    $block = $start === false ? '' : substr($core, $start, (int)strpos($core, "'myshowcase_collections' => [", $start) - $start);
    preg_match_all("/^\\s{8}'([a-z_]+)' => \\[/m", $block, $found);
    $columns = $found[1];
    $check($columns !== [], 'the table definition of myshowcase_access_logs is found in Plugin/Core.php (' . implode(', ', $columns) . ')');

    $database = new DB_SQLite();
    $repository = new \MyShowcase\Repository\AccessLogsRepository($database, new \MyShowcase\Event\EventDispatcher());
    $repository->setShowcase(new \MyShowcase\Entity\ShowcaseEntity());

    $error = null;
    try {
        $repository->deleteAccessLog(5);
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }

    $check($error === null, 'deleteAccessLog() runs without an error' . ($error === null ? '' : " [{$error}]"));

    foreach ($database->selects as $select) {
        foreach (array_map('trim', explode(',', $select['fields'])) as $field) {
            $check(in_array($field, $columns, true), "the read of deleteAccessLog() names the column {$field} of the access-log table");
        }
    }

    $check(count($database->deletes) === 1, 'deleteAccessLog() deletes one row');
    $check(
        isset($database->deletes[0]) && $database->deletes[0]['table'] === 'myshowcase_access_logs'
            && str_contains($database->deletes[0]['where'], "access_log_id='5'")
            && str_contains($database->deletes[0]['where'], "showcase_id='2'"),
        'the delete names the access log and its showcase'
    );

    echo "\n" . ($checks - $failures) . " passed, {$failures} failed (access_log_delete, {$checks} checks)\n";
    exit($failures === 0 ? 0 : 1);
}
