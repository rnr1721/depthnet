<?php

namespace App\Services\Agent\Plugins\Services;

use App\Contracts\Agent\Plugins\NotepadServiceInterface;
use App\Contracts\OptionsServiceInterface;

class NotepadService implements NotepadServiceInterface
{
    private const MAX_SIZE = 5000;

    public function __construct(protected OptionsServiceInterface $optionsService)
    {
    }

    public function getNotepad(): string
    {
        return $this->optionsService->get('plugin_notepad_content', '');
    }
    public function setNotepad(string $content): self
    {
        if (mb_strlen($content) > self::MAX_SIZE) {
            throw new \InvalidArgumentException('Memory content exceeds maximum size of ' . self::MAX_SIZE . ' characters.');
        }
        $this->optionsService->set('plugin_notepad_content', $content);
        return $this;
    }
    public function clearNotepad(): self
    {
        $this->optionsService->remove('plugin_notepad_content');
        return $this;
    }
}
