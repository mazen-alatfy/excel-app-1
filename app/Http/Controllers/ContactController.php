<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\ContactService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function upload(Request $request, ContactService $contactService): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        try {
            $receiverResponse = $contactService->sendFile($request->file('file'));
        } catch (ConnectionException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot reach the receiver service.',
            ], 502);
        }

        if ($receiverResponse->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'The receiver service rejected the data.',
                'details' => $receiverResponse->json(),
            ], 502);
        }

        return ApiResponse::success([
            'receiver_response' => $receiverResponse->json(),
        ], 'File sent to the receiver service.');
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
