<?php

namespace App\Services\Agent\Capabilities\Speech\Traits;

/**
 * Shared wake-word / interactive-voice config fields for STT providers.
 *
 * Same pattern as ProvidesNormalizationFields in the vision capability: every
 * STT driver merges these into getConfigFields() so the settings look identical
 * regardless of which driver is selected.
 *
 * SCOPE NOTE — these apply to INTERACTIVE VOICE CHANNELS only (the web chat with
 * an open microphone). A Telegram voice message needs no wake word: sending the
 * message IS the activation. Today there is one such channel, so these live in
 * the STT config; when a second channel appears these five fields are the ones
 * that move to a per-channel config.
 */
trait ProvidesWakeWordFields
{
    /**
     * @return array<string, array<string, mixed>>
     */
    protected function wakeWordFields(): array
    {
        return [
            'wake_enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable wake word',
                'description' => 'Listen in the background and start dictation when the '
                    . 'wake word is heard. Applies to interactive voice channels '
                    . '(web chat) only — messenger voice messages ignore this.',
                'required'    => false,
            ],
            'wake_words' => [
                'type'        => 'text',
                'label'       => 'Wake words',
                'description' => 'Comma-separated. Leave empty to use the preset code. '
                    . 'Latin and Cyrillic spellings are matched interchangeably.',
                'placeholder' => 'flash, флэш',
                'required'    => false,
            ],
            'wake_mode' => [
                'type'        => 'select',
                'label'       => 'Wake word detection',
                'description' => 'Where the wake word is matched. Browser recognition is '
                    . 'lower latency; server transcription works in browsers '
                    . 'without the Web Speech API.',
                'required'    => false,
                'options'     => [
                    'client_sr' => 'Browser recognition (low latency)',
                    'server'    => 'Server transcription (works everywhere)',
                ],
            ],
            'silence_ms' => [
                'type'        => 'number',
                'label'       => 'Silence before finalizing (ms)',
                'description' => 'How long to wait after speech stops before treating the '
                    . 'phrase as finished.',
                'min'         => 300,
                'max'         => 10000,
                'required'    => false,
            ],
            'echo_tail_ms' => [
                'type'        => 'number',
                'label'       => 'Echo suppression tail (ms)',
                'description' => 'How long after the agent stops speaking before the mic '
                    . 'reopens. Raise it if the agent transcribes its own voice.',
                'min'         => 0,
                'max'         => 5000,
                'required'    => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function wakeWordDefaults(): array
    {
        return [
            'wake_enabled' => false,
            'wake_words'   => '',
            'wake_mode'    => 'client_sr',
            'silence_ms'   => 1500,
            'echo_tail_ms' => 1200,
        ];
    }

    /**
     * Wake settings in the shape useVoice() expects. Safe for the browser —
     * contains no secrets by construction.
     *
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function wakeWordClientConfig(array $config): array
    {
        $words = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,|]/', (string) ($config['wake_words'] ?? '')) ?: []
        )));

        return [
            'wake_enabled' => (bool) ($config['wake_enabled'] ?? false),
            // Array, not a raw string: useVoice already accepts an array and
            // this avoids re-parsing the same separators in JS.
            'wake_words'   => $words,
            'wake_mode'    => $config['wake_mode']    ?? 'client_sr',
            'silence_ms'   => (int) ($config['silence_ms']   ?? 1500),
            'echo_tail_ms' => (int) ($config['echo_tail_ms'] ?? 1200),
        ];
    }

    /**
     * @param  array<string, mixed> $config
     * @return array<string, string> field => error
     */
    protected function validateWakeWordConfig(array $config): array
    {
        $errors = [];

        $mode = $config['wake_mode'] ?? 'client_sr';
        if (!in_array($mode, ['client_sr', 'server'], true)) {
            $errors['wake_mode'] = 'Wake mode must be client_sr or server.';
        }

        foreach (['silence_ms' => [300, 10000], 'echo_tail_ms' => [0, 5000]] as $key => [$min, $max]) {
            if (!isset($config[$key]) || $config[$key] === '') {
                continue;
            }
            $value = (int) $config[$key];
            if ($value < $min || $value > $max) {
                $errors[$key] = "Must be between {$min} and {$max}.";
            }
        }

        return $errors;
    }
}
