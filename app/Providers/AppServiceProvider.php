<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientAnnexureCheque;
use App\Models\ClientAnnexureEntry;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Policies\BranchPolicy;
use App\Policies\ClientAnnexureChequePolicy;
use App\Policies\ClientAnnexureEntryPolicy;
use App\Policies\ClientPolicy;
use App\Policies\IncomingStatementEntryPolicy;
use App\Policies\StatementEntryPolicy;
use App\Support\Installation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerPolicies();
        Installation::syncMarker();
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(StatementEntry::class, StatementEntryPolicy::class);
        Gate::policy(IncomingStatementEntry::class, IncomingStatementEntryPolicy::class);
        Gate::policy(ClientAnnexureEntry::class, ClientAnnexureEntryPolicy::class);
        Gate::policy(ClientAnnexureCheque::class, ClientAnnexureChequePolicy::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Schema::defaultStringLength(191);

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
