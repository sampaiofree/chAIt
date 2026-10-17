<?php

namespace App\Services\Database;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use PDO;
use RuntimeException;

class SqliteToPostgresImporter
{
    public function import(string $path, Connection $target): array
    {
        $path = realpath($path);
        if (! $path || ! is_file($path) || $target->getDriverName() !== 'pgsql' || $target->transactionLevel() !== 0) {
            throw new RuntimeException('Use um arquivo SQLite existente e uma conexão PostgreSQL sem transação ativa.');
        }
        $uri = implode('/', array_map('rawurlencode', explode('/', $path)));
        $source = new PDO('sqlite:file:'.$uri.'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $source->exec('PRAGMA query_only = ON');
        $source->beginTransaction();
        try {
            if ($source->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $source->query('PRAGMA foreign_key_check')->fetch()) {
                throw new RuntimeException('O SQLite de origem não passou na verificação de integridade.');
            }
            $tables = $source->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $schema = $target->selectOne('SELECT current_schema() AS name')->name;
            $targetTables = array_column($target->getSchemaBuilder()->getTables($schema), 'name');
            sort($targetTables);
            if ($tables !== $targetTables || ! in_array('migrations', $tables, true)) {
                throw new RuntimeException('As tabelas de origem e destino devem corresponder às mesmas migrations.');
            }
            $sourceMigrations = $source->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
            if ($sourceMigrations !== $target->table('migrations')->orderBy('migration')->pluck('migration')->all()) {
                throw new RuntimeException('Aplique as mesmas migrations no destino antes de importar.');
            }

            return $target->transaction(function () use ($source, $target, $tables, $schema) {
                $grammar = $target->getQueryGrammar();
                $qualified = fn (string $table) => $grammar->wrap($schema).'.'.$grammar->wrap($table);
                $dataTables = array_values(array_diff($tables, ['migrations']));
                // Prevent another writer from populating a target between the check and import.
                $target->statement('LOCK TABLE '.implode(', ', array_map($qualified, $tables)).' IN ACCESS EXCLUSIVE MODE');
                foreach ($dataTables as $table) {
                    if ($target->table($table)->exists()) {
                        throw new RuntimeException('O destino contém dados. A importação foi recusada.');
                    }
                }
                $constraints = $target->select("SELECT c.conname, t.relname, c.condeferrable, c.condeferred FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE c.contype = 'f' AND n.nspname = ?", [$schema]);
                foreach ($constraints as $constraint) {
                    $target->statement('ALTER TABLE '.$qualified($constraint->relname).' ALTER CONSTRAINT '.$grammar->wrap($constraint->conname).' DEFERRABLE INITIALLY IMMEDIATE');
                }
                $target->statement('SET CONSTRAINTS ALL DEFERRED');
                $counts = [];
                foreach ($dataTables as $table) {
                    $columns = $target->select('SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$schema, $table]);
                    $types = array_column($columns, 'data_type', 'column_name');
                    $sourceColumns = array_column($source->query('PRAGMA table_info('.$this->sqliteName($table).')')->fetchAll(PDO::FETCH_ASSOC), 'name');
                    $destinationColumns = array_keys($types);
                    sort($sourceColumns);
                    sort($destinationColumns);
                    if ($sourceColumns !== $destinationColumns) {
                        throw new RuntimeException('As colunas não correspondem na tabela '.$table.'.');
                    }
                    $statement = $source->query('SELECT * FROM '.$this->sqliteName($table));
                    $hashes = [];
                    $batch = [];
                    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                        foreach ($types as $column => $type) {
                            if ($type === 'boolean' && $row[$column] !== null) {
                                if (! in_array($row[$column], [0, 1, '0', '1'], true)) {
                                    throw new RuntimeException('Booleano inválido na tabela '.$table.'.');
                                }
                                $row[$column] = (bool) $row[$column];
                            }
                        }
                        $hashes[] = $this->hash($row, $types);
                        $batch[] = $row;
                        if (count($batch) === 100) {
                            $target->table($table)->insert($batch);
                            $batch = [];
                        }
                    }
                    if ($batch !== []) {
                        $target->table($table)->insert($batch);
                    }
                    $destinationHashes = [];
                    foreach ($target->table($table)->cursor() as $row) {
                        $destinationHashes[] = $this->hash((array) $row, $types);
                    }
                    sort($hashes);
                    sort($destinationHashes);
                    if ($hashes !== $destinationHashes) {
                        throw new RuntimeException('O conteúdo importado não corresponde na tabela '.$table.'.');
                    }
                    $counts[$table] = count($hashes);
                }
                // Force every deferred reference to be checked before restoring the schema.
                $target->statement('SET CONSTRAINTS ALL IMMEDIATE');
                foreach ($constraints as $constraint) {
                    $definition = $constraint->condeferrable ? ('DEFERRABLE INITIALLY '.($constraint->condeferred ? 'DEFERRED' : 'IMMEDIATE')) : 'NOT DEFERRABLE';
                    $target->statement('ALTER TABLE '.$qualified($constraint->relname).' ALTER CONSTRAINT '.$grammar->wrap($constraint->conname).' '.$definition);
                }
                foreach ($dataTables as $table) {
                    $columns = $target->select('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$schema, $table]);
                    foreach ($columns as $column) {
                        $sequence = $target->selectOne('SELECT pg_get_serial_sequence(?, ?) AS name', [$qualified($table), $column->column_name])->name;
                        if ($sequence) {
                            $max = $target->table($table)->max($column->column_name);
                            $target->select('SELECT setval(CAST(? AS regclass), ?, ?)', [$sequence, max(1, (int) $max), $max !== null]);
                        }
                    }
                }

                return $counts;
            });
        } finally {
            $source->rollBack();
        }
    }

    private function sqliteName(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function hash(array $row, array $types): string
    {
        $normalized = [];
        foreach ($types as $column => $type) {
            $value = $row[$column];
            $normalized[$column] = $value === null ? null : match ($type) {
                'boolean' => in_array($value, [true, 1, '1', 't', 'true'], true),
                'smallint', 'integer', 'bigint' => (string) $value,
                'numeric', 'real', 'double precision' => (string) (float) $value,
                'json', 'jsonb' => $this->canonicalJson(json_decode($value, false, 512, JSON_THROW_ON_ERROR)),
                'timestamp without time zone', 'timestamp with time zone' => (new DateTimeImmutable($value))->format('Y-m-d H:i:s.uP'),
                default => (string) $value,
            };
        }

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalJson(mixed $value): mixed
    {
        if (is_object($value)) {
            $properties = (array) $value;
            ksort($properties);

            return (object) array_map($this->canonicalJson(...), $properties);
        }

        return is_array($value) ? array_map($this->canonicalJson(...), $value) : $value;
    }
}
