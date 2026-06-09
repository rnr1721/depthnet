<?php

namespace App\Services\Agent\Capabilities\Vision;

use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use Psr\Log\LoggerInterface;

/**
 * Normalizes images before they are sent to a vision model.
 *
 * Resize (aspect-preserving), format conversion, and quality control — the
 * subset of image processing that vision needs. Adapted from the project's
 * Imagick-based ImageService but operating on ImageData (base64) in/out so it
 * fits the capability layer with no disk involvement.
 *
 * Graceful by design: if Imagick is missing or any step throws, the ORIGINAL
 * ImageData is returned unchanged. Vision must never break because of resizing —
 * worst case we send the un-normalized image (the model downscales anyway).
 *
 * Options (all optional, sensible fallbacks applied):
 *   max_width  int   px, 0/absent = no width constraint
 *   max_height int   px, 0/absent = no height constraint
 *   mode       string width|height|both (default both)
 *   format     string jpeg|png|webp (default jpeg)
 *   quality    int   1–100 (default 85)
 */
class ImageNormalizer
{
    private const DEFAULTS = [
        'max_width'  => 1120,
        'max_height' => 1120,
        'mode'       => 'both',
        'format'     => 'jpeg',
        'quality'    => 85,
    ];

    /** Map our format keys to Imagick format strings + output mime types. */
    private const FORMAT_MAP = [
        'jpeg' => ['imagick' => 'JPEG', 'mime' => 'image/jpeg'],
        'png'  => ['imagick' => 'PNG',  'mime' => 'image/png'],
        'webp' => ['imagick' => 'WEBP', 'mime' => 'image/webp'],
    ];

    public function __construct(
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Return a normalized copy of $image, or the original on any failure.
     *
     * @param  array<string,mixed>  $options
     */
    public function normalize(ImageData $image, array $options = []): ImageData
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            // No Imagick — send as-is. The model will downscale internally.
            return $image;
        }

        $opt    = $this->resolveOptions($options);
        $format = self::FORMAT_MAP[$opt['format']] ?? self::FORMAT_MAP['jpeg'];

        $imagick = null;
        try {
            $binary = base64_decode($image->getBase64(), true);
            if ($binary === false || $binary === '') {
                return $image;
            }

            $imagick = new \Imagick();
            $imagick->readImageBlob($binary);

            // Flatten multi-frame (animated) inputs to the first frame.
            if ($imagick->getNumberImages() > 1) {
                $imagick = $imagick->coalesceImages();
                $imagick->setIteratorIndex(0);
            }

            $this->applyResize($imagick, $opt);

            $imagick->setImageFormat($format['imagick']);

            if (in_array($opt['format'], ['jpeg', 'webp'], true)) {
                $imagick->setImageCompressionQuality($opt['quality']);
            }

            // Strip metadata (EXIF/orientation/etc.) — smaller payload, less leakage.
            // Note: do this AFTER resize; some pipelines need orientation first,
            // but Imagick honors orientation on read for most formats.
            $imagick->stripImage();

            $blob = $imagick->getImageBlob();

            return new ImageData(
                base64:      base64_encode($blob),
                mimeType:    $format['mime'],
                sourceLabel: $image->getSourceLabel(),
            );

        } catch (\Throwable $e) {
            $this->logger->warning('ImageNormalizer: normalization failed, using original — ' . $e->getMessage(), [
                'source' => $image->getSourceLabel(),
            ]);
            return $image;
        } finally {
            if ($imagick instanceof \Imagick) {
                $imagick->clear();
                $imagick->destroy();
            }
        }
    }

    /**
     * Resize aspect-preserving according to mode + max dimensions.
     * No-op when constraints are absent or the image already fits.
     */
    private function applyResize(\Imagick $imagick, array $opt): void
    {
        $maxW = (int) $opt['max_width'];
        $maxH = (int) $opt['max_height'];
        $mode = $opt['mode'];

        $w = $imagick->getImageWidth();
        $h = $imagick->getImageHeight();

        if ($w <= 0 || $h <= 0) {
            return;
        }

        $ratio = match ($mode) {
            'width'  => $maxW > 0 && $w > $maxW ? $maxW / $w : 1.0,
            'height' => $maxH > 0 && $h > $maxH ? $maxH / $h : 1.0,
            default  => $this->bothRatio($w, $h, $maxW, $maxH), // 'both'
        };

        if ($ratio >= 1.0) {
            return; // already fits — never upscale
        }

        $newW = max(1, (int) round($w * $ratio));
        $newH = max(1, (int) round($h * $ratio));

        $imagick->resizeImage($newW, $newH, \Imagick::FILTER_LANCZOS, 1);
    }

    private function bothRatio(int $w, int $h, int $maxW, int $maxH): float
    {
        $rw = $maxW > 0 ? $maxW / $w : PHP_FLOAT_MAX;
        $rh = $maxH > 0 ? $maxH / $h : PHP_FLOAT_MAX;
        $ratio = min($rw, $rh);

        return $ratio < 1.0 ? $ratio : 1.0;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function resolveOptions(array $options): array
    {
        $merged = array_merge(self::DEFAULTS, array_filter($options, fn ($v) => $v !== null && $v !== ''));

        $merged['format']  = isset(self::FORMAT_MAP[$merged['format']]) ? $merged['format'] : 'jpeg';
        $merged['mode']    = in_array($merged['mode'], ['width', 'height', 'both'], true) ? $merged['mode'] : 'both';
        $merged['quality'] = max(1, min(100, (int) $merged['quality']));

        return $merged;
    }
}
