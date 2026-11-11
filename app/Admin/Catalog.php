<?php
namespace App\Admin;

final class Catalog
{
    public static function all(): array
    {
        static $items;
        return $items ??= json_decode(file_get_contents(config_path('admin_controls.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function find(string $id): array
    {
        foreach (self::all() as $item) {
            if ($item['id'] === $id) return $item;
        }
        abort(404, 'Unknown admin control.');
    }

    public static function readOnly(array $a): bool
    {
        return in_array($a['kind'], ['inspect','export','report','history','notes','target_audit','avatar_inspect','avatar_export','staff_list','staff_inspect','audit','audit_mine','audit_export','counts','health','queue_pending','queue_failed','flair_inspect']);
    }
}
