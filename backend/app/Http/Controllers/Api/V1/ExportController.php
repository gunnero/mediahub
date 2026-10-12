<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UserExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function json(Request $request, UserExportService $exports): StreamedResponse
    {
        $payload = $exports->payload($request->user());

        return response()->streamDownload(
            fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'reelatlas-export-'.now()->format('Ymd-His').'.json',
            ['Content-Type' => 'application/json']
        );
    }

    public function csv(Request $request, string $dataset, UserExportService $exports): StreamedResponse
    {
        abort_unless(in_array($dataset, ['movies', 'shows', 'episodes', 'movie-watches', 'episode-watches', 'ratings', 'notes'], true), 404);
        $export = $exports->csv($request->user(), $dataset);

        return response()->streamDownload(function () use ($export): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, $export['headers']);
            foreach ($export['rows'] as $row) {
                fputcsv($stream, $row);
            }
            fclose($stream);
        }, $export['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
