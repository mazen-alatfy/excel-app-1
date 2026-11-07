<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ContactService
{
    public function sendFile(UploadedFile $file): Response
    {
        return Http::acceptJson()
            ->timeout(60)
            ->attach(
                'file',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            )
            ->post($this->receiverUrl('/api/contacts/import'));
    }

    public function sendStoredFile(string $path, string $originalName): Response
    {
        return Http::acceptJson()
            ->timeout(60)
            ->attach(
                'file',
                Storage::disk('local')->get($path),
                $originalName
            )
            ->post($this->receiverUrl('/api/contacts/import'));
    }

    public function getContacts(): Response
    {
        return Http::acceptJson()
            ->timeout(15)
            ->get($this->receiverUrl('/api/contacts'));
    }

    private function receiverUrl(string $path): string
    {
        return rtrim(config('services.receiver.url'), '/').$path;
    }
}
