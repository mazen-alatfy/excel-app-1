<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ContactUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.receiver.url' => 'http://receiver.test']);
    }

    protected function tearDown(): void
    {
        $dir = storage_path('app/testing');
        if (is_dir($dir)) {
            array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*.xlsx') ?: []);
            rmdir($dir);
        }

        parent::tearDown();
    }

    /**
     * Builds a real .xlsx file where the first row holds the column headers.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function makeXlsx(array $rows, string $filename): string
    {
        $dir = storage_path('app/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 1));
        }

        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function upload(string $path)
    {
        return $this->post('/api/contacts/upload', [
            'file' => new UploadedFile(
                $path,
                'contacts.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ]);
    }

    public function test_upload_forwards_the_file_to_the_receiver_import_endpoint(): void
    {
        Http::fake([
            'receiver.test/*' => Http::response([
                'message' => 'Contacts saved.',
                'created' => 2,
                'updated' => 0,
            ], 201),
        ]);

        $path = $this->makeXlsx([
            ['name', 'email', 'phone', 'age'],
            ['Mazen', 'mazen@test.com', '01000000001', 28],
            ['Ahmed', 'ahmed@test.com', null, null],
            ['', '', '', ''],
        ], 'contacts-upload.xlsx');

        $response = $this->upload($path);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'File sent to the receiver service.')
            ->assertJsonPath('data.receiver_response', ['message' => 'Contacts saved.', 'created' => 2, 'updated' => 0]);

        Http::assertSent(function (Request $request) use ($path) {
            return $request->url() === 'http://receiver.test/api/contacts/import'
                && $request->hasFile('file', file_get_contents($path), 'contacts.xlsx');
        });
    }

    public function test_upload_requires_a_file(): void
    {
        Http::preventStrayRequests();

        $this->post('/api/contacts/upload')
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_forwards_file_without_reading_its_rows(): void
    {
        Http::fake([
            'receiver.test/*' => Http::response(['message' => 'No valid rows.'], 422),
        ]);

        $path = $this->makeXlsx([
            ['name', 'email'],
            ['', ''],
        ], 'contacts-empty.xlsx');

        $this->upload($path)
            ->assertStatus(502)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'The receiver service rejected the data.')
            ->assertJsonPath('details.message', 'No valid rows.');
    }

    public function test_upload_returns_502_when_the_receiver_is_unreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Could not resolve host');
        });

        $path = $this->makeXlsx([
            ['name', 'email'],
            ['Mazen', 'mazen@test.com'],
        ], 'contacts-unreachable.xlsx');

        $this->upload($path)
            ->assertStatus(502)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Cannot reach the receiver service.');
    }

    public function test_upload_returns_receiver_error_in_standard_json_response(): void
    {
        Http::fake([
            'receiver.test/*' => Http::response(['message' => 'Invalid contacts.'], 422),
        ]);

        $path = $this->makeXlsx([
            ['name', 'email'],
            ['Mazen', 'mazen@test.com'],
        ], 'contacts-rejected.xlsx');

        $this->upload($path)
            ->assertStatus(502)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'The receiver service rejected the data.')
            ->assertJsonPath('details.message', 'Invalid contacts.');
    }

    public function test_index_returns_receiver_contacts_in_standard_json_response(): void
    {
        Http::fake([
            'receiver.test/*' => Http::response([
                'count' => 1,
                'data' => [['id' => 1, 'email' => 'mazen@test.com']],
            ]),
        ]);

        $this->get('/api/contacts')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Contacts fetched successfully.')
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.data.0.email', 'mazen@test.com');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://receiver.test/api/contacts');
    }

    public function test_index_returns_502_when_the_receiver_rejects_the_request(): void
    {
        Http::fake([
            'receiver.test/*' => Http::response(['message' => 'Unavailable.'], 503),
        ]);

        $this->get('/api/contacts')
            ->assertStatus(502)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'The receiver service rejected the request.')
            ->assertJsonPath('data.receiver_response.message', 'Unavailable.');
    }
}
