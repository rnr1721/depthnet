<?php

namespace App\Services\Agent\Capabilities\Vision\Traits;

/**
 * Shared normalization config fields for vision providers.
 *
 * Normalization parameters are global to every vision driver (they describe how
 * an image is prepared before being sent to ANY vision model), so instead of
 * duplicating five field definitions in each provider, drivers mix in this trait
 * and merge its fields into their own getConfigFields()/getDefaultConfig().
 *
 * Stored flat in preset_capability_configs.config under these keys:
 *   norm_max_width   — max width in px  (0 = no width constraint)
 *   norm_max_height  — max height in px (0 = no height constraint)
 *   norm_mode        — which dimension(s) drive the resize: width|height|both
 *   norm_format      — output format fed to the model: jpeg|png|webp
 *   norm_quality     — output quality 1–100 (applies to jpeg/webp)
 *
 * VisionService reads these and applies them via ImageNormalizer before calling
 * the provider. Letting users see and tune them (rather than hiding behind magic
 * defaults) means they can trade quality for cost depending on their plan.
 */
trait ProvidesNormalizationFields
{
    protected function normalizationFields(): array
    {
        return [
            'norm_max_width' => [
                'type'        => 'number',
                'label'       => 'Max width (px)',
                'description' => 'Downscale wider images to this width. 0 = no limit. '
                    . 'Vision models downscale internally anyway (~1120px), so large values waste tokens.',
                'min'         => 0,
                'max'         => 8192,
                'required'    => false,
            ],
            'norm_max_height' => [
                'type'        => 'number',
                'label'       => 'Max height (px)',
                'description' => 'Downscale taller images to this height. 0 = no limit.',
                'min'         => 0,
                'max'         => 8192,
                'required'    => false,
            ],
            'norm_mode' => [
                'type'        => 'select',
                'label'       => 'Resize mode',
                'description' => 'Which dimension drives the resize (aspect ratio always preserved).',
                'required'    => false,
                'options'     => [
                    'both'   => 'Both — fit within max width AND height (recommended)',
                    'width'  => 'Width — constrain by width only',
                    'height' => 'Height — constrain by height only',
                ],
            ],
            'norm_format' => [
                'type'        => 'select',
                'label'       => 'Output format',
                'description' => 'Format the image is converted to before sending to the model.',
                'required'    => false,
                'options'     => [
                    'jpeg' => 'JPEG (smallest, recommended for photos)',
                    'png'  => 'PNG (lossless, larger)',
                    'webp' => 'WebP (small, modern)',
                ],
            ],
            'norm_quality' => [
                'type'        => 'number',
                'label'       => 'Quality (1–100)',
                'description' => 'Compression quality for JPEG/WebP. Lower = smaller payload = cheaper. '
                    . '85 is a good balance; drop to 60–70 to save on costly providers.',
                'min'         => 1,
                'max'         => 100,
                'required'    => false,
            ],
        ];
    }

    protected function normalizationDefaults(): array
    {
        return [
            'norm_max_width'  => 1120,
            'norm_max_height' => 1120,
            'norm_mode'       => 'both',
            'norm_format'     => 'jpeg',
            'norm_quality'    => 85,
        ];
    }

    private function explainHttp(string $vendor, int $status, string $body, string $model): string
    {
        $short = mb_substr(trim($body), 0, 200);

        $reason = match (true) {
            $status === 401 => 'invalid or missing API key',
            $status === 402 => 'insufficient balance / quota',
            $status === 403 => 'access forbidden (key lacks permission)',
            $status === 404 => "model '{$model}' not found",
            $status === 422 => 'invalid request (model may not support images)',
            $status === 429 => 'rate limited — slow down',
            $status >= 500  => 'provider server error, try again later',
            default         => 'request rejected',
        };

        return "{$vendor} API error ({$status}): {$reason}. {$short}";
    }
}
