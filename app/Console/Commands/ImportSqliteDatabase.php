<?php

namespace App\Console\Commands;

use App\Services\Database\SqliteToPostgresImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportSqliteDatabase extends Command
{
    protected $signature = 'database:import-sqlite {source : Caminho do backup SQLite} {--force : Executar sem confirmação}';

    protected $description = 'Importa um SQLite em PostgreSQL vazio, verificando conteúdo, referências e sequências';

    public function handle(SqliteToPostgresImporter $importer): int
    {
        if (! $this->option('force') && ! $this->confirm('Importar todos os dados no PostgreSQL configurado?')) {
            return self::FAILURE;
        }
        try {
            $counts = $importer->import($this->argument('source'), DB::connection());
            $this->table(['Tabela', 'Registros verificados'], array_map(fn ($name, $count) => [$name, $count], array_keys($counts), $counts));
            $this->info('Importação confirmada. Conteúdo, referências e sequências verificados.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            // Database exception messages may contain encrypted credentials or message payloads.
            $this->error(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'A importação falhou e foi revertida. Categoria: '.class_basename($error).'; código: '.$error->getCode());

            return self::FAILURE;
        }
    }
}
