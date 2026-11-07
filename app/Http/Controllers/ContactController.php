<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Jobs\SendContactsFileJob;
use App\Services\ContactService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        $file = $request->file('file');
        $path = $file->store('contact-uploads', 'local');

        SendContactsFileJob::dispatch($path, $file->getClientOriginalName());

        return ApiResponse::success([
            'file' => $file->getClientOriginalName(),
        ], 'File received and queued for processing.', 202);
    }

    public function index(ContactService $contactService): JsonResponse
    {
        try {
            $receiverResponse = $contactService->getContacts();
        } catch (ConnectionException) {
            return ApiResponse::error('Cannot reach the receiver service.', 502);
        }

        if ($receiverResponse->failed()) {
            return ApiResponse::error(
                'The receiver service rejected the request.',
                502,
                ['receiver_response' => $receiverResponse->json()]
            );
        }

        return ApiResponse::success(
            $receiverResponse->json(),
            'Contacts fetched successfully.'
        );
    }
}
