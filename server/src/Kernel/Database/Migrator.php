<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Database;

/**
 * Applies each context's migrations, found as numbered .sql files in
 * src/<Context>/Migrations/ (design section 3.1). Contexts are discovered by
 * scanning, so this class never names one.
 */
final class Migrator
{
    private readonly string $sourceDirectory;

    public function __construct(
        private readonly Database $database,
        ?string $sourceDirectory = null,
    ) {
        $this->sourceDirectory = $sourceDirectory ?? dirname(__DIR__, 2);
    }

    /**
     * @return string[] applied migrations, as "Context/file.sql"
     */
    public function migrate(): array
    {
        $pdo = $this->database->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS kernel_migrations ('
                . 'context TEXT NOT NULL, name TEXT NOT NULL, applied_at INTEGER NOT NULL, '
                . 'PRIMARY KEY (context, name))',
        );

        $applied = [];

        foreach ($this->pending() as [$context, $name, $file]) {
            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new \RuntimeException(sprintf('Could not read migration "%s".', $file));
            }

            $this->database->transaction(function (\PDO $pdo) use ($sql, $context, $name): void {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO kernel_migrations (context, name, applied_at) VALUES (?, ?, ?)')
                    ->execute([$context, $name, time()]);
            });

            $applied[] = $context . '/' . $name;
        }

        return $applied;
    }

    public function isUpToDate(): bool
    {
        if (!$this->database->exists()) {
            return false;
        }

        return $this->pending() === [];
    }

    /**
     * @return list<array{string, string, string}> context, file name and path, in order
     */
    private function pending(): array
    {
        $applied = [];
        $pdo = $this->database->pdo();
        $tableExists = $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'kernel_migrations'",
        )->fetchColumn();

        if ($tableExists !== false) {
            foreach ($pdo->query('SELECT context, name FROM kernel_migrations') as $row) {
                $applied[$row['context'] . '/' . $row['name']] = true;
            }
        }

        $files = glob($this->sourceDirectory . '/*/Migrations/*.sql') ?: [];
        sort($files);
        $pending = [];

        foreach ($files as $file) {
            $context = basename(dirname($file, 2));
            $name = basename($file);

            if (!isset($applied[$context . '/' . $name])) {
                $pending[] = [$context, $name, $file];
            }
        }

        return $pending;
    }
}
