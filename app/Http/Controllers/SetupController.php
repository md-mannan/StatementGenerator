<?php

namespace App\Http\Controllers;

use App\Http\Requests\Setup\InstallApplicationRequest;
use App\Http\Requests\Setup\TestDatabaseRequest;
use App\Services\SetupRequirementsChecker;
use App\Services\SetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class SetupController extends Controller
{
    public function show(SetupRequirementsChecker $requirementsChecker): Response
    {
        return Inertia::render('setup/index', $this->setupPageProps($requirementsChecker));
    }

    public function testDatabase(
        TestDatabaseRequest $request,
        SetupService $setupService,
    ): JsonResponse {
        try {
            $setupService->testDatabaseConnection($request->validated());
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Database connection successful.',
        ]);
    }

    public function install(
        InstallApplicationRequest $request,
        SetupService $setupService,
    ): JsonResponse|SymfonyResponse {
        try {
            $setupService->install($request->validated());
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], 422);
            }

            return back()->withErrors($exception->errors())->withInput();
        } catch (Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Installation failed: '.$exception->getMessage(),
                    'errors' => [
                        'install' => ['Installation failed: '.$exception->getMessage()],
                    ],
                ], 500);
            }

            return back()->withErrors([
                'install' => 'Installation failed: '.$exception->getMessage(),
            ])->withInput();
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'redirect' => route('dashboard'),
            ]);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location(route('dashboard'));
        }

        return redirect()->route('dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    private function setupPageProps(SetupRequirementsChecker $requirementsChecker): array
    {
        return [
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
        ];
    }
}
