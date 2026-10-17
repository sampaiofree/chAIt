<?php

use App\Services\Database\SqliteToPostgresImporter;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->importSchema = 'chait_import_'.bin2hex(random_bytes(12));
    config(['database.connections.import_target' => array_merge(config('database.connections.pgsql'), ['search_path' => $this->importSchema])]);
    $this->target = DB::connection('import_target');
    $this->target->statement('CREATE SCHEMA "'.$this->importSchema.'"');
    $this->target->statement('CREATE TABLE migrations (migration TEXT PRIMARY KEY, batch INTEGER)');
    $this->target->statement("INSERT INTO migrations VALUES ('fixture', 9)");
    $this->target->statement('CREATE TABLE parents (id BIGSERIAL PRIMARY KEY, child_id BIGINT, enabled BOOLEAN, graph JSONB, secret TEXT, created_at TIMESTAMP)');
    $this->target->statement('CREATE TABLE children (id BIGSERIAL PRIMARY KEY, parent_id BIGINT REFERENCES parents(id))');
    $this->target->statement('ALTER TABLE parents ADD CONSTRAINT child_reference FOREIGN KEY (child_id) REFERENCES children(id)');
    $this->sourcePath = tempnam(sys_get_temp_dir(), 'chait-import-');
    $this->source = new PDO('sqlite:'.$this->sourcePath);
    $this->source->exec("PRAGMA foreign_keys=ON;
        CREATE TABLE migrations (migration TEXT PRIMARY KEY, batch INTEGER);
        INSERT INTO migrations VALUES ('fixture', 1);
        CREATE TABLE parents (id INTEGER PRIMARY KEY, child_id INTEGER REFERENCES children(id) DEFERRABLE INITIALLY DEFERRED, enabled INTEGER, graph TEXT, secret TEXT, created_at TEXT);
        CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parents(id) DEFERRABLE INITIALLY DEFERRED);");
    $this->source->beginTransaction();
    $this->source->exec("INSERT INTO parents VALUES (12, 37, 1, '{\"z\":[2,1],\"a\":{\"b\":true}}', 'encrypted-fixture', '2026-10-14 12:01:02'); INSERT INTO children VALUES (37,12)");
    $this->source->commit();
});

afterEach(function () {
    $this->target->statement('DROP SCHEMA "'.$this->importSchema.'" CASCADE');
    DB::purge('import_target');
    $this->source = null;
    unlink($this->sourcePath);
});

test('import preserves circular references, types, ciphertext and source and advances sequences', function () {
    $before = hash_file('sha256', $this->sourcePath);
    $counts = app(SqliteToPostgresImporter::class)->import($this->sourcePath, $this->target);
    expect($counts)->toBe(['children' => 1, 'parents' => 1])
        ->and(hash_file('sha256', $this->sourcePath))->toBe($before)
        ->and($this->target->table('parents')->value('secret'))->toBe('encrypted-fixture')
        ->and($this->target->table('parents')->value('enabled'))->toBeTrue()
        ->and($this->target->table('migrations')->value('batch'))->toBe(9)
        ->and($this->target->table('parents')->insertGetId(['enabled' => false]))->toBe(13)
        ->and($this->target->table('children')->insertGetId(['parent_id' => 13]))->toBe(38);
    expect($this->target->selectOne("SELECT condeferrable FROM pg_constraint WHERE conname='child_reference' AND connamespace = (SELECT oid FROM pg_namespace WHERE nspname=?)", [$this->importSchema])->condeferrable)->toBeFalse();
});

test('import refuses populated destinations without overwriting rows', function () {
    $this->target->table('parents')->insert(['id' => 90, 'secret' => 'keep']);
    expect(fn () => app(SqliteToPostgresImporter::class)->import($this->sourcePath, $this->target))->toThrow(RuntimeException::class, 'O destino contém dados');
    expect($this->target->table('parents')->sole()->secret)->toBe('keep')->and($this->target->table('children')->count())->toBe(0);
});

test('invalid conversion rolls back every imported table and constraint change', function () {
    $this->source->exec('UPDATE parents SET enabled=7');
    expect(fn () => app(SqliteToPostgresImporter::class)->import($this->sourcePath, $this->target))->toThrow(RuntimeException::class, 'Booleano inválido');
    expect($this->target->table('parents')->count())->toBe(0)->and($this->target->table('children')->count())->toBe(0);
    expect($this->target->selectOne("SELECT condeferrable FROM pg_constraint WHERE conname='child_reference' AND connamespace = (SELECT oid FROM pg_namespace WHERE nspname=?)", [$this->importSchema])->condeferrable)->toBeFalse();
});

test('mismatched migration history and corrupt references are rejected before copying', function () {
    $this->source->exec("UPDATE migrations SET migration='other'");
    expect(fn () => app(SqliteToPostgresImporter::class)->import($this->sourcePath, $this->target))->toThrow(RuntimeException::class, 'mesmas migrations');
    $this->source->exec("UPDATE migrations SET migration='fixture'; PRAGMA foreign_keys=OFF; UPDATE children SET parent_id=999");
    expect(fn () => app(SqliteToPostgresImporter::class)->import($this->sourcePath, $this->target))->toThrow(RuntimeException::class, 'integridade');
    expect($this->target->table('parents')->count())->toBe(0);
});
