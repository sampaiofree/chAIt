<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceOpenAiIntegration extends Model
{
    protected $table = 'workspace_openai_integrations';

    protected $guarded = [];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }
}
