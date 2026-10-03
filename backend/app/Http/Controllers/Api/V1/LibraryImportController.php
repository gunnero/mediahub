<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\LibraryImportService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class LibraryImportController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['batches' => ImportBatch::where('user_id', $request->user()->id)->latest('id')->limit(20)->get()]);
    }

    public function preview(Request $request, LibraryImportService $imports)
    {
        $request->validate(['file' => 'required|file|max:10240']);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($extension, ['json', 'sqlite', 'db'], true), 422, 'Choose a MediaHub JSON export or a supported TV Time JSON/SQLite archive.');
        try {
            $data = $imports->read($file->getRealPath(), $extension);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'This archive could not be read. Check its format and try again.']);
        }

        return response()->json(['batch' => $imports->preview($request->user(), $data)], 201);
    }

    public function apply(Request $request, int $batch, LibraryImportService $imports)
    {
        $request->validate(['confirm' => 'required|accepted']);
        $batch = ImportBatch::where('user_id', $request->user()->id)->findOrFail($batch);

        return response()->json(['batch' => $imports->apply($request->user(), $batch)]);
    }

    public function recover(Request $request, int $batch, LibraryImportService $imports)
    {
        $request->validate(['confirm' => 'required|accepted']);
        $batch = ImportBatch::where('user_id', $request->user()->id)->findOrFail($batch);
        $imports->recover($request->user(), $batch);

        return response()->noContent();
    }
}
