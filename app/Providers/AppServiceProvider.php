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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
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
        $this->configureSessionAndUrls();
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

    protected function configureSessionAndUrls(): void
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        /** @var Request $request */
        $request = request();

        $isSecure = $request->isSecure()
            || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';

        config(['session.secure' => $isSecure]);

        $appUrl = config('app.url');

        if (is_string($appUrl) && str_starts_with($appUrl, 'https://') && $isSecure) {
            URL::forceScheme('https');
        }
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
