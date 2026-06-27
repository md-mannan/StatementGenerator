<?php

namespace App\Http\Controllers;

use App\Http\Requests\Setup\InstallApplicationRequest;
use App\Http\Requests\Setup\TestDatabaseRequest;
use App\Services\SetupRequirementsChecker;
use App\Services\SetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function show(SetupRequirementsChecker $requirementsChecker): Response
    {
        return Inertia::render('setup/index', [
            'requirements' => $requirementsChecker->check(),
            'defaults' => [
                'db_host' => env('DB_HOST', '127.0.0.1'),
                'db_port' => (string) env('DB_PORT', '3306'),
                'db_database' => env('DB_DATABASE', 'statements_tracker'),
                'db_username' => env('DB_USERNAME', 'root'),
                'app_name' => config('app.name') ?: 'Statement Analyzer',
                'app_url' => config('app.url') ?: request()->getSchemeAndHttpHost(),
            ],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function testDatabase(
        TestDatabaseRequest $request,
        SetupService $setupService,
    ): RedirectResponse {
        try {
            $setupService->testDatabaseConnection($request->validated());
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('setupDatabaseTested', false);
        }

        return back()
            ->with('setupDatabaseTested', true)
            ->with('setupDatabaseMessage', 'Database connection successful.');
    }

    public function install(
        InstallApplicationRequest $request,
        SetupService $setupService,
    ): RedirectResponse {
        try {
            $setupService->install($request->validated());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()->route('dashboard');
    }
}
