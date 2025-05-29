<?php

namespace App\Http\Controllers;

use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\ModelRegistryInterface;
use App\Contracts\Chat\ChatExporterServiceInterface;
use App\Contracts\Chat\ChatServiceInterface;
use App\Contracts\OptionsServiceInterface;
use App\Http\Requests\Chat\ChatExportRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\UpdateModelSettingsRequest;
use App\Jobs\ProcessAgentThinking;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;

class ChatController extends Controller
{
    public function __construct(
        protected ChatServiceInterface $chatService,
        protected OptionsServiceInterface $optionsService,
        protected ModelRegistryInterface $modelRegistry,
        protected ChatExporterServiceInterface $chatExporterService,
        protected AuthManager $auth,
    ) {
    }

    /**
     * Chat index page
     *
     * @return void
     */
    public function index(AgentJobServiceInterface $agentJobService)
    {

        $messages = $this->chatService->getAllMessages();
        $user = $this->auth->user();
        $mode = $this->optionsService->get('model_agent_mode', 'looped');

        $data = [
            'messages' => $messages,
            'user' => $user,
            'mode' => $mode
        ];

        if ($user && $user->isAdmin()) {
            $modelSettings = $agentJobService->getModelSettings();
            $availableModels = $this->modelRegistry->all();
            $data['availableModels'] = array_map(fn ($model) => [
                'name' => $model->getName(),
                'displayName' => $model->getName()
            ], $availableModels);
            $data['currentModel'] = $modelSettings['model_default'];
            $data['modelActive'] = $modelSettings['model_active'];
            $data['exportFormats'] = $this->chatExporterService->getAvailableFormats();
        }

        return Inertia::render('Chat/Index', $data);
    }

    /**
     * Send a message to the chat from the user
     *
     * @param SendMessageRequest $request
     * @return void
     */
    public function sendMessage(SendMessageRequest $request)
    {
        $user = $this->auth->user();

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
     * Update AI model settings
     *
     * @param UpdateModelSettingsRequest $request
     * @param AgentJobServiceInterface $agentJobService
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateModelSettings(
        UpdateModelSettingsRequest $request,
        AgentJobServiceInterface $agentJobService
    ) {
        $success = $agentJobService->updateModelSettings(
            $request->getModelName(),
            $request->isActive()
        );

        if ($success) {
            return back()->with('success', 'Model settings updated successfully');
        }

        return back()->with('error', 'Failed to update model settings');
    }

    /**
     * Export chat history
     *
     * @param ChatExportRequest $request
     * @return Response
     */
    public function exportChat(ChatExportRequest $request)
    {
        $params = $request->validated();

        $options = [
            'include_thinking' => $params['include_thinking']
        ];

        return $this->chatExporterService->export($request['format'], $options);
    }

}
