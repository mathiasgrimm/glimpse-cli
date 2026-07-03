<?php

namespace App\Glimpse;

final readonly class ImageResult
{
    public function __construct(
        public string $bytes,
        public string $format,
        public string $mimeType,
        public int $size,
        public int $width,
        public int $height,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            bytes: (string) base64_decode((string) data_get($data, 'output.data'), true),
            format: (string) data_get($data, 'format'),
            mimeType: (string) data_get($data, 'mime_type'),
            size: (int) data_get($data, 'size'),
            width: (int) data_get($data, 'width'),
            height: (int) data_get($data, 'height'),
        );
    }
}
