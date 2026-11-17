<?php

namespace App\Services\Flows;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Imagick;
use RuntimeException;
use Throwable;

class FlowImageOptimizer
{
    /**
     * Return a smaller static image, or null to store the upload unchanged.
     *
     * @return array{contents: string, mime_type: string, original_name: string}|null
     */
    public function optimize(UploadedFile $file, string $mime): ?array
    {
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return null;
        }

        $image = new Imagick;
        $limits = [];

        try {
            foreach ([
                Imagick::RESOURCETYPE_MEMORY => 256 * 1024 * 1024,
                Imagick::RESOURCETYPE_MAP => 512 * 1024 * 1024,
                Imagick::RESOURCETYPE_DISK => 1024 * 1024 * 1024,
                Imagick::RESOURCETYPE_THREAD => 1,
            ] as $resource => $limit) {
                $limits[$resource] = $image->getResourceLimit($resource);
                $image->setResourceLimit($resource, min($limit, $limits[$resource]));
            }

            $original = $file->getContent();
            // Some PNG decoders expose only the first APNG frame. Inspect chunks
            // as well, so optimization cannot silently discard an animation.
            if ($mime === 'image/png' && $this->isAnimatedPng($original)) {
                return null;
            }

            $image->pingImageBlob($original);
            if ($image->getNumberImages() !== 1) {
                return null;
            }
            $this->assertSafeDimensions($image);
            $image->clear();
            $image->readImageBlob($original);
            $this->assertSafeDimensions($image);
            foreach (['autoOrient', 'autoOrientImage', 'autoOrientate'] as $method) {
                if (method_exists($image, $method)) {
                    $image->{$method}();
                    break;
                }
            }
            $image->setImagePage(0, 0, 0, 0);
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);

            if (max($image->getImageWidth(), $image->getImageHeight()) > 1600) {
                $image->thumbnailImage(1600, 1600, true);
            }

            $transparent = $image->getImageAlphaChannel()
                && $image->getImageChannelMean(Imagick::CHANNEL_ALPHA)['mean'] < $image->getQuantumRange()['quantumRangeLong'];
            $image->stripImage();

            if ($transparent) {
                $image->setImageFormat('png');
                $image->setOption('png:compression-level', '9');
                $outputMime = 'image/png';
                $extension = 'png';
            } else {
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $image->setImageFormat('jpeg');
                $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality(75);
                $outputMime = 'image/jpeg';
                $extension = 'jpg';
            }

            $contents = $image->getImageBlob();
            if ($contents === '' || (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) !== $outputMime) {
                throw new RuntimeException('Invalid optimized image.');
            }
            if (strlen($contents) >= strlen($original)) {
                return null;
            }

            return [
                'contents' => $contents,
                'mime_type' => $outputMime,
                'original_name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.'.$extension,
            ];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Não foi possível preparar a imagem. Verifique o arquivo e envie novamente.']);
        } finally {
            $image->clear();
            foreach ($limits as $resource => $limit) {
                $image->setResourceLimit($resource, $limit);
            }
        }
    }

    private function assertSafeDimensions(Imagick $image): void
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        if ($width < 1 || $height < 1 || max($width, $height) > 16_384 || $width * $height > 60_000_000) {
            throw ValidationException::withMessages(['file' => 'A imagem excede os limites seguros de processamento.']);
        }
    }

    private function isAnimatedPng(string $bytes): bool
    {
        $length = strlen($bytes);
        for ($offset = 8; $offset + 12 <= $length;) {
            $chunkLength = unpack('N', substr($bytes, $offset, 4))[1];
            if ($chunkLength > $length - $offset - 12) {
                break;
            }
            if (substr($bytes, $offset + 4, 4) === 'acTL') {
                return true;
            }
            $offset += $chunkLength + 12;
        }

        return false;
    }
}
