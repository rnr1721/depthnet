<?php

namespace App\Services\Agent\Code;

use App\Contracts\Agent\Code\LspServiceInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Services\Agent\Code\DTO\LspDiagnostic;
use App\Services\Agent\Code\DTO\LspLocation;
use App\Services\Agent\Code\DTO\LspStartResult;
use App\Services\Agent\Code\DTO\LspStatus;
use Psr\Log\LoggerInterface;

/**
 * LspService manages the lifecycle and interactions with a Language Server Protocol (LSP) runner inside a sandbox.
 * It provides methods to start/stop the LSP server, check its status, and perform code intelligence operations like
 * finding references, definitions, hover info, symbols, and diagnostics.
 *
 * The service relies on a command-line LSP runner that must be present in the sandbox at /home/sandbox-user/.local/bin/lsp-runner.
 * This runner is expected to handle commands for starting/stopping the server and processing LSP requests via stdin.
 */
class LspService implements LspServiceInterface
{
    private const RUNNER_PATH = '/home/sandbox-user/.local/bin/lsp-runner';

    public function __construct(
        protected SandboxManagerInterface $sandboxManager,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function start(string $sandboxId, string $user, int $timeout, string $workingDir = '/home/sandbox-user'): LspStartResult
    {
        $check = trim($this->exec($sandboxId, "test -x " . self::RUNNER_PATH . " && echo ok || echo missing", $user, 3));
        if ($check !== 'ok') {
            return new LspStartResult(false, 'LSP runner not found in sandbox. Add lsp-runner to Dockerfile.');
        }

        $output = trim($this->exec(
            $sandboxId,
            "cd " . escapeshellarg($workingDir) . " && " . self::RUNNER_PATH . " start 2>&1",
            $user,
            10
        ));

        if (str_starts_with($output, 'ERROR:')) {
            return new LspStartResult(false, $output);
        }

        $language = trim($this->exec($sandboxId, self::RUNNER_PATH . " language 2>/dev/null || echo unknown", $user, 3));
        $pidOutput = trim($this->exec($sandboxId, self::RUNNER_PATH . " status 2>/dev/null", $user, 3));

        $pid = 0;
        if (preg_match('/PID:\s*(\d+)/', $pidOutput, $m)) {
            $pid = (int) $m[1];
        }

        return new LspStartResult(true, $output, $language, $pid);
    }

    /**
     * @inheritDoc
     */
    public function stop(string $sandboxId, string $user, int $timeout): bool
    {
        $this->exec($sandboxId, self::RUNNER_PATH . " stop 2>/dev/null || true", $user, 3);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function status(string $sandboxId, string $user, int $timeout): LspStatus
    {
        $output = trim($this->exec($sandboxId, "test -S /tmp/lsp.sock && echo running || echo stopped", $user, 3));

        if ($output !== 'running') {
            return new LspStatus(false);
        }

        $language = trim($this->exec($sandboxId, "cat /tmp/lsp-language 2>/dev/null || echo unknown", $user, 3));

        return new LspStatus(true, $language);
    }

    /**
     * @inheritDoc
     */
    public function isRunning(string $sandboxId, string $user, int $timeout): bool
    {
        $output = trim($this->exec($sandboxId, "test -S /tmp/lsp.sock && echo running || echo stopped", $user, 3));
        return $output === 'running';
    }

    // ── Code Intelligence (position-based via symbol lookup) ──────────

    /**
     * Find line/col for a named symbol in a file via documentSymbol.
     * Returns null if the symbol is not found.
     *
     * @param string $sandboxId
     * @param string $file
     * @param string $symbol
     * @param string $user
     * @param integer $timeout
     * @return array|null
     */
    private function findSymbolPosition(string $sandboxId, string $file, string $symbol, string $user, int $timeout): ?array
    {
        $symbols = $this->symbols($sandboxId, $file, $user, $timeout);

        foreach ($symbols as $loc) {
            if ($loc->context === $symbol) {
                return ['line' => $loc->line, 'character' => $loc->col];
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function references(string $sandboxId, string $file, string $symbol, string $user, int $timeout): array
    {
        $pos = $this->findSymbolPosition($sandboxId, $file, $symbol, $user, $timeout);
        if ($pos === null) {
            return [];
        }

        $result = $this->lspRequest($sandboxId, 'references', $file, $pos, $user, $timeout);
        return $this->parseLocations($result);
    }

    /**
     * @inheritDoc
     */
    public function definition(string $sandboxId, string $file, string $symbol, string $user, int $timeout): ?LspLocation
    {
        $pos = $this->findSymbolPosition($sandboxId, $file, $symbol, $user, $timeout);
        if ($pos === null) {
            return null;
        }

        $result = $this->lspRequest($sandboxId, 'definition', $file, $pos, $user, $timeout);
        $locations = $this->parseLocations($result);
        return $locations[0] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function hover(string $sandboxId, string $file, string $symbol, string $user, int $timeout): string
    {
        $pos = $this->findSymbolPosition($sandboxId, $file, $symbol, $user, $timeout);
        if ($pos === null) {
            return '';
        }

        $result = $this->lspRequest($sandboxId, 'hover', $file, $pos, $user, $timeout);
        return $result['hover'] ?? $result['result'] ?? '';
    }

    /**
     * @inheritDoc
     */
    public function symbols(string $sandboxId, string $path, string $user, int $timeout): array
    {
        $result = $this->lspRequest($sandboxId, 'symbols', $path, [], $user, $timeout);
        return $this->parseLocations($result);
    }

    /**
     * @inheritDoc
     */
    public function diagnostics(string $sandboxId, string $path, string $user, int $timeout): array
    {
        $result = $this->lspRequest($sandboxId, 'diagnostics', $path, [], $user, $timeout);

        if (empty($result['results'])) {
            return [];
        }

        return array_map(
            fn ($d) => new LspDiagnostic(
                $d['file'] ?? '',
                $d['line'] ?? 0,
                $d['message'] ?? '',
                $d['severity'] ?? 'error',
            ),
            $result['results']
        );
    }

    // ── Private ──────────────────────────────────────────────

    /**
     * Sends a request to the LSP runner in the sandbox and returns the decoded JSON response.
     * Returns null if the response is empty or not valid JSON.
     *
     * @param string $sandboxId
     * @param string $method
     * @param string $file
     * @param array $extra
     * @param string $user
     * @param integer $timeout
     * @return array|null
     */
    private function lspRequest(string $sandboxId, string $method, string $file, array $extra, string $user, int $timeout): ?array
    {
        $request = array_merge([
            'method' => $method,
            'file' => $file,
            'line' => 1,
            'character' => 1,
        ], $extra);

        $json = json_encode($request, JSON_UNESCAPED_SLASHES);

        $output = trim($this->exec(
            $sandboxId,
            "echo " . escapeshellarg($json) . " | " . self::RUNNER_PATH . " request 2>/dev/null",
            $user,
            $timeout
        ));

        if (empty($output)) {
            return null;
        }

        $result = json_decode($output, true);
        return is_array($result) ? $result : ['result' => $output];
    }

    /**
     * Parses the LSP location results into an array of LspLocation objects.
     *
     * @param array|null $result
     * @return LspLocation[]
     */
    private function parseLocations(?array $result): array
    {
        if (empty($result['results'])) {
            return [];
        }

        return array_map(
            fn ($loc) => new LspLocation(
                $loc['file'] ?? '',
                $loc['line'] ?? 0,
                $loc['col'] ?? 0,
                $loc['context'] ?? '',
            ),
            $result['results']
        );
    }

    /**
     * Executes a command in the sandbox and returns trimmed output.
     * Logs errors and returns empty string on failure.
     *
     * @param string $sandboxId
     * @param string $cmd
     * @param string $user
     * @param integer $timeout
     * @return string
     */
    private function exec(string $sandboxId, string $cmd, string $user, int $timeout): string
    {
        try {
            $result = $this->sandboxManager->executeCommand($sandboxId, $cmd, $user, $timeout);
            return trim($result->output ?: '');
        } catch (\Throwable $e) {
            $this->logger->error('LspService::exec error', [
                'sandbox_id' => $sandboxId,
                'command' => substr($cmd, 0, 200),
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }
}
