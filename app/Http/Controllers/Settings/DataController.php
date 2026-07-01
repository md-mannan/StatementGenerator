<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RestoreDatabaseBackupRequest;
use App\Http\Requests\Settings\WipeDatabaseRequest;
use App\Services\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DataController extends Controller
{
    public function __construct(
        private readonly DatabaseBackupService $backupService,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('settings/data', [
            'summary' => $this->backupService->summary(),
        ]);
    }

    public function export(): BinaryFileResponse
    {
        try {
            $path = $this->backupService->export();
        } catch (RuntimeException $exception) {
            abort(500, $exception->getMessage());
        }

        return response()
            ->download($path, $this->backupService->exportFilename())
            ->deleteFileAfterSend(true);
    }

    public function restore(RestoreDatabaseBackupRequest $request): RedirectResponse
    {
        $file = $request->file('backup');
        $path = $file?->getRealPath();

        if ($path === false || $path === null) {
            return back()->withErrors([
                'backup' => __('Unable to read the uploaded backup file.'),
            ]);
        }

        try {
            $this->backupService->restore($path, $file->getClientOriginalName());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return back()->withErrors([
                'backup' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $message = trim($exception->getMessage());

            return back()->withErrors([
                'backup' => $message !== ''
                    ? __('Restore failed: :message', ['message' => $message])
                    : __('The database could not be restored. Check that the file is a valid backup from this application.'),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Database restored successfully. Please sign in again.'),
        ]);

        return to_route('home')->with(
            'status',
            __('Database restored successfully. Please sign in again.'),
        );
    }

    public function wipe(WipeDatabaseRequest $request): RedirectResponse
    {
        try {
            $this->backupService->wipeApplicationData();
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'wipe' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'wipe' => __('Application data could not be cleared. Try again or restore from a backup.'),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('All application data was cleared. User accounts were kept.'),
        ]);

        return to_route('data.edit');
    }
}
