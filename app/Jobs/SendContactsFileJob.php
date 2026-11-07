<?php

namespace App\Jobs;

use App\Services\ContactService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SendContactsFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public int $timeout = 90;

    public function __construct(public string $path, public string $originalName) {}

    public function handle(ContactService $contactService): void
    {
        $response = $contactService->sendStoredFile($this->path, $this->originalName);

        if ($response->serverError() || $response->status() === 429) {
            throw new RuntimeException(
                "Receiver returned HTTP {$response->status()} for {$this->originalName}."
            );
        }

        if ($response->clientError()) {
            Log::error('Receiver rejected the contacts file.', [
                'file' => $this->originalName,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            $this->fail(new RuntimeException(
                "Receiver rejected {$this->originalName} with HTTP {$response->status()}."
            ));

            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Receiver returned unexpected HTTP {$response->status()} for {$this->originalName}."
            );
        }

        Storage::disk('local')->delete($this->path);

        Log::info('Contacts file sent successfully.', [
            'file' => $this->originalName,
            'status' => $response->status(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Contacts file processing failed.', [
            'file' => $this->originalName,
            'error' => $exception->getMessage(),
        ]);

        Storage::disk('local')->delete($this->path);
    }
}
