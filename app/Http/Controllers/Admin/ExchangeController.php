<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Exchange\PresetExporterInterface;
use App\Contracts\Agent\Exchange\PresetImporterInterface;
use App\Contracts\Auth\AuthServiceInterface;
use App\Exceptions\Exchange\ExportException;
use App\Exceptions\Exchange\ImportException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import/export of presets and agents as portable "depthnet.bundle" JSON.
 *
 * Thin controller — all logic lives in PresetExporter / PresetImporter.
 *
 * Export is a per-object action (a button on a preset or agent) that streams a
 * JSON file. Import is a two-step flow: preflight (validate, show report, write
 * nothing) then import (write). The two steps mirror the two service methods and
 * preserve the user's chance to review warnings before anything is created.
 */
class ExchangeController extends Controller
{
    public function __construct(
        protected PresetExporterInterface $exporter,
        protected PresetImporterInterface $importer,
        protected AuthServiceInterface $authService,
        protected LoggerInterface $logger,
    ) {
    }

    // ── Import page ─────────────────────────────────────────────────────────

    /**
     * The import page (upload + preview + confirm).
     */
    public function importForm(): InertiaResponse
    {
        return Inertia::render('Admin/Exchange/Index');
    }

    // ── Export (streamed file download) ─────────────────────────────────────

    /**
     * Export a single preset with its full dependency closure.
     * GET /admin/exchange/export/preset/{id}?include_skills=0
     */
    public function exportPreset(Request $request, int $id): StreamedResponse|JsonResponse
    {
        try {
            $bundle = $this->exporter->exportPreset($id, [
                'include_skills' => $request->boolean('include_skills'),
            ]);

            return $this->streamBundle($bundle, "preset-{$id}");

        } catch (ExportException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logger->error('ExchangeController::exportPreset failed', [
                'preset_id' => $id,
                'error'     => $e->getMessage(),
            ]);
            return $this->errorResponse('Export failed.', 500);
        }
    }

    /**
     * Export an agent (planner + roles + validators + full closure).
     * GET /admin/exchange/export/agent/{id}?include_skills=0
     */
    public function exportAgent(Request $request, int $id): StreamedResponse|JsonResponse
    {
        try {
            $bundle = $this->exporter->exportAgent($id, [
                'include_skills' => $request->boolean('include_skills'),
            ]);

            return $this->streamBundle($bundle, "agent-{$id}");

        } catch (ExportException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logger->error('ExchangeController::exportAgent failed', [
                'agent_id' => $id,
                'error'    => $e->getMessage(),
            ]);
            return $this->errorResponse('Export failed.', 500);
        }
    }

    // ── Import step 1: preflight (writes nothing) ───────────────────────────

    /**
     * Validate an uploaded bundle and return the report. Writes NOTHING.
     * POST /admin/exchange/import/preflight   (multipart: bundle=<file>)
     *
     * The response carries the parsed bundle back to the client so step 2 can
     * resubmit it without a server-side temp file.
     */
    public function preflight(Request $request): JsonResponse
    {
        $bundle = $this->readUploadedBundle($request);
        if ($bundle instanceof JsonResponse) {
            return $bundle; // parse/validation error already shaped
        }

        try {
            $result = $this->importer->preflight($bundle);

            return $this->successResponse([
                'report' => $result->toArray(),
                'bundle' => $bundle, // echoed back for step 2
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('ExchangeController::preflight failed', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Preflight failed: ' . $e->getMessage(), 500);
        }
    }

    // ── Import step 2: commit ───────────────────────────────────────────────

    /**
     * Import a bundle that already passed preflight. Runs its own preflight
     * again defensively; any error aborts before writing.
     * POST /admin/exchange/import   (json: { bundle: {...} })
     */
    public function import(Request $request): JsonResponse
    {
        $bundle = $request->input('bundle');

        if (!is_array($bundle)) {
            return $this->errorResponse('Missing or malformed bundle payload.', 422);
        }

        try {
            $result = $this->importer->import($bundle, $this->authService->getCurrentUserId());

            return $this->successResponse($result->toArray());

        } catch (ImportException $e) {
            // Blocking errors (e.g. a code collision that appeared between steps).
            // Nothing was written — the importer's transaction rolled back.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors'  => $e->errors,
            ], 422);

        } catch (\Throwable $e) {
            $this->logger->error('ExchangeController::import failed', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Import failed: ' . $e->getMessage(), 500);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Stream a bundle as a pretty-printed, unicode-preserving JSON download.
     * JSON_UNESCAPED_UNICODE keeps Cyrillic prompts readable in the file.
     */
    private function streamBundle(array $bundle, string $slug): StreamedResponse
    {
        $filename = "depthnet-{$slug}-" . now()->format('Ymd-His') . '.json';
        $json     = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return Response::streamDownload(
            fn () => print($json),
            $filename,
            [
                'Content-Type'        => 'application/json',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }

    /**
     * Read and JSON-decode the uploaded bundle file. Returns the decoded array,
     * or a shaped JsonResponse on any upload/parse problem (fail-closed: a bundle
     * we can't even parse never reaches preflight).
     *
     * @return array|JsonResponse
     */
    private function readUploadedBundle(Request $request): array|JsonResponse
    {
        $request->validate([
            'bundle' => 'required|file|mimetypes:application/json,text/plain|max:10240', // 10 MB
        ]);

        $raw = file_get_contents($request->file('bundle')->getRealPath());

        if ($raw === false || trim($raw) === '') {
            return $this->errorResponse('Uploaded file is empty or unreadable.', 422);
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $this->errorResponse('File is not valid JSON: ' . json_last_error_msg(), 422);
        }

        return $decoded;
    }

    protected function successResponse($data = null): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    protected function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
