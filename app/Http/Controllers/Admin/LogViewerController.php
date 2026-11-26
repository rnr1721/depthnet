<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Logs\LogViewerServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Admin-only Laravel log viewer (JSON API for the "Log" tab in chat).
 * Route group is behind AdminMiddleware — logs may contain secrets / user data.
 */
class LogViewerController extends Controller
{
    public function __construct(
        protected LogViewerServiceInterface $logViewer
    ) {
    }

    /**
     * GET admin/logs?file=laravel.log&offset=12345
     * Without offset: tail of the file. With offset: only appended entries.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'   => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-]+\.log$/'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $result = $this->logViewer->read(
                $validated['file'] ?? null,
                isset($validated['offset']) ? (int) $validated['offset'] : null
            );
        } catch (InvalidArgumentException $e) {
            // Selected file disappeared (rotation) -> fall back to newest file
            $result = $this->logViewer->read(null, null);
            $result['reset'] = true;
        }

        return response()->json(array_merge($result, [
            'files' => $this->logViewer->listFiles(),
        ]));
    }

    /**
     * POST admin/logs/clear  { file }
     */
    public function clear(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-]+\.log$/'],
        ]);

        try {
            $ok = $this->logViewer->clear($validated['file']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['success' => $ok]);
    }
}
