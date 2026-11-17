<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Thread;
use App\Models\Workspace;
use App\Services\Chat\ChatPublisher;
use App\Services\Contacts\ContactIdentity;
use App\Services\Contacts\InternationalPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LeadController extends Controller
{
    private function access(Request $r, Workspace $w, bool $edit = false): void
    {
        abort_unless($r->user()->hasLeadPermission($w, 'can_view_leads') && (! $edit || $r->user()->hasLeadPermission($w, 'can_edit_leads')), 403);
    }

    public function index(Request $r, Workspace $workspace)
    {
        $this->access($r, $workspace);
        $data = $r->validate(['search' => 'nullable|string|max:255', 'tag_ids' => 'sometimes|array', 'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('workspace_id', $workspace->id)], 'tag_mode' => 'nullable|in:and,or', 'untagged' => 'nullable|boolean', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'order' => 'nullable|in:asc,desc']);
        $q = Contact::where('workspace_id', $workspace->id)->with('tags:id,name,color');
        if ($search = trim($data['search'] ?? '')) {
            $q->where(function ($q) use ($search) {
                $q->where('display_name', 'ilike', '%'.$search.'%');
                $digits = preg_replace('/\D/', '', $search);
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', '%'.$digits.'%');
                }
            });
        }
        if (($data['tag_ids'] ?? []) || ($data['untagged'] ?? false)) {
            $q->where(function ($q) use ($data) {
                $ids = $data['tag_ids'] ?? [];
                if ($ids) {
                    if (($data['tag_mode'] ?? 'or') === 'and') {
                        $q->where(function ($q) use ($ids) {
                            foreach ($ids as $id) {
                                $q->whereHas('tags', fn ($q) => $q->where('tags.id', $id));
                            }
                        });
                    } else {
                        $q->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $ids));
                    }
                }if ($data['untagged'] ?? false) {
                    if ($ids) {
                        $q->orWhereDoesntHave('tags');
                    } else {
                        $q->whereDoesntHave('tags');
                    }
                }
            });
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if ($data[$key] ?? null) {
                $date = Carbon::parse($data[$key], $workspace->timezone ?: 'UTC');
                $q->where('created_at', $op, $key === 'from' ? $date->startOfDay()->utc() : $date->endOfDay()->utc());
            }
        }

        return Inertia::render('leads/index', ['workspace' => $workspace->only('id', 'name'), 'leads' => $q->orderBy('created_at', $data['order'] ?? 'desc')->orderBy('id', $data['order'] ?? 'desc')->paginate(25)->withQueryString(), 'filters' => $data, 'tags' => $workspace->tags()->orderBy('name')->get(), 'canEdit' => $r->user()->hasLeadPermission($workspace, 'can_edit_leads'), 'canCreateTags' => $r->user()->hasLeadPermission($workspace, 'can_create_tags'), 'canCreateFields' => $r->user()->hasLeadPermission($workspace, 'can_create_contact_fields')]);
    }

    public function show(Request $r, Workspace $workspace, Contact $lead)
    {
        $this->access($r, $workspace);
        abort_unless($lead->workspace_id === $workspace->id, 404);
        $member = $r->user()->membershipFor($workspace);
        $threads = Thread::where('workspace_id', $workspace->id)->where('contact_id', $lead->id)->whereHas('uazapiConnection', fn ($q) => $q->where('workspace_id', $workspace->id));
        if (! $r->user()->canAccessWorkspace($workspace, WorkspaceRole::Admin) && ! $member->can_view_unassigned) {
            $threads->where('assigned_to_user_id', $r->user()->id);
        }

        return Inertia::render('leads/show', ['workspace' => $workspace->only('id', 'name'), 'lead' => $lead->load(['tags', 'customFieldValues', 'channels']), 'tags' => $workspace->tags()->orderBy('name')->get(), 'fields' => $workspace->contactCustomFields()->orderBy('name')->get(), 'threadId' => $threads->latest('id')->value('id'), 'canEdit' => $r->user()->hasLeadPermission($workspace, 'can_edit_leads'), 'canCreateTags' => $r->user()->hasLeadPermission($workspace, 'can_create_tags'), 'canCreateFields' => $r->user()->hasLeadPermission($workspace, 'can_create_contact_fields')]);
    }

    private function validated(Request $r, Workspace $w): array
    {
        return $r->validate(['display_name' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255', 'cpf' => 'nullable|string|max:14', 'observations' => 'nullable|string|max:10000', 'tag_ids' => 'sometimes|array', 'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('workspace_id', $w->id)], 'values' => 'sometimes|array', 'values.*' => 'nullable|string|max:10000']);
    }

    public function store(Request $r, Workspace $workspace)
    {
        $this->access($r, $workspace, true);
        $data = $this->validated($r, $workspace);
        $r->validate(['phone' => 'required|string|max:40']);
        $phone = app(InternationalPhone::class)->normalize($r->input('phone'));
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Informe um WhatsApp válido com código do país.']);
        }
        $contact = DB::transaction(function () use ($workspace, $phone, $data) {
            $inserted = DB::table('contacts')->insertOrIgnore(['workspace_id' => $workspace->id, 'phone' => $phone, 'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) {
                throw ValidationException::withMessages(['phone' => 'Este WhatsApp já está cadastrado. Busque o número na lista de Leads.']);
            }$c = app(ContactIdentity::class)->locked($workspace->id, $phone);
            $c->update(collect($data)->except(['tag_ids', 'values'])->all());
            $c->tags()->sync(collect($data['tag_ids'] ?? [])->mapWithKeys(fn ($id) => [$id => ['workspace_id' => $workspace->id]])->all());

            return $c;
        });

        return redirect()->route('workspaces.leads.show', [$workspace, $contact]);
    }

    public function update(Request $r, Workspace $workspace, Contact $lead)
    {
        $this->access($r, $workspace, true);
        abort_unless($lead->workspace_id === $workspace->id, 404);
        $data = $this->validated($r, $workspace);
        DB::transaction(function () use ($workspace, $lead, $data) {
            $lead = Contact::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $lead->update(collect($data)->except(['tag_ids', 'values'])->all());
            if (array_key_exists('tag_ids', $data)) {
                $lead->tags()->sync(collect($data['tag_ids'])->mapWithKeys(fn ($id) => [$id => ['workspace_id' => $workspace->id]])->all());
            }foreach ($data['values'] ?? [] as $id => $value) {
                abort_unless($workspace->contactCustomFields()->whereKey($id)->exists(), 422);
                $lead->customFieldValues()->updateOrCreate(['field_id' => $id], ['workspace_id' => $workspace->id, 'value' => $value]);
            }
        });
        foreach ($lead->threads as $thread) {
            app(ChatPublisher::class)->thread($thread->id);
        }

        return back()->with('success', 'Lead salvo.');
    }
}
