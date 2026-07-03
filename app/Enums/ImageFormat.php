<?php

namespace App\Enums;

enum ImageFormat: string
{
    case Jpg = 'jpg';
    case Png = 'png';
    case Webp = 'webp';
    case Gif = 'gif';
    case Avif = 'avif';

    public static function fromExtension(string $extension): ?self
    {
        $extension = strtolower($extension);

        return self::tryFrom($extension === 'jpeg' ? 'jpg' : $extension);
    }
}
