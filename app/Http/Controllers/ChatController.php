<?php

namespace App\Http\Controllers;

use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;
use App\Contracts\Auth\AuthServiceInterface;
use App\Contracts\Chat\ChatExporterServiceInterface;
use App\Contracts\Chat\ChatServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Contracts\Users\UserServiceInterface;
use App\Exceptions\PresetException;
use App\Http\Requests\Admin\Preset\UpdatePresetRequest;
use App\Http\Requests\Chat\ChatExportRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\UpdatePresetSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class ChatController extends Controller
{
    public function __construct(
        protected ChatServiceInterface $chatService,
        protected PresetServiceInterface $presetService,
        protected AuthServiceInterface $authService,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Chat index page
     *
     * @return \Inertia\Response
     */
    public function index(
        ChatExporterServiceInterface $chatExporterService,
        EngineRegistryInterface $engineRegistry,
        ChatStatusServiceInterface $chatStatusService,
        UserServiceInterface $userService,
        PluginRegistryInterface $pluginRegistry,
        ShortcodeManagerServiceInterface $shortcodeManager,
        OptionsServiceInterface $optionsService
    ) {
        $defaultPreset = $this->presetService->getDefaultPreset();
        $user = $this->authService->getCurrentUser();
        $users = $userService->getAllUsers();
        $toLabel = __('To');

        $data = [
            'messages' => [],
            'user' => $user,
            'presetMetadata' => $defaultPreset->metadata ?? [],
            'showAgentResults' => $optionsService->get('agent_show_results', true),
            'showCommandResults' => $optionsService->get('agent_show_commands', true)
        ];

        if ($user && $user->isAdmin()) {
            $chatActive = $chatStatusService->getChatStatus();

            // Get available presets (only active ones for regular use)
            $availablePresets = $this->presetService->getActivePresets();

            $pluginRegistry->setCurrentPreset($defaultPreset);
            $shortcodeManager->setDefaultShortcodes();
            $placeholders = $shortcodeManager->getRegisteredShortcodes();
            $engines = $engineRegistry->getAvailableEngines();

            $data = array_merge($data, [
                'availablePresets' => $availablePresets->map(fn ($preset) => [
                    'id' => $preset->id,
                    'name' => $preset->name,
                    'description' => $preset->description,
                    'engine_name' => $preset->engine_name,
                    'engine_display_name' => $preset->engine_display_name ?? $preset->engine_name,
                    'is_default' => $preset->is_default,
                    'model' => $preset->engine_config['model'] ?? null,
                    'metadata' => $preset->metadata ?? [],
                ])->toArray(),
                'currentPresetId' => $defaultPreset->getId(),
                'currentPreset' => $defaultPreset ? [
                    'id' => $defaultPreset->id,
                    'name' => $defaultPreset->name,
                    'engine_name' => $defaultPreset->engine_name,
                    'engine_display_name' => $defaultPreset->engine_display_name ?? $defaultPreset->engine_name,
                    'model' => $defaultPreset->engine_config['model'] ?? null,
                    'metadata' => $defaultPreset->metadata ?? [],
                ] : null,
                'chatActive' => $chatActive,
                'exportFormats' => array_values($chatExporterService->getAvailableFormats()),
                'engines' => $engines,
                'placeholders' => $placeholders
            ]);
        }

        $data = array_merge($data, [
            'users' => $users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'is_admin' => $user->is_admin ?? false,
            ])->toArray(),
            'toLabel' => $toLabel
        ]);

        return Inertia::render('Chat/Index', $data);
    }

    /**
     * Send a message to the chat from the user
     *
     * @param SendMessageRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function sendMessage(
        SendMessageRequest $request,
        AuthServiceInterface $authService
    ) {
        $user = $authService->getCurrentUser();

        $currentPreset = $this->presetService->getDefaultPreset();
        $this->chatService->sendUserMessage(
            $user,
            $currentPreset->getId(),
            $request->validated()['content']
        );

        return back();
    }

    /**
     * Clear the chat history based on request parameters
     *
     * @param \Illuminate\Http\Request $request
     * @param MemoryServiceInterface $memoryService
     * @param VectorMemoryServiceInterface $vectorMemoryService
     * @return RedirectResponse|JsonResponse
     */
    public function clearHistory(
        Request $request,
        MemoryServiceInterface $memoryService,
        VectorMemoryServiceInterface $vectorMemoryService
    ): JsonResponse|RedirectResponse {
        try {
            $currentPreset = $this->presetService->getDefaultPreset();
            $presetId = $currentPreset->getId();

            $cleared = [];

            $clearMessages = $request->boolean('clear_messages', true);
            if ($clearMessages) {
                $this->chatService->clearHistory($presetId);
                $cleared[] = 'messages';
            }

            if ($request->boolean('clear_memory')) {
                $memoryService->clearMemory($currentPreset);
                $cleared[] = 'memory';
            }

            if ($request->boolean('clear_vector_memory')) {
                $vectorMemoryService->clearVectorMemories($currentPreset);
                $cleared[] = 'vector_memory';
            }

            if (empty($cleared)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Nothing selected to clear'
                    ], 400);
                }
                return back()->withErrors(['error' => 'Nothing selected to clear']);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Selected items cleared successfully',
                    'cleared' => $cleared
                ]);
            }

            return back()->with('success', 'Selected items cleared successfully');
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear chat history', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'preset_id' => $presetId ?? null,
                'user_id' => $this->authService->getCurrentUserId(),
                'request_params' => $request->only(['clear_messages', 'clear_memory', 'clear_vector_memory'])
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to clear selected items: ' . $e->getMessage()
                ], 500);
            }

            return back()->withErrors(['error' => 'Failed to clear selected items']);
        }
    }

    /**
     * Get new messages since the last ID
     *
     * @param Request $request
     * @param int $lastId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNewMessages(Request $request, $lastId = 0): JsonResponse
    {
        $limit = $request->get('limit', 10);
        $limit = min($limit, 50);
        $presetId = $request->get('preset_id');

        $preset = $presetId
            ? $this->presetService->findById($presetId)
            : $this->presetService->getDefaultPreset();

        if (!$preset) {
            return response()->json([
                'success' => false,
                'error' => 'Preset not found',
                'messages' => []
            ], 404);
        }

        $messages = $this->chatService->getNewMessages(
            $preset->getId(),
            $lastId,
            $limit
        );

        $response = [
            'messages' => $messages,
        ];

        if (count($messages) > 0) {
            $preset->refresh();
            $response['presetMetadata'] = $preset->metadata ?? [];
        }

        return response()->json($response);
    }

    /**
     * Get paginated latest messages
     *
     * @param Request $request
     * @return void
     */
    public function getLatestMessages(Request $request): JsonResponse
    {
        try {
            $presetId = $request->get('preset_id');
            $perPage = min((int) $request->get('per_page', 30), 100);

            $preset = $presetId
                ? $this->presetService->findById($presetId)
                : $this->presetService->getDefaultPreset();

            if (!$preset) {
                return response()->json(['success' => false, 'error' => 'Preset not found'], 404);
            }

            $result = $this->chatService->getLatestMessagesWithPagination($preset->getId(), $perPage);

            return response()->json([
                'success' => true,
                'messages' => $result['messages'],
                'pagination' => $result['pagination'],
                'presetMetadata' => $preset->metadata ?? [],
                'presetId' => $preset->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching latest messages', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch messages',
                'messages' => [],
            ], 500);
        }
    }

    /**
     * Load older messages (any page)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function loadOlderMessages(Request $request): JsonResponse
    {
        try {
            $page = max(1, (int) $request->get('page', 1));
            $perPage = min((int) $request->get('per_page', 30), 100);
            $presetId = $request->get('preset_id');

            $preset = $presetId
                ? $this->presetService->findById($presetId)
                : $this->presetService->getDefaultPreset();

            if (!$preset) {
                return response()->json(['success' => false, 'error' => 'Preset not found'], 404);
            }

            $result = $this->chatService->getMessagesPaginatedEnhanced(
                $preset->getId(),
                $page,
                $perPage
            );

            return response()->json([
                'success' => true,
                'messages' => $result['messages'],
                'pagination' => $result['pagination'],
                'presetId' => $preset->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error loading older messages', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to load older messages',
                'messages' => [],
            ], 500);
        }
    }

    /**
     * Delete a specific message
     *
     * @param int $messageId
     * @return RedirectResponse
     */
    public function deleteMessage(int $messageId): RedirectResponse
    {
        $deleted = $this->chatService->deleteMessage($messageId);

        if ($deleted) {
            return back()->with('success', 'Message deleted successfully');
        }

        return back()->with('error', 'Message not found or could not be deleted');
    }

    /**
     * Update chat preset settings
     *
     * @param UpdatePresetSettingsRequest $request
     * @return RedirectResponse
     */
    public function updatePresetSettings(
        UpdatePresetSettingsRequest $request,
        AgentJobServiceInterface $agentJobService
    ): RedirectResponse {
        try {
            $validated = $request->validated();

            $success = $agentJobService->updateModelSettings(
                $validated['preset_id'],
                $validated['chat_active']
            );

            if ($success) {
                return back()->with('success', 'Model settings updated successfully');
            }

            return back()->with('error', 'Failed to update model settings');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to update chat settings: ' . $e->getMessage());
        }
    }

    /**
     * Update preset from chat page (stays on chat)
     *
     * @param UpdatePresetRequest $request
     * @param integer $id
     * @return JsonResponse
     */
    public function updatePreset(UpdatePresetRequest $request, int $id): JsonResponse
    {
        try {
            $this->presetService->updatePresetWithValidation($id, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Preset updated successfully'
            ]);
        } catch (PresetException $e) {
            return response()->json([
                'success' => false,
                'errors' => ['engine_config' => $e->getMessage()]
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the preset'
            ], 500);
        }
    }

    /**
     * Get current chat preset information
     *
     * @return JsonResponse
     */
    public function getCurrentPreset(): JsonResponse
    {
        $preset = $this->presetService->getDefaultPreset();

        if (!$preset) {
            return response()->json([
                'success' => false,
                'message' => 'No active preset found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'description' => $preset->description,
                'engine_name' => $preset->engine_name,
                'engine_display_name' => $preset->engine_display_name ?? $preset->engine_name,
                'model' => $preset->engine_config['model'] ?? null,
                'is_active' => $preset->is_active,
                'is_default' => $preset->is_default,
            ]
        ]);
    }

    /**
     * Export chat history
     *
     * @param ChatExportRequest $request
     * @return Response
     */
    public function exportChat(ChatExportRequest $request, ChatExporterServiceInterface $chatExporterService): Response
    {
        $params = $request->validated();

        $options = [
            'include_thinking' => $params['include_thinking']
        ];

        $currentPreset = $this->presetService->getDefaultPreset();
        return $chatExporterService->export($request['format'], $currentPreset->getId(), $options);
    }

    /**
     * Get user list
     *
     * @param UserServiceInterface $userService
     * @return JsonResponse
     */
    public function getUsers(UserServiceInterface $userService): JsonResponse
    {
        try {
            $users = $userService->getAllUsers();
            return response()->json($users);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch users'], 500);
        }
    }
}
