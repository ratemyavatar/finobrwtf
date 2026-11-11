<?php
namespace App\Support;

final class AvatarImage
{
    public const DEFAULT_URL = '/s/img/default-avatar.png';
    public const LEGACY_DEFAULT = 'avatar/682b8bd6cbc48.png';

    public static function url(?string $path): string
    {
        $path = trim($path ?? '');
        if ($path === self::LEGACY_DEFAULT || !preg_match('~^avatar/[A-Za-z0-9_-]+\.(png|jpg|jpeg|gif|webp)$~D', $path)) {
            return self::DEFAULT_URL;
        }
        return rtrim(config('admin.cdn_url'), '/').'/'.$path;
    }
}
