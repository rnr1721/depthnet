<?php

declare(strict_types=1);

namespace App\Contracts\Sandbox;

use App\Services\Sandbox\DTO\CodeExecutionResult;
use App\Services\Sandbox\DTO\InstallationResult;
use App\Services\Sandbox\DTO\SandboxEnvironment;

/**
 * High-level sandbox service interface
 */
interface SandboxServiceInterface
{
    /**
     * Execute code in a sandbox with automatic environment setup
     *
     * @param string $code Code to execute
     * @param string $language Programming language (python, javascript, php, etc.)
     * @param array $options Additional execution options
     * @return CodeExecutionResult
     * @throws SandboxException
     */
    public function executeCode(string $code, string $language, array $options = []): CodeExecutionResult;

    /**
     * Execute code in existing sandbox
     *
     * @param string $sandboxId Existing sandbox ID
     * @param string $code Code to execute
     * @param string $language Programming language
     * @param array $options Additional execution options
     * @return CodeExecutionResult
     * @throws SandboxException
     */
    public function executeCodeInSandbox(
        string $sandboxId,
        string $code,
        string $language,
        array $options = []
    ): CodeExecutionResult;

    /**
     * Create persistent sandbox for multiple operations
     *
     * @param string $language Primary language for sandbox
     * @param array $requirements Additional requirements/packages
     * @param array $options Sandbox configuration options
     * @return string Sandbox ID
     * @throws SandboxException
     */
    public function createPersistentSandbox(
        string $language,
        array $requirements = [],
        array $options = []
    ): string;

    /**
     * Install packages in sandbox
     *
     * @param string $sandboxId Sandbox identifier
     * @param array $packages Packages to install
     * @param string $language Language package manager to use
     * @return InstallationResult
     * @throws SandboxException
     */
    public function installPackages(
        string $sandboxId,
        array $packages,
        string $language
    ): InstallationResult;

    /**
     * Get sandbox environment info
     *
     * @param string $sandboxId Sandbox identifier
     * @return SandboxEnvironment
     * @throws SandboxException
     */
    public function getSandboxEnvironment(string $sandboxId): SandboxEnvironment;

    /**
     * Upload file to sandbox
     *
     * @param string $sandboxId Sandbox identifier
     * @param string $filePath Path in sandbox
     * @param string $content File content
     * @return bool
     * @throws SandboxException
     */
    public function uploadFile(string $sandboxId, string $filePath, string $content): bool;

    /**
     * Download file from sandbox
     *
     * @param string $sandboxId Sandbox identifier
     * @param string $filePath Path in sandbox
     * @return string File content
     * @throws SandboxException
     */
    public function downloadFile(string $sandboxId, string $filePath): string;

    /**
     * Get supported languages and their configurations
     *
     * @return array
     */
    public function getSupportedLanguages(): array;

    /**
     * Set user for operations
     *
     * @param string $user
     * @return self
     */
    public function setUser(string $user): self;
}
