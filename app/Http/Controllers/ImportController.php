<?php

namespace App\Http\Controllers;

use App\Services\HistoryImporter;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class ImportController extends Controller
{
    public function __invoke(HistoryImporter $importer): RedirectResponse
    {
        // ponytail: synchronous import; use the command for large exports or queue it if web requests time out.
        set_time_limit(0);

        try {
            $result = $importer->import(base_path('import'));

            return redirect()->route('configure')->with($result['files'] === 0 ? 'warning' : 'status', $result['message']);
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()->route('configure')->with('warning', $exception->getMessage());
        }
    }
}
