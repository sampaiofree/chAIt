<?php

namespace App\Providers;

use App\Events\AutomationContextChanged;
use App\Models\Note;
use App\Models\Thread;
use App\Models\WorkspaceMembership;
use App\Observers\ChatMembershipObserver;
use App\Observers\ChatNoteObserver;
use App\Observers\ChatThreadObserver;
use App\Services\Chat\ChatPublisher;
use App\Services\Chat\TransportContext;
use App\Services\Sequences\SequenceScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TransportContext::class);
        $this->app->singleton(ChatPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(AutomationContextChanged::class,
            function ($event): void {
                app(SequenceScheduler::class)->reconcile($event->context);
                if (config('chat.enabled')) {
                    Thread::where('workspace_id', $event->context->workspace_id)->where('uazapi_connection_id', $event->context->uazapi_connection_id)->where('contact_id', $event->context->contact_id)->pluck('id')->each(fn ($id) => app(ChatPublisher::class)->thread($id));
                }
            });

        Thread::observe(ChatThreadObserver::class);
        Note::observe(ChatNoteObserver::class);
        WorkspaceMembership::observe(ChatMembershipObserver::class);
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
