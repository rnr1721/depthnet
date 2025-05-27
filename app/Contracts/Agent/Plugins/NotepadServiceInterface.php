<?php

namespace App\Contracts\Agent\Plugins;

interface NotepadServiceInterface
{
    /**
     * Get the notepad content
     *
     * @return string Text content of the notepad
     */
    public function getNotepad(): string;

    /**
     * Set the notepad content
     *
     * @param string $content Text content to be set in the notepad
     * @return self
     */
    public function setNotepad(string $content): self;

    /**
     * Clear the notepad content
     *
     * @return self
     */
    public function clearNotepad(): self;
}
