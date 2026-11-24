<?php
declare(strict_types=1);

namespace Borealis\Core;

/** Applies versioned SQL migrations from database/migrations in filename order, once each. */
final class Migrator
{
    public function __construct(private readonly \PDO $pdo, private readonly string $dir = BASE_PATH . '/database/migrations')
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(60) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function applied(): array
    {
        $this->ensureTable();
        return $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function pending(): array
    {
        $done = array_flip($this->applied());
        $out = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $f) {
            $v = basename($f, '.sql');
            if (!isset($done[$v])) $out[$v] = $f;
        }
        ksort($out);
        return $out;
    }

    /** @return string[] applied versions */
    public function migrate(): array
    {
        // 1.5: one update at a time. A second click (or a second admin) waits, then finds nothing pending.
        $locked = (int)$this->pdo->query("SELECT GET_LOCK('borealis_migrate', 60)")->fetchColumn();
        if ($locked !== 1) throw new \RuntimeException('Otra actualización de la base de datos está en curso. Espera un minuto y vuelve a intentarlo.');
        try {
            return $this->run();
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('borealis_migrate')");
        }
    }

    private function run(): array
    {
        $ran = [];
        foreach ($this->pending() as $version => $file) {
            $sql = (string)file_get_contents($file);
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $stmt) {
                $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
                if ($stmt === '') continue;
                try {
                    $this->pdo->exec($stmt);
                } catch (\PDOException $e) {
                    // 1.7: a migration interrupted halfway can run again. 1060 = the column already
                    // exists, 1061 = the index already exists (MySQL has no ADD COLUMN IF NOT EXISTS).
                    $code = (int)($e->errorInfo[1] ?? 0);
                    if ($code !== 1060 && $code !== 1061) throw $e;
                }
            }
            $st = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
            $st->execute([$version, gmdate('Y-m-d H:i:s')]);
            $ran[] = $version;
        }
        return $ran;
    }
}
