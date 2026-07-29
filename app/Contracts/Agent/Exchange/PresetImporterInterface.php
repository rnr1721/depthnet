<?php

namespace App\Contracts\Agent\Exchange;

/**
 * Imports a "depthnet.bundle" produced by PresetExporterInterface.
 *
 * Two entry points mirror the two-phase HTTP flow:
 *   preflight() — validate only, write nothing. Feeds the UI preview and the
 *                 error/warning report. fail-closed: any ERROR blocks import.
 *   import()    — run the actual import inside a single transaction, after a
 *                 preflight that produced no errors.
 *
 * Secrets in the bundle are null by design (stripped at export). The importer
 * does NOT treat a missing key as an error — the user enters keys via the UI
 * after import.
 */
interface PresetImporterInterface
{
    /**
     * Validate a decoded bundle without touching the database.
     * Accumulates ALL problems (never stops at the first), split into blocking
     * errors and non-blocking warnings.
     *
     * @param  array $bundle  Decoded bundle (json_decode(..., true)).
     * @return \App\Services\Agent\Exchange\DTO\PreflightResult
     */
    public function preflight(array $bundle): \App\Services\Agent\Exchange\DTO\PreflightResult;

    /**
     * Import a bundle. Runs preflight first; if it yields any error, throws
     * ImportException without writing anything. Otherwise creates all presets,
     * their owned collections and any agents inside one transaction.
     *
     * @param  array    $bundle
     * @param  int|null $createdBy  User id to stamp on created presets/agents.
     * @return \App\Services\Agent\Exchange\DTO\ImportResult
     *
     * @throws \App\Exceptions\Exchange\ImportException on any blocking error.
     */
    public function import(array $bundle, ?int $createdBy = null): \App\Services\Agent\Exchange\DTO\ImportResult;
}
