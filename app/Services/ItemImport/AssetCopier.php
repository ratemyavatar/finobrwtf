<?php

namespace App\Services\ItemImport;

use App\Http\Controllers\Asset as AssetHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AssetCopier
{
    const HAT_TYPES = [8, 41, 42, 43, 44, 45, 46, 47];

    public static function copy(string $link, int $author, int $price, bool $onSale, ?string $title = null, ?string $description = null): array
    {
        if (($bundleId = self::bundleIdFromLink($link)) !== null) {
            return self::copyBundle($bundleId, $author, $price, $onSale, $title, $description);
        }

        $id = self::idFromLink($link);
        $details = self::details($id);
        $name = trim((string) ($title ?: ($details['Name'] ?? "Item {$id}")));
        $desc = (string) ($description ?? ($details['Description'] ?? ''));
        $type = (int) ($details['AssetTypeId'] ?? 0);

        if (in_array($type, self::HAT_TYPES, true) || $type === 19) {
            $siteId = ItemImporter::import(ItemImporter::download($id), $name, $author, $desc, $price, $onSale,
                $type === 19 ? ItemImporter::GEAR : ItemImporter::HAT);
            return ['id' => $siteId, 'name' => $name];
        }

        if ($type === 17 || $type === 79) {
            $siteId = ItemImporter::import(ItemImporter::download($id), $name, $author, $desc, $price, $onSale, ItemImporter::HEAD);
            if ($type === 79) {
                self::markDynamicHead($siteId);
            }
            return ['id' => $siteId, 'name' => $name];
        }

        $kind = match ($type) {
            18 => ['face', 'Texture'],
            11 => ['shirt', 'ShirtTemplate'],
            12 => ['pants', 'PantsTemplate'],
            2 => ['tshirt', 'Graphic'],
            default => throw new ImportException("That item's type ({$type}) can't be copied yet: hats, accessories, gear, faces, shirts, pants, t-shirts, heads and bundles can."),
        };

        $xml = RbxmReader::toXml(ItemImporter::download($id));
        if (!preg_match('#<Content name="' . $kind[1] . '">\s*<url>([^<]+)</url>#', $xml, $m) || ($imageId = ItemImporter::robloxAssetId($m[1])) === null) {
            throw new ImportException("Couldn't find the image in that {$kind[0]}.");
        }

        $siteId = self::fromImage(ItemImporter::download($imageId), $kind[0], $name, $author, $desc, $price, $onSale);
        return ['id' => $siteId, 'name' => $name];
    }

    private static function copyBundle(int $bundleId, int $author, int $price, bool $onSale, ?string $title, ?string $description): array
    {
        $bundle = self::bundleDetails($bundleId);
        $name = trim((string) ($title ?: ($bundle['name'] ?? "Bundle {$bundleId}")));
        $desc = (string) ($description ?? ($bundle['description'] ?? ''));

        $items = [];
        foreach (($bundle['items'] ?? []) as $item) {
            $robloxId = (int) ($item['id'] ?? 0);
            if ($robloxId === 0 || ($item['type'] ?? 'Asset') !== 'Asset') {
                continue;
            }
            $assetType = (int) ($item['assetType'] ?? 0);
            if ($assetType === 0) {
                try {
                    $assetType = (int) (self::details($robloxId)['AssetTypeId'] ?? 0);
                } catch (\Throwable $e) {
                    report($e);
                    continue;
                }
            }
            $type = self::finobeType($assetType);
            if ($type === null) {
                continue;
            }
            $items[] = ['id' => $robloxId, 'assetType' => $assetType, 'type' => $type, 'name' => (string) ($item['name'] ?? $name)];
        }

        $files = [];
        $r15 = [];
        foreach ($items as $i => $item) {
            if (!in_array($item['type'], ['bodypart', 'head'], true)) {
                continue;
            }
            try {
                $files[$i] = ItemImporter::download($item['id']);
            } catch (\Throwable $e) {
                report($e);
                continue;
            }
            if ($item['type'] === 'bodypart' && !R15ToR6::hasR6($files[$i])) {
                $r15[ItemImporter::BODY_PARTS[$item['assetType']]] = R15ToR6::parts($files[$i]);
            }
        }
        $rig = $r15 ? R15ToR6::rig($r15) : null;
        if ($rig !== null && isset($r15[1])) {
            try {
                $rig[3] = R15ToR6::profile(R15ToR6::build(1, $r15[1], $rig, fn(int $id) => ItemImporter::download($id), false)[0]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $ids = [];
        foreach ($items as $i => $item) {
            try {
                if ($item['type'] === 'bodypart') {
                    if (!isset($files[$i])) {
                        continue;
                    }
                    $ids[] = ItemImporter::importBodyPart($files[$i], $item['name'], $author, $item['assetType'], $rig);
                } elseif ($item['type'] === 'head' && isset($files[$i])) {
                    $offset = $rig !== null ? R15ToR6::headOffset(R15ToR6::headAttachments($files[$i]), $rig, $r15) : null;
                    $ids[] = $headId = self::importHead($files[$i], $item['name'], $author, $offset);
                    if ($item['assetType'] === 79) {
                        self::markDynamicHead($headId);
                    }
                } else {
                    $ids[] = self::importItem($item['id'], $item['type'], $item['name'], $author);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (!$ids) {
            throw new ImportException("Nothing in that Roblox bundle works on the 2016 client: it only has animations or body parts with no R6 version.");
        }

        $packageId = AssetHelper::createPackage($name, $ids, $author, $desc, $price, $onSale);
        return ['id' => $packageId, 'name' => $name];
    }

    private static function finobeType(int $robloxType): ?string
    {
        return match (true) {
            $robloxType === 19 => 'gear',
            in_array($robloxType, self::HAT_TYPES, true) => 'hat',
            $robloxType === 17, $robloxType === 79 => 'head',
            $robloxType === 18 => 'face',
            $robloxType >= 27 && $robloxType <= 31 => 'bodypart',
            $robloxType === 11 => 'shirt',
            $robloxType === 12 => 'pants',
            $robloxType === 2 => 'tshirt',
            default => null,
        };
    }

    private static function markDynamicHead(int $id): void
    {
        $asset = \App\Models\Asset::find($id);
        if ($asset) {
            $asset->additional = array_merge(is_array($asset->additional) ? $asset->additional : [], ['dynamicHead' => true]);
            $asset->save();
        }
    }

    private static function importHead(string $file, string $name, int $author, ?array $offset): int
    {
        try {
            return ItemImporter::import($file, $name, $author, '', 0, false, ItemImporter::HEAD, $offset);
        } catch (ImportException $e) {
            return ItemImporter::import($file, $name, $author, '', 0, false, null, $offset);
        }
    }

    private static function importItem(int $robloxId, string $type, string $name, int $author): int
    {
        if (in_array($type, ['hat', 'gear', 'head'], true)) {
            $file = ItemImporter::download($robloxId);
            $expect = ['hat' => ItemImporter::HAT, 'gear' => ItemImporter::GEAR, 'head' => ItemImporter::HEAD][$type];
            try {
                return ItemImporter::import($file, $name, $author, '', 0, false, $expect);
            } catch (ImportException $e) {
                if ($type !== 'head') {
                    throw $e;
                }

                return ItemImporter::import($file, $name, $author, '', 0, false);
            }
        }

        $kind = ['face' => ['face', 'Texture'], 'shirt' => ['shirt', 'ShirtTemplate'],
            'pants' => ['pants', 'PantsTemplate'], 'tshirt' => ['tshirt', 'Graphic']][$type];
        $xml = RbxmReader::toXml(ItemImporter::download($robloxId));
        if (!preg_match('#<Content name="' . $kind[1] . '">\s*<url>([^<]+)</url>#', $xml, $m)
            || ($imageId = ItemImporter::robloxAssetId($m[1])) === null) {
            throw new ImportException("Couldn't find the image in that {$kind[0]}.");
        }
        return self::fromImage(ItemImporter::download($imageId), $kind[0], $name, $author, '', 0, false);
    }

    public static function fromImage(string $image, string $kind, string $name, int $author, string $description, int $price, bool $onSale): int
    {
        if (!str_starts_with($image, "\x89PNG") && !str_starts_with($image, "\xFF\xD8")) {
            throw new ImportException('The image has to be a PNG or JPEG.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'finobe_item_');
        file_put_contents($tmp, $image);
        try {
            $siteId = AssetHelper::createAccessory($name, ['tmp_name' => $tmp], $author, $description, $price, $onSale, false, $kind);
        } finally {
            @unlink($tmp);
        }

        $db = DB::connection('finobe');
        $additional = json_decode((string) $db->table('assets')->where('id', $siteId)->value('additional'), true) ?: [];
        $db->table('assets')->whereIn('id', array_filter([$siteId, (int) ($additional['media']['textureAssetId'] ?? 0)]))->update(['visibility' => 'n']);

        return $siteId;
    }

    public static function idFromLink(string $link): int
    {
        $link = trim($link);
        if (preg_match('#^\d+$#', $link)
            || preg_match('#roblox\.com/(?:[a-z-]+/)?(?:catalog|library)/(\d+)#i', $link, $m)
            || preg_match('#create\.roblox\.com/(?:[a-z-]+/)?(?:store|marketplace)/asset/(\d+)#i', $link, $m)
            || preg_match('#[?&]id=(\d+)#i', $link, $m)) {
            return (int) ($m[1] ?? $link);
        }
        throw new ImportException("That doesn't look like a Roblox item link.");
    }

    private static function details(int $id): array
    {
        foreach (['economy.roproxy.com', 'economy.roblox.com'] as $host) {
            try {
                $r = Http::timeout(15)->retry(2, 500, throw: false)->get("https://{$host}/v2/assets/{$id}/details");
            } catch (\Throwable $e) {
                continue;
            }
            if ($r->ok() && is_array($r->json())) {
                return $r->json();
            }
            if ($r->status() === 400 || $r->status() === 404) {
                throw new ImportException("Roblox has no item {$id}.");
            }
        }
        throw new ImportException("Couldn't reach Roblox to look up item {$id}, try again.");
    }

    public static function bundleIdFromLink(string $link): ?int
    {
        if (preg_match('#roblox\.com/(?:[a-z-]+/)?bundles/(\d+)#i', trim($link), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private static function bundleDetails(int $id): array
    {
        try {
            $r = Http::withHeaders(['User-Agent' => 'Roblox/WinInet'])
                ->timeout(15)->retry(2, 500, throw: false)
                ->get("https://catalog.roblox.com/v1/bundles/{$id}/details");
        } catch (\Throwable $e) {
            throw new ImportException("Couldn't reach Roblox to look up bundle {$id}, try again.");
        }
        if (!$r->ok() || !is_array($r->json())) {
            throw new ImportException("Roblox has no bundle {$id}.");
        }
        return $r->json();
    }
}
