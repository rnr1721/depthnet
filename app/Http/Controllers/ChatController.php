<?php

namespace App\Http\Controllers;

use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Auth\AuthServiceInterface;
use App\Contracts\Chat\ChatExporterServiceInterface;
use App\Contracts\Chat\ChatServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Users\UserServiceInterface;
use App\Exceptions\PresetException;
use App\Http\Requests\Admin\Preset\UpdatePresetRequest;
use App\Http\Requests\Chat\ChatExportRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\UpdatePresetSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChatController extends Controller
{
    public function __construct(
        protected ChatServiceInterface $chatService,
        protected PresetServiceInterface $presetService,
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
        AuthServiceInterface $authService,
        ChatStatusServiceInterface $chatStatusService,
        UserServiceInterface $userService,
        PluginRegistryInterface $pluginRegistry,
        ShortcodeManagerServiceInterface $shortcodeManager
    ) {
        $messages = $this->chatService->getAllMessages();
        $user = $authService->getCurrentUser();
        $users = $userService->getAllUsers();
        $toLabel = __('To');

        $data = [
            'messages' => $messages,
            'user' => $user
        ];

        if ($user && $user->isAdmin()) {
            $chatActive = $chatStatusService->getChatStatus();

            // Get available presets (only active ones for regular use)
            $availablePresets = $this->presetService->getActivePresets();

            $defaultPreset = $this->presetService->getDefaultPreset();

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
                ])->toArray(),
                'currentPresetId' => $defaultPreset->getId(),
                'currentPreset' => $defaultPreset ? [
                    'id' => $defaultPreset->id,
                    'name' => $defaultPreset->name,
                    'engine_name' => $defaultPreset->engine_name,
                    'engine_display_name' => $defaultPreset->engine_display_name ?? $defaultPreset->engine_name,
                    'model' => $defaultPreset->engine_config['model'] ?? null,
                ] : null,
                'chatActive' => $chatActive,
                'exportFormats' => $chatExporterService->getAvailableFormats(),
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

        $this->chatService->sendUserMessage(
            $user,
            $request->validated()['content']
        );

        return back();
    }

    /**
     * Clear the chat history
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function clearHistory()
    {
        $this->chatService->clearHistory();

        return back();
    }

    /**
     * Get new messages since the last ID
     *
     * @param Request $request
     * @param int $lastId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNewMessages(Request $request, $lastId = 0)
    {
        $messages = $this->chatService->getNewMessages($lastId);

        return response()->json($messages);
    }

    /**
     * Delete a specific message
     *
     * @param int $messageId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deleteMessage(int $messageId)
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
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updatePresetSettings(
        UpdatePresetSettingsRequest $request,
        AgentJobServiceInterface $agentJobService
    ) {
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
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrentPreset()
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
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function exportChat(ChatExportRequest $request, ChatExporterServiceInterface $chatExporterService)
    {
        $params = $request->validated();

        $options = [
            'include_thinking' => $params['include_thinking']
        ];

        return $chatExporterService->export($request['format'], $options);
    }

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
