<?php

namespace App\Services;

use App\Models\StatementEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementInvoiceScanService
{
    private const DISK = 'local';

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function store(StatementEntry $entry, UploadedFile $file): void
    {
        $extension = $this->resolveExtension($file);
        $this->assertAllowedUpload($file, $extension);

        $entry->loadMissing('branch.client');

        $directory = $this->directoryFor($entry);
        $filename = $this->filenameFor($entry->invoice_no, $extension);
        $path = $directory.'/'.$filename;

        $this->deleteStoredFile($entry);

        Storage::disk(self::DISK)->putFileAs($directory, $file, $filename);

        $entry->forceFill(['invoice_scan_path' => $path])->save();
    }

    public function delete(StatementEntry $entry): void
    {
        $this->deleteStoredFile($entry);

        $entry->forceFill(['invoice_scan_path' => null])->save();
    }

    public function syncFilenameAfterInvoiceChange(StatementEntry $entry): void
    {
        if ($entry->invoice_scan_path === null) {
            return;
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($entry->invoice_scan_path)) {
            $entry->forceFill(['invoice_scan_path' => null])->save();

            return;
        }

        $extension = pathinfo($entry->invoice_scan_path, PATHINFO_EXTENSION);
        $directory = $this->directoryFor($entry);
        $newFilename = $this->filenameFor($entry->invoice_no, $extension);
        $newPath = $directory.'/'.$newFilename;

        if ($newPath === $entry->invoice_scan_path) {
            return;
        }

        if ($disk->exists($newPath)) {
            $disk->delete($newPath);
        }

        $disk->move($entry->invoice_scan_path, $newPath);

        $entry->forceFill(['invoice_scan_path' => $newPath])->save();
    }

    public function show(StatementEntry $entry): StreamedResponse
    {
        if ($entry->invoice_scan_path === null) {
            abort(404);
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($entry->invoice_scan_path)) {
            abort(404);
        }

        $extension = pathinfo($entry->invoice_scan_path, PATHINFO_EXTENSION);
        $filename = $this->filenameFor($entry->invoice_no, $extension);

        return $disk->response(
            $entry->invoice_scan_path,
            $filename,
            ['Content-Disposition' => 'inline; filename="'.$filename.'"'],
        );
    }

    public function hasScan(StatementEntry $entry): bool
    {
        return $entry->invoice_scan_path !== null
            && Storage::disk(self::DISK)->exists($entry->invoice_scan_path);
    }

    public function filenameFor(string $invoiceNo, string $extension): string
    {
        $invoiceNo = trim($invoiceNo);
        $sanitized = preg_replace('/[\\\\\\/:*?"<>|\\x00-\\x1F]/u', '_', $invoiceNo) ?? '';

        if ($sanitized === '') {
            throw new InvalidArgumentException('Invoice number is not valid for a file name.');
        }

        return $sanitized.'.'.strtolower($extension);
    }

    private function directoryFor(StatementEntry $entry): string
    {
        return sprintf(
            'invoice-scans/clients/%d/branches/%d',
            $entry->branch->client_id,
            $entry->branch_id,
        );
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        if ($extension === 'jpeg') {
            return 'jpg';
        }

        if ($extension !== '') {
            return $extension;
        }

        $guessed = strtolower($file->guessExtension() ?: '');

        return $guessed === 'jpeg' ? 'jpg' : $guessed;
    }

    private function assertAllowedUpload(UploadedFile $file, string $extension): void
    {
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Only PDF or image scans are allowed.');
        }

        $mime = $file->getMimeType() ?? '';

        if ($mime !== '' && ! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('The uploaded file type is not supported.');
        }
    }

    private function deleteStoredFile(StatementEntry $entry): void
    {
        if ($entry->invoice_scan_path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($entry->invoice_scan_path);
    }
}
