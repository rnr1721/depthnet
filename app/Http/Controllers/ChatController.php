<?php

namespace App\Http\Controllers;

use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\Cleanup\PresetCleanupServiceInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Orchestrator\AgentServiceInterface;
use App\Contracts\Agent\Orchestrator\AgentTaskServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
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
     * Chat index page.
     */
    public function index(
        ChatExporterServiceInterface $chatExporterService,
        EngineRegistryInterface $engineRegistry,
        ChatStatusServiceInterface $chatStatusService,
        UserServiceInterface $userService,
        PluginRegistryInterface $pluginRegistry,
        AgentServiceInterface $agentService,
        ShortcodeManagerServiceInterface $shortcodeManager,
        OptionsServiceInterface $optionsService
    ) {
        $defaultPreset = $this->presetService->getDefaultPreset();
        $user          = $this->authService->getCurrentUser();
        $users         = $userService->getAllUsers();
        $toLabel       = __('To');

        $data = [
            'messages'           => [],
            'user'               => $user,
            'presetMetadata'     => $defaultPreset->metadata ?? [],
            'showAgentResults'   => $optionsService->get('agent_show_results', true),
            'showCommandResults' => $optionsService->get('agent_show_commands', true),
            'currentPresetId'    => $defaultPreset->getId(),
            'currentPreset'      => $defaultPreset ? [
                'id'                  => $defaultPreset->id,
                'name'                => $defaultPreset->name,
                'engine_name'         => $defaultPreset->engine_name,
                'engine_display_name' => $defaultPreset->engine_display_name ?? $defaultPreset->engine_name,
                'model'               => $defaultPreset->engine_config['model'] ?? null,
                'metadata'            => $defaultPreset->metadata ?? [],
            ] : null,
            'agents' => $agentService->getAgentsForChat()
        ];

        if ($user && $user->isAdmin()) {
            $availablePresets = $this->presetService->getActivePresets();

            $pluginRegistry->applyPreset($defaultPreset);
            $shortcodeManager->setDefaultShortcodes($defaultPreset);
            $placeholders = $shortcodeManager->getRegisteredShortcodes();
            $engines      = $engineRegistry->getAvailableEngines();

            // Build per-preset active status map: { presetId: bool }
            $presetActiveMap = [];
            foreach ($availablePresets as $preset) {
                $presetActiveMap[$preset->id] = $chatStatusService->getPresetStatus($preset->id);
            }

            $data = array_merge($data, [
                'availablePresets' => $availablePresets->map(fn ($preset) => [
                    'id'                      => $preset->id,
                    'name'                    => $preset->name,
                    'description'             => $preset->description,
                    'engine_name'             => $preset->engine_name,
                    'engine_display_name'     => $preset->engine_display_name ?? $preset->engine_name,
                    'is_default'              => $preset->is_default,
                    'model'                   => $preset->engine_config['model'] ?? null,
                    'metadata'                => $preset->metadata ?? [],
                    'preset_code'             => $preset->preset_code,
                    'rag_preset_id'           => $preset->rag_preset_id,
                    'voice_preset_id'         => $preset->voice_preset_id,
                    'cycle_prompt_preset_id'  => $preset->cycle_prompt_preset_id,
                    'voice_mp_commands'       => $preset->voice_mp_commands,
                    'chat_active'             => $presetActiveMap[$preset->id] ?? false,
                ])->toArray(),
                // Legacy global flag: true if at least one preset is active
                'chatActive'    => $chatStatusService->getChatStatus(),
                'exportFormats' => array_values($chatExporterService->getAvailableFormats()),
                'engines'       => $engines,
                'placeholders'  => $placeholders,
            ]);
        }

        $data = array_merge($data, [
            'users'   => $users->map(fn ($user) => [
                'id'       => $user->id,
                'name'     => $user->name,
                'is_admin' => $user->is_admin ?? false,
            ])->toArray(),
            'toLabel' => $toLabel,
        ]);

        return Inertia::render('Chat/Index', $data);
    }

    /**
     * Send a message to the chat from the user.
     *
     * The message is sent to whichever preset is currently selected by the user.
     * The loop for that preset is started inside ChatService if needed.
     */
    public function sendMessage(
        SendMessageRequest $request,
        AuthServiceInterface $authService,
        ChatStatusServiceInterface $chatStatusService
    ) {
        $user     = $authService->getCurrentUser();
        $presetId = (int) $request->input('preset_id', 0);

        // Fall back to default preset when no preset_id is provided
        if (!$presetId) {
            $presetId = $this->presetService->getDefaultPreset()->getId();
        }

        // In cycle mode the pool accumulates; dispatch=true flushes it immediately
        $presetActive = $chatStatusService->getPresetStatus($presetId);
        $dispatch = !$presetActive;

        $this->chatService->sendUserMessage(
            $user,
            $presetId,
            $request->validated()['content'],
            $dispatch
        );

        return back();
    }

    /**
     * Clear the chat history for the currently selected preset.
     * Optionally clear additional data (memory, vector memory, workspace, etc.)
     * If clear_agent is true and the preset belongs to an agent, clears all agent presets.
     */
    public function clearHistory(
        Request $request,
        PresetCleanupServiceInterface $cleanupService,
        AgentTaskServiceInterface $agentTaskService
    ): JsonResponse|RedirectResponse {
        try {
            $presetId = (int) $request->input('preset_id', 0);

            if (!$presetId) {
                $currentPreset = $this->presetService->getDefaultPreset();
                $presetId      = $currentPreset->getId();
            } else {
                $currentPreset = $this->presetService->findById($presetId);
            }

            if (!$currentPreset) {
                return response()->json(['success' => false, 'message' => 'Preset not found'], 404);
            }

            $options = $request->all();
            $cleared = [];

            if ($request->boolean('clear_agent')) {
                $agent = $agentTaskService->findAgentForPreset($currentPreset);
                if ($agent) {
                    $agentCleared = $cleanupService->clearAgent($agent, $options);
                    foreach ($agentCleared as $presetName => $items) {
                        foreach ($items as $item) {
                            $cleared[] = "{$presetName}:{$item}";
                        }
                    }
                } else {
                    $cleared = $cleanupService->clearPreset($currentPreset, $options);
                }
            } else {
                $cleared = $cleanupService->clearPreset($currentPreset, $options);
            }

            if (empty($cleared)) {
                if ($request->expectsJson()) {
                    return response()->json(['success' => false, 'message' => 'Nothing selected to clear'], 400);
                }
                return back()->withErrors(['error' => 'Nothing selected to clear']);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Selected items cleared successfully',
                    'cleared' => $cleared,
                ]);
            }

            return back()->with('success', 'Selected items cleared successfully');

        } catch (\Exception $e) {
            $this->logger->error('Failed to clear chat history', [
                'error'     => $e->getMessage(),
                'preset_id' => $presetId ?? null,
            ]);

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Failed to clear: ' . $e->getMessage()], 500);
            }

            return back()->withErrors(['error' => 'Failed to clear: ' . $e->getMessage()]);
        }
    }

    /**
     * Get new messages after a given message ID for the specified preset.
     */
    public function getNewMessages(Request $request, int $lastMessageId): JsonResponse
    {
        try {
            $presetId = (int) $request->get('preset_id', 0);
            $limit    = min((int) $request->get('limit', 50), 200);

            $preset = $presetId
                ? $this->presetService->findById($presetId)
                : $this->presetService->getDefaultPreset();

            if (!$preset) {
                return response()->json(['success' => false, 'error' => 'Preset not found'], 404);
            }

            $messages = $this->chatService->getNewMessages($preset->getId(), $lastMessageId, $limit);

            $response = ['messages' => $messages];

            if (count($messages) > 0) {
                $preset->refresh();
                $response['presetMetadata'] = $preset->metadata ?? [];
            }

            return response()->json($response);

        } catch (\Exception $e) {
            $this->logger->error('Error fetching new messages', ['error' => $e->getMessage()]);
            return response()->json(['messages' => []], 500);
        }
    }

    /**
     * Get paginated latest messages.
     */
    public function getLatestMessages(Request $request): JsonResponse
    {
        try {
            $presetId = $request->get('preset_id');
            $perPage  = min((int) $request->get('per_page', 30), 100);

            $preset = $presetId
                ? $this->presetService->findById($presetId)
                : $this->presetService->getDefaultPreset();

            if (!$preset) {
                return response()->json(['success' => false, 'error' => 'Preset not found'], 404);
            }

            $result = $this->chatService->getLatestMessagesWithPagination($preset->getId(), $perPage);

            return response()->json([
                'success'        => true,
                'messages'       => $result['messages'],
                'pagination'     => $result['pagination'],
                'presetMetadata' => $preset->metadata ?? [],
                'presetId'       => $preset->getId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching latest messages', ['error' => $e->getMessage()]);
            return response()->json([
                'success'  => false,
                'error'    => 'Failed to fetch messages',
                'messages' => [],
            ], 500);
        }
    }

    /**
     * Load older messages (any page).
     */
    public function loadOlderMessages(Request $request): JsonResponse
    {
        try {
            $page     = max(1, (int) $request->get('page', 1));
            $perPage  = min((int) $request->get('per_page', 30), 100);
            $presetId = $request->get('preset_id');

            $preset = $presetId
                ? $this->presetService->findById($presetId)
                : $this->presetService->getDefaultPreset();

            if (!$preset) {
                return response()->json(['success' => false, 'error' => 'Preset not found'], 404);
            }

            $result = $this->chatService->getMessagesPaginatedEnhanced($preset->getId(), $page, $perPage);

            return response()->json([
                'success'    => true,
                'messages'   => $result['messages'],
                'pagination' => $result['pagination'],
                'presetId'   => $preset->getId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error loading older messages', ['error' => $e->getMessage()]);
            return response()->json([
                'success'  => false,
                'error'    => 'Failed to load older messages',
                'messages' => [],
            ], 500);
        }
    }

    /**
     * Delete a specific message.
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
     * Update chat preset settings (toggle loop on/off for a specific preset).
     *
     * Request must include: preset_id (int), chat_active (bool).
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
     * Update preset from chat page (stays on chat).
     */
    public function updatePreset(UpdatePresetRequest $request, int $id): JsonResponse
    {
        try {
            $this->presetService->updatePresetWithValidation($id, $request->validated());

            return response()->json(['success' => true, 'message' => 'Preset updated successfully']);

        } catch (PresetException $e) {
            return response()->json([
                'success' => false,
                'errors'  => ['engine_config' => $e->getMessage()],
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the preset',
            ], 500);
        }
    }

    /**
     * Get current chat preset information.
     */
    public function getCurrentPreset(): JsonResponse
    {
        $preset = $this->presetService->getDefaultPreset();

        if (!$preset) {
            return response()->json(['success' => false, 'message' => 'No active preset found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                  => $preset->id,
                'name'                => $preset->name,
                'description'         => $preset->description,
                'engine_name'         => $preset->engine_name,
                'engine_display_name' => $preset->engine_display_name ?? $preset->engine_name,
                'model'               => $preset->engine_config['model'] ?? null,
                'is_active'           => $preset->is_active,
                'is_default'          => $preset->is_default,
            ],
        ]);
    }

    /**
     * Export chat history.
     */
    public function exportChat(ChatExportRequest $request, ChatExporterServiceInterface $chatExporterService): Response
    {
        $params        = $request->validated();
        $presetId      = (int) ($params['preset_id'] ?? 0);
        $currentPreset = $presetId
            ? $this->presetService->findById($presetId)
            : $this->presetService->getDefaultPreset();

        return $chatExporterService->export($params['format'], $currentPreset->getId());
    }

    /**
     * Get user list.
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
