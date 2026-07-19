<?php

namespace App\Services\Agent\Capabilities\Speech\Drivers;

use App\Contracts\Agent\Capabilities\SttProviderInterface;
use App\Services\Agent\Capabilities\Speech\Traits\ProvidesWakeWordFields;

/**
 * Browser Web Speech API provider.
 *
 * Config only — deliberately does NOT implement TranscribesAudioInterface.
 * Recognition happens entirely in the user's browser via SpeechRecognition;
 * there is nothing for the backend to call. Asking this provider to transcribe
 * a Telegram voice message is a configuration mistake, and SttService reports
 * it as one rather than failing at runtime.
 *
 * What this provider DOES buy: the settings that were hardcoded in useVoice.js
 * (wake word, silence timeout, echo tail, language) become per-preset config,
 * editable in the GUI, with no behavioural change on the frontend.
 *
 * Availability caveat: Chrome and Edge implement SpeechRecognition, Firefox does
 * not, and Safari partially. The frontend feature-detects and hides voice input
 * when unsupported — the backend cannot know in advance.
 */
class BrowserSttProvider implements SttProviderInterface
{
    use ProvidesWakeWordFields;

    private string $language;
    private bool $interimResults;
    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->raw            = $config;
        $this->language       = $config['language'] ?? '';
        $this->interimResults = (bool) ($config['interim_results'] ?? true);
    }

    public function getDriverName(): string
    {
        return 'browser';
    }

    public function getDisplayName(): string
    {
        return 'Browser (Web Speech API)';
    }

    public function getExecutionMode(): string
    {
        return self::MODE_CLIENT;
    }

    public function getConfigFields(): array
    {
        return array_merge([
            'language' => [
                'type'        => 'select',
                'label'       => 'Recognition language',
                'description' => 'Language the browser recognizer expects. Leave on '
                    . '"Follow interface" to use the current UI locale.',
                'required'    => false,
                'options'     => [
                    ''      => 'Follow interface language',
                    'en-US' => 'English (US)',
                    'en-GB' => 'English (UK)',
                    'ru-RU' => 'Russian',
                    'fr-FR' => 'French',
                    'de-DE' => 'German',
                    'es-ES' => 'Spanish',
                ],
            ],
            'interim_results' => [
                'type'        => 'checkbox',
                'label'       => 'Show interim results',
                'description' => 'Display partial recognition while speaking. Turn off if '
                    . 'the flickering text is distracting.',
                'required'    => false,
            ],
            'send_to_pool' => [
                'type'        => 'checkbox',
                'label'       => 'Route recognized speech to input pool',
                'description' => 'When ON and the preset uses input-pool mode, recognized '
                    . 'speech enters the pool as a sensory input instead of being '
                    . 'posted as a plain user message.',
                'required'    => false,
            ],
        ], $this->wakeWordFields());
    }

    public function getDefaultConfig(): array
    {
        return array_merge([
            'language'        => '',
            'interim_results' => true,
            'send_to_pool'    => false,
        ], $this->wakeWordDefaults());
    }

    public function validateConfig(array $config): array
    {
        $errors = $this->validateWakeWordConfig($config);

        // 'server' wake mode needs a backend transcriber; this provider has none.
        // Caught here rather than producing silence at runtime.
        if (($config['wake_mode'] ?? 'client_sr') === 'server') {
            $errors['wake_mode'] = 'Server wake detection requires a server-side STT '
                . 'driver. Use browser recognition with this provider.';
        }

        return $errors;
    }

    public function getClientConfig(): array
    {
        return array_merge([
            'execution_mode'  => self::MODE_CLIENT,
            'driver'          => $this->getDriverName(),
            'language'        => $this->language,
            'interim_results' => $this->interimResults,
        ], $this->wakeWordClientConfig($this->raw));
    }
}
