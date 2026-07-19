<?php

namespace App\Services\Agent\Capabilities\Speech\Drivers;

use App\Contracts\Agent\Capabilities\TtsProviderInterface;

/**
 * Browser speechSynthesis provider.
 *
 * Config only — does NOT implement SynthesizesSpeechInterface. The audio is
 * rendered by the user's operating system through the Web Speech API; the
 * backend never sees bytes.
 *
 * Note the small config surface, and that this is fine. There is no API key, no
 * endpoint, and no server-side voice list — voices come from the user's machine
 * and are enumerated client-side. Inventing fields here just to look symmetrical
 * with the server drivers would be worse than an honest three-setting form.
 *
 * Voice selection is intentionally absent: available voices differ per machine
 * and per browser, so the picker belongs in the frontend where
 * speechSynthesis.getVoices() can actually answer.
 */
class BrowserTtsProvider implements TtsProviderInterface
{
    private float $rate;
    private float $pitch;
    private float $volume;
    private bool $autoSpeak;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->rate      = (float) ($config['rate']   ?? 1.0);
        $this->pitch     = (float) ($config['pitch']  ?? 1.0);
        $this->volume    = (float) ($config['volume'] ?? 1.0);
        $this->autoSpeak = (bool) ($config['auto_speak'] ?? true);
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
        return [
            'rate' => [
                'type'        => 'number',
                'label'       => 'Speech rate',
                'description' => '1.0 is normal. Below 1 is slower, above is faster.',
                'min'         => 0.5,
                'max'         => 2.0,
                'step'        => 0.1,
                'required'    => false,
            ],
            'pitch' => [
                'type'        => 'number',
                'label'       => 'Pitch',
                'description' => '1.0 is the voice default.',
                'min'         => 0.0,
                'max'         => 2.0,
                'step'        => 0.1,
                'required'    => false,
            ],
            'volume' => [
                'type'        => 'number',
                'label'       => 'Volume',
                'description' => '0.0 to 1.0.',
                'min'         => 0.0,
                'max'         => 1.0,
                'step'        => 0.1,
                'required'    => false,
            ],
            'auto_speak' => [
                'type'        => 'checkbox',
                'label'       => 'Speak new messages automatically',
                'description' => 'When off, messages are only spoken on demand via the '
                    . 'speaker button.',
                'required'    => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'rate'       => 1.0,
            'pitch'      => 1.0,
            'volume'     => 1.0,
            'auto_speak' => true,
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        $ranges = [
            'rate'   => [0.5, 2.0],
            'pitch'  => [0.0, 2.0],
            'volume' => [0.0, 1.0],
        ];

        foreach ($ranges as $key => [$min, $max]) {
            if (!isset($config[$key]) || $config[$key] === '') {
                continue;
            }
            $value = (float) $config[$key];
            if ($value < $min || $value > $max) {
                $errors[$key] = "Must be between {$min} and {$max}.";
            }
        }

        return $errors;
    }

    public function getClientConfig(): array
    {
        return [
            'execution_mode' => self::MODE_CLIENT,
            'driver'         => $this->getDriverName(),
            'rate'           => $this->rate,
            'pitch'          => $this->pitch,
            'volume'         => $this->volume,
            'auto_speak'     => $this->autoSpeak,
        ];
    }
}
