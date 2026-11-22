<?php
declare(strict_types=1);

final class Store
{
    private ?PDO $pdo = null;
    private string $path;
    private string $driver;

    public function __construct(array $config, string $data)
    {
        $this->driver = $config['driver'] ?? 'auto';
        if ($this->driver === 'auto') {
            // Keep an existing automatic installation on its original backend.
            $sqlite = $config['sqlite_path'] ?? $data . '/wiki.sqlite';
            $json = $config['json_path'] ?? $data . '/wiki.json';
            $available = class_exists(PDO::class) && in_array('sqlite', PDO::getAvailableDrivers(), true);
            $this->driver = is_file($sqlite) || (!is_file($json) && $available) ? 'sqlite' : 'json';
        }

        if ($this->driver === 'json') {
            $this->path = $config['json_path'] ?? $data . '/wiki.json';
            return;
        }
        if ($this->driver === 'sqlite') {
            $this->pdo = new PDO('sqlite:' . ($config['sqlite_path'] ?? $data . '/wiki.sqlite'));
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS kiowiki_state (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
            $this->pdo->exec("INSERT OR IGNORE INTO kiowiki_state VALUES (1, '{}')");
            return;
        }
        if (in_array($this->driver, ['mysql', 'mariadb'], true)) {
            $this->pdo = new PDO($config['mysql_dsn'], $config['mysql_user'] ?? '', $config['mysql_password'] ?? '', [
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS kiowiki_state (id INT PRIMARY KEY, payload LONGTEXT NOT NULL) ENGINE=InnoDB');
            $this->pdo->exec("INSERT IGNORE INTO kiowiki_state VALUES (1, '{}')");
            return;
        }
        throw new RuntimeException('Unsupported storage driver.');
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function read(): array
    {
        if ($this->pdo) {
            return $this->decode($this->pdo->query('SELECT payload FROM kiowiki_state WHERE id = 1')->fetchColumn());
        }
        return is_file($this->path) ? $this->decode(file_get_contents($this->path)) : [];
    }

    public function change(callable $operation): mixed
    {
        if ($this->pdo) {
            // Acquire the write lock before reading to prevent lost updates.
            $this->pdo->beginTransaction();
            try {
                if ($this->driver === 'sqlite') {
                    $this->pdo->exec('UPDATE kiowiki_state SET id = id WHERE id = 1');
                } else {
                    $this->pdo->query('SELECT id FROM kiowiki_state WHERE id = 1 FOR UPDATE')->closeCursor();
                }
                $state = $this->read();
                $result = $operation($state);
                $this->pdo->prepare('UPDATE kiowiki_state SET payload = ? WHERE id = 1')->execute([$this->encode($state)]);
                $this->pdo->commit();
                return $result;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        $lock = fopen($this->path . '.lock', 'c');
        if (!$lock) {
            throw new RuntimeException('Cannot open storage lock.');
        }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock storage.');
            }
            $state = $this->read();
            $result = $operation($state);
            $temporary = tempnam(dirname($this->path), 'wiki-');
            $payload = $this->encode($state);
            if (!$temporary || file_put_contents($temporary, $payload) !== strlen($payload) || !rename($temporary, $this->path)) {
                throw new RuntimeException('Cannot save storage.');
            }
            return $result;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
            fclose($lock);
        }
    }

    private function encode(array $state): string
    {
        return json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decode(string $raw): array
    {
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
