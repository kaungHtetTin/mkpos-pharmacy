<?php

namespace App\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;
final class PlatformBackup
{
    private function root(): string
    {
        $root = config('operations.backup_path');
        if (!is_dir($root) && !mkdir($root, 0700, true)) {
            throw new \RuntimeException('Cannot create backup directory.');
        }
        $real = realpath($root);
        $public = realpath(public_path());
        if (!$real || $public && ($real === $public || str_starts_with($real, $public . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Backups must be outside the public directory.');
        }
        chmod($real, 0700);
        return $real;
    }
    private function password(): string
    {
        $password = config('operations.backup_password');
        if (strlen($password ?? '') < 24) {
            throw new \RuntimeException('Configure PLATFORM_BACKUP_PASSWORD with at least 24 characters in the private environment.');
        }
        return $password;
    }
    public function status(): array
    {
        $root = config('operations.backup_path');
        $status = is_file($root . '/status.json') ? json_decode(file_get_contents($root . '/status.json'), true) : null;
        return ['configured' => strlen(config('operations.backup_password') ?? '') >= 24, 'last_success_at' => $status['completed_at'] ?? null, 'bytes' => $status['bytes'] ?? null, 'stale' => !isset($status['completed_at']) || \Illuminate\Support\Carbon::parse($status['completed_at'])->lt(now()->subHours(26))];
    }
    public function create(): string
    {
        $password = $this->password();
        $root = $this->root();
        $id = 'backup-' . now()->format('Ymd-His') . '-' . Str::random(8);
        $work = $root . '/' . $id;
        mkdir($work, 0700);
        $sql = $work . '/database.sql';
        $zip = new ZipArchive();
        $target = $root . '/' . $id . '.zip';
        $manifest = ['format' => 'mkpos-platform-backup-v1', 'created_at' => now()->toIso8601String(), 'files' => []];
        try {
            $f = fopen($sql, 'xb');
            chmod($sql, 0600);
            try {
                fwrite($f, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
                $pdo = DB::connection()->getPdo();
                DB::transaction(function () use ($pdo, $f) {
                    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as [$table, $type]) {
                        if ($type !== 'BASE TABLE') {
                            continue;
                        }
                        $quoted = '`' . str_replace('`', '``', $table) . '`';
                        fwrite($f, 'DROP TABLE IF EXISTS ' . $quoted . ";\n" . $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(\PDO::FETCH_NUM)[1] . ";\n");
                        $buffered = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
                        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                        $records = $pdo->query('SELECT * FROM ' . $quoted);
                        try {
                            foreach ($records as $row) {
                                $columns = [];
                                $values = [];
                                foreach ($row as $key => $value) {
                                    if (is_int($key)) {
                                        continue;
                                    }
                                    $columns[] = '`' . str_replace('`', '``', $key) . '`';
                                    $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                                }
                                fwrite($f, 'INSERT INTO ' . $quoted . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
                            }
                        } finally {
                            $records->closeCursor();
                            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                        }
                    }
                });
                fwrite($f, "SET FOREIGN_KEY_CHECKS=1;\n");
            } finally {
                fclose($f);
            }
            touch($target);
            chmod($target, 0600);
            if ($zip->open($target, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot open backup archive.');
            }
            $zip->setPassword($password);
            $add = function (string $file, string $name) use ($zip, &$manifest) {
                if (!$zip->addFile($file, $name) || !$zip->setEncryptionName($name, ZipArchive::EM_AES_256)) {
                    throw new \RuntimeException('Encrypted ZIP is unavailable.');
                }
                $manifest['files'][$name] = ['sha256' => hash_file('sha256', $file), 'bytes' => filesize($file)];
            };
            $add($sql, 'database.sql');
            foreach ([storage_path('app/private/businesses') => 'media/private/businesses', storage_path('app/businesses') => 'media/businesses', storage_path('app/billing') => 'media/billing', public_path('storage') => 'media/public'] as $folder => $prefix) {
                if (!is_dir($folder)) {
                    continue;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isLink() || !$file->isFile()) {
                        continue;
                    }
                    $add($file->getPathname(), $prefix . '/' . substr($file->getPathname(), strlen($folder) + 1));
                }
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
            if (!$zip->close()) {
                throw new \RuntimeException('Backup archive failed.');
            }
            $this->verify($target);
            $status = ['completed_at' => now()->toIso8601String(), 'file' => basename($target), 'bytes' => filesize($target)];
            $statusPath = $root . '/status-' . Str::uuid() . '.tmp';
            file_put_contents($statusPath, json_encode($status, JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($statusPath, 0600);
            if (!rename($statusPath, $root . '/status.json')) {
                throw new \RuntimeException('Cannot update backup metadata.');
            }
            return $target;
        } catch (\Throwable $e) {
            if (is_file($target)) {
                try {
                    $zip->close();
                } catch (\Throwable) {
                }
            }
            if (is_file($target)) {
                unlink($target);
            }
            throw $e;
        } finally {
            if (is_file($sql)) {
                unlink($sql);
            }
            rmdir($work);
        }
    }
    public function verify(string $path): array
    {
        $real = realpath($path);
        $root = $this->root();
        if (!$real || dirname($real) !== $root || !preg_match('/^backup-[A-Za-z0-9-]+\.zip$/', basename($real))) {
            throw new \RuntimeException('Select a backup inside the configured private directory.');
        }
        $zip = new ZipArchive();
        if ($zip->open($real) !== true) {
            throw new \RuntimeException('Cannot read backup.');
        }
        $zip->setPassword($this->password());
        try {
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) {
                throw new \RuntimeException('Cannot decrypt backup manifest.');
            }
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 'mkpos-platform-backup-v1' || !isset($manifest['files']['database.sql'])) {
                throw new \RuntimeException('Unsupported backup manifest.');
            }
            foreach ($manifest['files'] as $name => $expected) {
                $stream = $zip->getStream($name);
                if (!$stream) {
                    throw new \RuntimeException('Missing backup entry.');
                }
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stream);
                fclose($stream);
                if ($bytes !== $expected['bytes'] || !hash_equals($expected['sha256'], hash_final($hash))) {
                    throw new \RuntimeException('Backup checksum mismatch.');
                }
            }
            return ['files' => count($manifest['files']), 'created_at' => $manifest['created_at']];
        } finally {
            $zip->close();
        }
    }
    public function prune(): int
    {
        $root = $this->root();
        $removed = 0;
        $cutoff = now()->subDays(config('operations.backup_retention_days'))->timestamp;
        foreach (glob($root . '/backup-*.zip') ?: [] as $file) {
            if (!is_link($file) && filemtime($file) < $cutoff) {
                unlink($file);
                $removed++;
            }
        }
        foreach (glob(storage_path('app/private/exports/*.zip')) ?: [] as $file) {
            if (!is_link($file) && filemtime($file) < now()->subDay()->timestamp) {
                unlink($file);
            }
        }
        return $removed;
    }
}
