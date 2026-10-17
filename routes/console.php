<?php

use App\Jobs\ProcessFlowExecution;
use App\Jobs\ReconcileAiSend;
use App\Models\ExternalWebhookReceipt;
use App\Models\FlowExecution;
use App\Models\UazapiConnection;
use App\Services\Chat\ChatMaintenance;
use App\Services\Flows\FlowMenuRuntime;
use App\Services\Sequences\SequenceScheduler;
use App\Services\Uazapi\UazapiClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sequences:tick', function () {
    $count = app(SequenceScheduler::class)->tick();
    $this->info("Etapas elegíveis encaminhadas: {$count}");
})->purpose('Reconcilia participações e encaminha a próxima etapa elegível');

Schedule::command('sequences:tick')->everyMinute()->withoutOverlapping();

Artisan::command('webhooks:prune-receipts', function () {
    $count = ExternalWebhookReceipt::where('created_at', '<', now()->subDays(config('external-webhooks.receipt_retention_days')))->delete();
    $this->info("Recebimentos removidos: {$count}");
})->purpose('Remove metadados de Webhooks externos com mais de 30 dias');

Schedule::command('webhooks:prune-receipts')->daily()->withoutOverlapping();

Artisan::command('menus:tick', function () {
    $count = app(FlowMenuRuntime::class)->tick();
    $this->info("Esperas de Menu verificadas: {$count}");
})->purpose('Recupera envios pendentes e expira Menus de todos os fluxos');

Schedule::command('menus:tick')->everyMinute()->withoutOverlapping();

Artisan::command('flows:reconcile-sends', function () {
    FlowExecution::whereIn('status', ['running', 'waiting_ai'])->where(function ($q) {
        $q->whereNotNull('context->pending_track_id')->orWhereNotNull('context->ai_pending_send');
    })->eachById(function ($execution) {
        if (isset($execution->context['ai_pending_send'])) {
            ReconcileAiSend::dispatch($execution->context['ai_pending_send']['session_id']);
        } else {
            ProcessFlowExecution::dispatch($execution->id);
        }
    });
})->purpose('Recupera intenções de transporte sem reconstruir mensagens');
Schedule::command('flows:reconcile-sends')->everyMinute()->withoutOverlapping();

Artisan::command('chat:recover', function () {
    app(ChatMaintenance::class)->recover();
})->purpose('Reencaminha somente eventos e operações já aceitos pelo CHAIT');
Artisan::command('chat:prune', function () {
    app(ChatMaintenance::class)->prune();
})->purpose('Descarta conteúdo temporário do chat após 24 horas');
Artisan::command('chat:metrics', function () {
    $this->line(json_encode(app(ChatMaintenance::class)->metrics(), JSON_THROW_ON_ERROR));
})->purpose('Expõe diagnóstico técnico do chat, sem conteúdo');
Artisan::command('chat:register-local-webhook {origin}', function (UazapiClient $uazapi) {
    if (! app()->environment('local')) {
        $this->error('Este comando é exclusivo do ambiente local.');

        return 1;
    }

    $origin = rtrim((string) $this->argument('origin'), '/');
    if (! preg_match('~^https://[a-z0-9-]+\.trycloudflare\.com$~i', $origin)) {
        $this->error('Informe a origem HTTPS do Quick Tunnel de webhook.');

        return 1;
    }

    $count = 0;
    foreach (UazapiConnection::where('status', 'connected')->cursor() as $connection) {
        $secret = hash_hmac('sha256', 'uazapi-webhook:'.$connection->id, (string) config('app.key'));
        $path = route('webhooks.uazapi', ['connection' => $connection->id, 'secret' => $secret], false);
        try {
            $uazapi->configureWebhook($connection, $origin.$path);
            $count++;
        } catch (Throwable) {
            $this->error("Falha ao registrar o webhook da conexão {$connection->id}.");

            return 1;
        }
    }

    $this->info("Webhooks registrados: {$count}. Guarde o túnel ativo durante o teste.");

    return 0;
})->purpose('Registra o Quick Tunnel local na UAZAPI sem exibir os segredos');
Schedule::command('chat:recover')->everyMinute()->withoutOverlapping();
Schedule::command('chat:prune')->everyMinute()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();
