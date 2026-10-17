<?php

namespace App\Services\Sequences;

use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SequenceDefinitionService
{
    public function validate(Workspace $workspace, array $input, bool $publish = false): array
    {
        $required = $publish ? 'required' : 'nullable';
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'], 'timezone' => ['required', 'timezone'],
            'steps' => ['present', 'array', 'max:100', ...($publish ? ['min:1'] : [])],
            'steps.*.schedule_type' => ['required', 'in:interval,specific_datetime'],
            'steps.*.flow_id' => [$required, 'integer', 'exists:flows,id,workspace_id,'.$workspace->id],
            'steps.*.delay_seconds' => ['nullable', 'integer', 'min:60', 'max:31536000'],
            'steps.*.scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ], [
            'name.required' => 'Informe o nome da sequência.', 'name.max' => 'Use até 120 caracteres no nome.',
            'timezone.timezone' => 'Selecione um fuso horário válido.',
            'steps.min' => 'Adicione ao menos uma etapa antes de publicar.',
            'steps.max' => 'Use até 100 etapas por sequência.',
            'steps.*.flow_id.required' => 'Selecione um fluxo publicado para esta etapa.',
            'steps.*.flow_id.exists' => 'Selecione um fluxo deste workspace.',
            'steps.*.delay_seconds.min' => 'O intervalo mínimo é de 1 minuto.',
            'steps.*.delay_seconds.max' => 'O intervalo máximo é de 365 dias.',
            'steps.*.delay_seconds.integer' => 'Informe um intervalo em segundos inteiros.',
            'steps.*.scheduled_at.date_format' => 'Informe uma data e hora válidas.',
        ])->validate();
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Informe o nome da sequência.']);
        }
        foreach ($data['steps'] as $i => &$step) {
            $relative = $step['schedule_type'] === 'interval';
            $step['delay_seconds'] = $relative ? ($step['delay_seconds'] ?? null) : null;
            $step['scheduled_at'] = $relative ? null : ($step['scheduled_at'] ?? null);
            if ($publish && ($relative ? $step['delay_seconds'] === null : $step['scheduled_at'] === null)) {
                throw ValidationException::withMessages(["steps.$i.".($relative ? 'delay_seconds' : 'scheduled_at') => 'Preencha a programação da etapa.']);
            }
            if ($step['scheduled_at'] !== null) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $step['scheduled_at'], $data['timezone']);
                if ($date->format('Y-m-d\TH:i') !== $step['scheduled_at']) {
                    throw ValidationException::withMessages(["steps.$i.scheduled_at" => 'Horário inexistente neste fuso.']);
                }
            }
        }
        unset($step);

        return $data;
    }

    public function save(Sequence $sequence, Workspace $workspace, array $input, int $revision): Sequence
    {
        $data = $this->validate($workspace, $input);

        return DB::transaction(function () use ($sequence, $data, $revision) {
            $sequence = Sequence::whereKey($sequence->id)->lockForUpdate()->firstOrFail();
            abort_unless($sequence->revision === $revision, 409, 'Esta sequência foi alterada em outra aba. Recarregue antes de salvar.');
            $sequence->update(['name' => $data['name'], 'draft' => $data, 'revision' => $revision + 1]);

            return $sequence;
        });
    }

    public function publish(Sequence $sequence, Workspace $workspace, User $user, int $revision): SequenceVersion
    {
        return DB::transaction(function () use ($sequence, $workspace, $user, $revision) {
            // Same order as dependency mutations: workspace -> sequence -> referenced flows.
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $sequence = Sequence::whereKey($sequence->id)->lockForUpdate()->firstOrFail();
            abort_unless($sequence->revision === $revision, 409, 'Esta sequência foi alterada em outra aba. Recarregue antes de publicar.');
            $data = $this->validate($workspace, $sequence->draft, true);
            $versions = [];
            foreach ($data['steps'] as $i => $step) {
                $this->captureFlow($workspace, (int) $step['flow_id'], $versions, [], "steps.$i.flow_id");
            }
            $version = $sequence->versions()->create([
                'workspace_id' => $workspace->id, 'version' => ((int) $sequence->versions()->max('version')) + 1,
                'name' => $data['name'], 'timezone' => $data['timezone'], 'published_by_user_id' => $user->id, 'published_at' => now(),
            ]);
            foreach ($versions as $flowId => $flowVersion) {
                DB::table('sequence_version_flows')->insert(['sequence_version_id' => $version->id, 'flow_id' => $flowId, 'flow_version_id' => $flowVersion->id]);
            }
            foreach ($data['steps'] as $i => $step) {
                $version->steps()->create([
                    'position' => $i, 'schedule_type' => $step['schedule_type'], 'delay_seconds' => $step['delay_seconds'],
                    'scheduled_at' => $step['scheduled_at'] ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $step['scheduled_at'], $data['timezone'])->utc() : null,
                    'flow_version_id' => $versions[(int) $step['flow_id']]->id,
                ]);
            }
            $sequence->update(['published_version_id' => $version->id, 'is_active' => true]);
            app(AuditLogService::class)->record($workspace, 'sequence.published', $user, $sequence, payload: ['version' => $version->version]);

            return $version;
        });
    }

    private function captureFlow(Workspace $workspace, int $flowId, array &$versions, array $path, string $field): void
    {
        if (in_array($flowId, $path, true) || count($path) > 20) {
            throw ValidationException::withMessages([$field => 'A cadeia de fluxos possui ciclo ou excede o limite de saltos.']);
        }
        if (isset($versions[$flowId])) {
            return;
        }
        $flow = Flow::where('workspace_id', $workspace->id)->whereKey($flowId)->lockForUpdate()->first();
        if (! $flow?->is_active || ! $flow->published_version_id) {
            throw ValidationException::withMessages([$field => 'Selecione um fluxo publicado e ativo deste workspace.']);
        }
        $version = FlowVersion::where('workspace_id', $workspace->id)->where('flow_id', $flowId)->findOrFail($flow->published_version_id);
        $path[] = $flowId;
        foreach ($version->graph['nodes'] ?? [] as $node) {
            if (($node['type'] ?? '') === 'jump_flow') {
                $this->captureFlow($workspace, (int) ($node['data']['target_flow_id'] ?? 0), $versions, $path, $field);
            }
        }
        $versions[$flowId] = $version;
    }

    public function flowReferences(int $workspaceId, int $flowId, bool $allVersions = false): array
    {
        return DB::table('sequence_version_flows as dependencies')->join('sequence_versions as versions', 'versions.id', '=', 'dependencies.sequence_version_id')
            ->join('sequences', 'sequences.id', '=', 'versions.sequence_id')->where('sequences.workspace_id', $workspaceId)->where('dependencies.flow_id', $flowId)
            ->when(! $allVersions, fn ($query) => $query->where(function ($q) {
                $q->where(fn ($active) => $active->where('sequences.is_active', true)->whereColumn('sequences.published_version_id', 'versions.id'))
                    ->orWhereExists(fn ($enrolled) => $enrolled->selectRaw('1')->from('sequence_enrollments')->whereColumn('sequence_version_id', 'versions.id')->whereNotIn('status', SequenceEnrollment::TERMINAL));
            }))->distinct()->pluck('versions.name')->all();
    }
}
