<?php

namespace App\Services\ItemImport;

use DOMDocument;
use DOMElement;
use DOMXPath;

class R15ToR6
{
    const LIMBS = [
        1 => ['UpperTorso', 'LowerTorso'],
        2 => ['LeftUpperArm', 'LeftLowerArm', 'LeftHand'],
        3 => ['RightUpperArm', 'RightLowerArm', 'RightHand'],
        4 => ['LeftUpperLeg', 'LeftLowerLeg', 'LeftFoot'],
        5 => ['RightUpperLeg', 'RightLowerLeg', 'RightFoot'],
    ];

    const R6_CENTERS = [
        1 => [0, 3, 0],
        2 => [-1.5, 3, 0],
        3 => [1.5, 3, 0],
        4 => [-0.5, 1, 0],
        5 => [0.5, 1, 0],
    ];

    const R6_JOINTS = [
        'LeftShoulderRigAttachment' => [2, [-1, 3.5, 0]],
        'RightShoulderRigAttachment' => [3, [1, 3.5, 0]],
        'LeftHipRigAttachment' => [4, [-0.5, 2, 0]],
        'RightHipRigAttachment' => [5, [0.5, 2, 0]],
        'RootRigAttachment' => [1, [0, 3, 0]],
    ];

    const SLOT = 512;

    public static function parts(string $file): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML(RbxmReader::toXml($file), LIBXML_NONET | LIBXML_PARSEHUGE)) {
            return [];
        }
        $xp = new DOMXPath($doc);
        $folder = null;
        foreach (['R15ArtistIntent', 'R15Fixed', 'R15'] as $name) {
            $folder = $xp->query('//Item[@class="Folder" and Properties/string[@name="Name"]="' . $name . '"]')->item(0);
            if ($folder && $xp->query('./Item[@class="MeshPart"]', $folder)->length) {
                break;
            }
            $folder = null;
        }
        $items = $folder ? $xp->query('./Item[@class="MeshPart"]', $folder) : $xp->query('//Item[@class="MeshPart"]');

        $parts = [];
        foreach ($items as $item) {
            $name = self::prop($xp, $item, 'Name');
            if ($name === null || isset($parts[$name])) {
                continue;
            }
            $cf = self::cframe($xp, $xp->query('./Properties/CoordinateFrame[@name="CFrame"]', $item)->item(0));
            $size = self::vec($xp, $xp->query('./Properties/Vector3[@name="size" or @name="Size"]', $item)->item(0));
            $meshId = ItemImporter::robloxAssetId(self::content($xp, $item, 'MeshID') ?: self::content($xp, $item, 'MeshId'));
            if ($cf === null || $size === null || $meshId === null) {
                continue;
            }
            $texture = ItemImporter::robloxAssetId(self::content($xp, $item, 'TextureID'));
            $sa = $xp->query('./Item[@class="SurfaceAppearance"]', $item)->item(0);
            if ($texture === null && $sa instanceof DOMElement) {
                $texture = ItemImporter::robloxAssetId(self::content($xp, $sa, 'ColorMap'));
            }

            $attachments = [];
            foreach ($xp->query('./Item[@class="Attachment"]', $item) as $att) {
                $acf = self::cframe($xp, $xp->query('./Properties/CoordinateFrame[@name="CFrame"]', $att)->item(0));
                if ($acf !== null) {
                    $attachments[self::prop($xp, $att, 'Name')] = self::apply($cf, array_slice($acf, 0, 3));
                }
            }

            $parts[$name] = ['cf' => $cf, 'size' => $size, 'mesh' => $meshId, 'texture' => $texture, 'attachments' => $attachments];
        }
        return $parts;
    }

    public static function hasR6(string $file): bool
    {
        return str_contains(RbxmReader::toXml($file), 'class="CharacterMesh"');
    }

    public static function rig(array $partsByBodyPart): ?array
    {
        $all = array_merge(...array_values($partsByBodyPart ?: [[]]));
        $root = $all['LowerTorso']['attachments']['RootRigAttachment'] ?? null;

        $ground = INF;
        foreach ([4, 5] as $bp) {
            foreach ($partsByBodyPart[$bp] ?? [] as $p) {
                $ground = min($ground, self::bottom($p));
            }
        }

        if ($root !== null) {
            return [$root[0], is_finite($ground) ? $ground : $root[1] - 3, $root[2], null];
        }

        $l = $all['LeftUpperArm']['attachments']['LeftShoulderRigAttachment'] ?? $all['LeftUpperLeg']['attachments']['LeftHipRigAttachment'] ?? null;
        $r = $all['RightUpperArm']['attachments']['RightShoulderRigAttachment'] ?? $all['RightUpperLeg']['attachments']['RightHipRigAttachment'] ?? null;
        if ($l !== null && $r !== null && is_finite($ground)) {
            return [($l[0] + $r[0]) / 2, $ground, ($l[2] + $r[2]) / 2, null];
        }
        return null;
    }

    public static function build(int $bodyPart, array $parts, ?array $rig, callable $download, bool $withTexture = true): array
    {
        $names = array_values(array_filter(self::LIMBS[$bodyPart] ?? [], fn($n) => isset($parts[$n])));
        if (!$names) {
            throw new ImportException('That body part has no R6 or R15 mesh the 2016 client can use.');
        }

        $offset = $rig !== null ? [-$rig[0], -$rig[1], -$rig[2]] : self::jointOffset($bodyPart, $parts);
        $center = self::R6_CENTERS[$bodyPart];

        $slots = [];
        foreach ($names as $name) {
            $slots[(int) ($parts[$name]['texture'] ?? 0)] ??= count($slots);
        }

        [$pivot, $cos, $sin] = self::hang($bodyPart, $parts);

        $out = [];
        $faces = '';
        $count = 0;
        foreach ($names as $name) {
            $p = $parts[$name];
            [$v, $f] = self::readV2(MeshConverter::toLegacy($download($p['mesh'])));
            [$min, $max] = self::bounds($v);
            $scale = [];
            for ($k = 0; $k < 3; $k++) {
                $scale[$k] = ($max[$k] - $min[$k]) > 1e-6 ? $p['size'][$k] / ($max[$k] - $min[$k]) : 1;
            }
            $mid = [($min[0] + $max[0]) / 2, ($min[1] + $max[1]) / 2, ($min[2] + $max[2]) / 2];
            $slot = $slots[(int) ($p['texture'] ?? 0)];
            $n = count($slots);

            foreach ($v as $vert) {
                $local = [($vert[0] - $mid[0]) * $scale[0], ($vert[1] - $mid[1]) * $scale[1], ($vert[2] - $mid[2]) * $scale[2]];
                $w = self::apply($p['cf'], $local);
                $w = [$pivot[0] + ($w[0] - $pivot[0]) * $cos - ($w[1] - $pivot[1]) * $sin,
                    $pivot[1] + ($w[0] - $pivot[0]) * $sin + ($w[1] - $pivot[1]) * $cos, $w[2]];
                $pos = [$w[0] + $offset[0] - $center[0], $w[1] + $offset[1] - $center[1], $w[2] + $offset[2] - $center[2]];
                $nrm = self::rotate($p['cf'], [$vert[3] / $scale[0], $vert[4] / $scale[1], $vert[5] / $scale[2]]);
                $nrm = [$nrm[0] * $cos - $nrm[1] * $sin, $nrm[0] * $sin + $nrm[1] * $cos, $nrm[2]];
                $len = sqrt($nrm[0] ** 2 + $nrm[1] ** 2 + $nrm[2] ** 2) ?: 1;
                $u = self::wrap($vert[6]);
                $tv = self::wrap($vert[7]);
                $out[] = [$pos[0], $pos[1], $pos[2], $nrm[0] / $len, $nrm[1] / $len, $nrm[2] / $len, $u, ($slot + $tv) / $n, 0];
            }
            foreach ($f as $tri) {
                $faces .= pack('V3', $tri[0] + $count, $tri[1] + $count, $tri[2] + $count);
            }
            $count += count($v);
        }

        $push = 0;
        if (($bodyPart === 2 || $bodyPart === 3) && is_array($rig[3] ?? null) && $out) {
            $inner = [];
            foreach ($out as $v) {
                $band = (int) floor(($v[1] + $center[1]) / 0.2);
                $inner[$band] = min($inner[$band] ?? INF, abs($v[0] + $center[0]));
            }
            $need = -INF;
            foreach ($inner as $band => $x) {
                if (isset($rig[3][$band])) {
                    $need = max($need, $rig[3][$band] + 0.03 - $x);
                }
            }
            if (is_finite($need)) {
                $push = $need * ($bodyPart === 2 ? -1 : 1);
            }
        }
        $verts = '';
        foreach ($out as $v) {
            $v[0] += $push;
            $verts .= pack('g9', ...$v);
        }

        $mesh = "version 2.00\n" . pack('vCCVV', 12, 36, 12, $count, intdiv(strlen($faces), 12)) . $verts . $faces;
        $texture = $withTexture ? self::atlas($slots, $download) : null;

        return [$mesh, $texture];
    }

    public static function profile(string $mesh): array
    {
        [$verts] = self::readV2($mesh);
        $bands = [];
        foreach ($verts as $v) {
            $band = (int) floor(($v[1] + 3) / 0.2);
            $bands[$band] = max($bands[$band] ?? 0, abs($v[0]));
        }
        return $bands;
    }

    public static function headAttachments(string $file): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML(RbxmReader::toXml($file), LIBXML_NONET | LIBXML_PARSEHUGE)) {
            return [];
        }
        $xp = new DOMXPath($doc);
        $out = [];
        foreach ($xp->query('//Item[@class="Vector3Value" or @class="Attachment"]') as $item) {
            $name = self::prop($xp, $item, 'Name');
            $v = $item->getAttribute('class') === 'Attachment'
                ? self::cframe($xp, $xp->query('./Properties/CoordinateFrame[@name="CFrame"]', $item)->item(0))
                : self::vec($xp, $xp->query('./Properties/Vector3[@name="Value"]', $item)->item(0));
            if ($name !== null && $v !== null) {
                $out[$name] = array_slice($v, 0, 3);
            }
        }
        return $out;
    }

    public static function headOffset(array $head, ?array $rig, array $partsByBodyPart): ?array
    {
        $torso = $partsByBodyPart[1] ?? [];
        $neck = $torso['UpperTorso']['attachments']['NeckRigAttachment'] ?? null;
        if ($rig === null || $neck === null) {
            return null;
        }
        $h = $head['NeckRigAttachment'] ?? [0, -0.5, 0];
        return [$neck[0] - $rig[0] - $h[0], $neck[1] - $rig[1] - $h[1] - 4.5, $neck[2] - $rig[2] - $h[2]];
    }

    private static function hang(int $bodyPart, array $parts): array
    {
        $side = [2 => 'Left', 3 => 'Right'][$bodyPart] ?? null;
        $pivot = $side ? ($parts["{$side}UpperArm"]['attachments']["{$side}ShoulderRigAttachment"] ?? null) : null;
        $end = $side ? ($parts["{$side}Hand"]['cf'] ?? $parts["{$side}LowerArm"]['cf'] ?? null) : null;
        if ($pivot === null || $end === null) {
            return [[0, 0, 0], 1, 0];
        }
        $angle = -atan2($end[0] - $pivot[0], $pivot[1] - $end[1]);
        return [$pivot, cos($angle), sin($angle)];
    }

    private static function jointOffset(int $bodyPart, array $parts): array
    {
        foreach ($parts as $p) {
            foreach ($p['attachments'] as $name => $pos) {
                if (isset(self::R6_JOINTS[$name]) && self::R6_JOINTS[$name][0] === $bodyPart) {
                    $t = self::R6_JOINTS[$name][1];
                    return [$t[0] - $pos[0], $t[1] - $pos[1], $t[2] - $pos[2]];
                }
            }
        }
        $first = reset($parts);
        $c = self::R6_CENTERS[$bodyPart];
        return [$c[0] - $first['cf'][0], $c[1] - $first['cf'][1], $c[2] - $first['cf'][2]];
    }

    private static function atlas(array $slots, callable $download): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $n = count($slots);
        if ($n === 1 && array_key_first($slots) > 0) {
            return self::image($download(array_key_first($slots)), $download);
        }
        $img = imagecreatetruecolor(self::SLOT, self::SLOT * $n);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        foreach ($slots as $texture => $slot) {
            if ($texture > 0) {
                $src = @imagecreatefromstring(self::image($download($texture), $download));
                if ($src !== false) {
                    imagecopyresampled($img, $src, 0, $slot * self::SLOT, 0, 0, self::SLOT, self::SLOT, imagesx($src), imagesy($src));
                }
            }
        }
        ob_start();
        imagepng($img);
        return ob_get_clean();
    }

    private static function image(string $data, callable $download): string
    {
        if (str_starts_with(ltrim($data), '<roblox') && preg_match('#<Content name="Texture">\s*<url>([^<]+)</url>#', RbxmReader::toXml($data), $m)
            && ($inner = ItemImporter::robloxAssetId($m[1])) !== null) {
            return $download($inner);
        }
        return $data;
    }

    private static function wrap(float $x): float
    {
        if ($x >= 0 && $x <= 1) {
            return $x;
        }
        return $x - floor($x);
    }

    private static function readV2(string $mesh): array
    {
        $o = strpos($mesh, "\n") + 1;
        $h = unpack('vheader/Cvert/Cface/Vn/Vnf', $mesh, $o);
        $o += $h['header'];
        $verts = [];
        for ($i = 0; $i < $h['n']; $i++) {
            $verts[] = array_values(unpack('g9', $mesh, $o + $i * $h['vert']));
        }
        $o += $h['n'] * $h['vert'];
        $faces = [];
        for ($i = 0; $i < $h['nf']; $i++) {
            $faces[] = array_values(unpack('V3', $mesh, $o + $i * $h['face']));
        }
        return [$verts, $faces];
    }

    private static function bounds(array $verts): array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        foreach ($verts as $v) {
            for ($k = 0; $k < 3; $k++) {
                $min[$k] = min($min[$k], $v[$k]);
                $max[$k] = max($max[$k], $v[$k]);
            }
        }
        return [$min, $max];
    }

    private static function bottom(array $p): float
    {
        $cf = $p['cf'];
        $extent = abs($cf[6]) * $p['size'][0] / 2 + abs($cf[7]) * $p['size'][1] / 2 + abs($cf[8]) * $p['size'][2] / 2;
        return $cf[1] - $extent;
    }

    private static function apply(array $cf, array $v): array
    {
        $r = self::rotate($cf, $v);
        return [$cf[0] + $r[0], $cf[1] + $r[1], $cf[2] + $r[2]];
    }

    private static function rotate(array $cf, array $v): array
    {
        return [
            $cf[3] * $v[0] + $cf[4] * $v[1] + $cf[5] * $v[2],
            $cf[6] * $v[0] + $cf[7] * $v[1] + $cf[8] * $v[2],
            $cf[9] * $v[0] + $cf[10] * $v[1] + $cf[11] * $v[2],
        ];
    }

    private static function cframe(DOMXPath $xp, $node): ?array
    {
        if (!$node) {
            return null;
        }
        $out = [];
        foreach (['X', 'Y', 'Z', 'R00', 'R01', 'R02', 'R10', 'R11', 'R12', 'R20', 'R21', 'R22'] as $k) {
            $n = $xp->query("./{$k}", $node)->item(0);
            if (!$n) {
                return null;
            }
            $out[] = (float) $n->textContent;
        }
        return $out;
    }

    private static function vec(DOMXPath $xp, $node): ?array
    {
        if (!$node) {
            return null;
        }
        return array_map(fn($k) => (float) ($xp->query("./{$k}", $node)->item(0)?->textContent ?? 1), ['X', 'Y', 'Z']);
    }

    private static function prop(DOMXPath $xp, DOMElement $item, string $name): ?string
    {
        return $xp->query("./Properties/*[@name='{$name}']", $item)->item(0)?->textContent;
    }

    private static function content(DOMXPath $xp, DOMElement $item, string $name): string
    {
        return trim((string) $xp->query("./Properties/Content[@name='{$name}']/url", $item)->item(0)?->textContent);
    }
}
