<?php

namespace App\Services\ItemImport;

class RbxmReader
{
    const CONTENT = ['MeshId', 'TextureId', 'TextureID', 'Texture', 'Image', 'SoundId', 'AnimationId', 'Graphic',
        'ShirtTemplate', 'PantsTemplate', 'LinkedSource', 'ColorMap', 'NormalMap', 'MetalnessMap', 'RoughnessMap',
        'SkyboxBk', 'SkyboxDn', 'SkyboxFt', 'SkyboxLf', 'SkyboxRt', 'SkyboxUp', 'Face', 'CageMeshId', 'ReferenceMeshId'];

    const SKIP = ['AttributesSerialize', 'Tags', 'Capabilities', 'DefinesCapabilities', 'HistoryId', 'UniqueId',
        'SourceAssetId', 'ScriptGuid', 'CollisionGroup', 'CollisionGroupId', 'MaterialVariantSerialized',
        'PivotOffset', 'WorldPivotData', 'NeedsPivotMigration', 'ModelStreamingMode', 'ModelMeshCFrame',
        'ModelMeshData', 'ModelMeshSize', 'LevelOfDetail', 'BackSurfaceInput', 'BottomSurfaceInput',
        'FrontSurfaceInput', 'LeftSurfaceInput', 'RightSurfaceInput', 'TopSurfaceInput', 'CastShadow',
        'CanQuery', 'CanTouch', 'AudioCanCollide', 'EnableFluidForces', 'RootPriority', 'Massless',
        'PhysicsData', 'PhysicalConfigData', 'MeshContent', 'TextureContent', 'Color3uint8'];

    const PALETTE = [1=>[242,243,243], 2=>[161,165,162], 3=>[249,233,153], 5=>[215,197,154], 6=>[194,218,184], 9=>[232,186,200], 11=>[128,187,219], 12=>[203,132,66], 18=>[204,142,105], 21=>[196,40,28], 22=>[196,112,160], 23=>[13,105,172], 24=>[245,205,48], 25=>[98,71,50], 26=>[27,42,53], 27=>[109,110,108], 28=>[40,127,71], 29=>[161,196,140], 36=>[243,207,155], 37=>[75,151,75], 38=>[160,95,53], 39=>[193,202,222], 40=>[236,236,236], 41=>[205,84,75], 42=>[193,223,240], 43=>[123,182,232], 44=>[247,241,141], 45=>[180,210,228], 47=>[217,133,108], 48=>[132,182,141], 49=>[248,241,132], 50=>[236,232,222], 100=>[238,196,182], 101=>[218,134,122], 102=>[110,153,202], 103=>[199,193,183], 104=>[107,50,124], 105=>[226,155,64], 106=>[218,133,65], 107=>[0,143,156], 108=>[104,92,67], 110=>[67,84,147], 111=>[191,183,177], 112=>[104,116,172], 113=>[229,173,200], 115=>[199,210,60], 116=>[85,165,175], 118=>[183,215,213], 119=>[164,189,71], 120=>[217,228,167], 121=>[231,172,88], 123=>[211,111,76], 124=>[146,57,120], 125=>[234,184,146], 126=>[165,165,203], 127=>[220,188,129], 128=>[174,122,89], 131=>[156,163,168], 133=>[213,115,61], 134=>[216,221,86], 135=>[116,134,157], 136=>[135,124,144], 137=>[224,152,100], 138=>[149,138,115], 140=>[32,58,86], 141=>[39,70,45], 143=>[207,226,247], 145=>[121,136,161], 146=>[149,142,163], 147=>[147,135,103], 148=>[87,88,87], 149=>[22,29,50], 150=>[171,173,172], 151=>[120,144,130], 153=>[149,121,119], 154=>[123,46,47], 157=>[255,246,123], 158=>[225,164,194], 168=>[117,108,98], 176=>[151,105,91], 178=>[180,132,85], 179=>[137,135,136], 180=>[215,169,75], 190=>[249,214,46], 191=>[232,171,45], 192=>[105,64,40], 193=>[207,96,36], 194=>[163,162,165], 195=>[70,103,164], 196=>[35,71,139], 198=>[142,66,133], 199=>[99,95,98], 200=>[130,138,93], 208=>[229,228,223], 209=>[176,142,68], 210=>[112,149,120], 211=>[121,181,181], 212=>[159,195,233], 213=>[108,129,183], 216=>[144,76,42], 217=>[124,92,70], 218=>[150,112,159], 219=>[107,98,155], 220=>[167,169,206], 221=>[205,98,152], 222=>[228,173,200], 223=>[220,144,149], 224=>[240,213,160], 225=>[235,184,127], 226=>[253,234,141], 232=>[125,187,221], 268=>[52,43,117], 301=>[80,109,84], 302=>[91,93,105], 303=>[0,16,176], 304=>[44,101,29], 305=>[82,124,174], 306=>[51,88,130], 307=>[16,42,220], 308=>[61,21,133], 309=>[52,142,64], 310=>[91,154,76], 311=>[159,161,172], 312=>[89,34,89], 313=>[31,128,29], 314=>[159,173,192], 315=>[9,137,207], 316=>[123,0,123], 317=>[124,156,107], 318=>[138,171,133], 319=>[185,196,177], 320=>[202,203,209], 322=>[123,47,123], 323=>[148,190,129], 324=>[168,189,153], 325=>[223,223,222], 327=>[151,0,0], 328=>[177,229,166], 329=>[152,194,219], 330=>[255,152,220], 331=>[255,89,89], 332=>[117,0,0], 334=>[248,217,109], 335=>[231,231,236], 336=>[199,212,228], 337=>[255,148,148], 338=>[190,104,98], 339=>[86,36,36], 340=>[241,231,199], 341=>[254,243,187], 342=>[224,178,208], 343=>[212,144,189], 344=>[150,85,85], 346=>[211,190,150], 347=>[226,220,188], 348=>[237,234,234], 349=>[233,218,218], 350=>[136,62,62], 351=>[188,155,93], 352=>[199,172,120], 353=>[202,191,163], 354=>[187,179,178], 355=>[108,88,75], 356=>[160,132,79], 357=>[149,137,136], 358=>[171,168,158], 359=>[175,148,131], 360=>[150,103,102], 361=>[86,66,54], 362=>[126,104,63], 363=>[105,102,92], 364=>[90,76,66], 365=>[106,57,9], 1001=>[248,248,248], 1002=>[205,205,205], 1003=>[17,17,17], 1004=>[255,0,0], 1005=>[255,176,0], 1006=>[180,128,255], 1007=>[163,75,75], 1008=>[193,190,66], 1009=>[255,255,0], 1010=>[0,0,255], 1011=>[0,32,96], 1012=>[33,84,185], 1013=>[4,175,236], 1014=>[170,85,0], 1015=>[170,0,170], 1016=>[255,102,204], 1017=>[255,175,0], 1018=>[18,238,212], 1019=>[0,255,255], 1020=>[0,255,0], 1021=>[58,125,21], 1022=>[127,142,100], 1023=>[140,91,159], 1024=>[175,221,255], 1025=>[255,201,201], 1026=>[177,167,255], 1027=>[159,243,233], 1028=>[204,255,204], 1029=>[255,255,204], 1030=>[255,204,153], 1031=>[98,37,209], 1032=>[255,0,191]];

    public static function toXml(string $data): string
    {
        if (str_starts_with($data, '<roblox!')) {
            return (new self())->binaryToXml($data);
        }
        if (preg_match('/^\s*<roblox[\s>]/', $data)) {
            return $data;
        }
        throw new ImportException("That file isn't a Roblox model (.rbxm or .rbxmx).");
    }

    private array $classes = [];
    private array $props = [];
    private array $classOf = [];
    private array $parent = [];
    private array $sharedStrings = [];

    private function binaryToXml(string $d): string
    {
        if (strlen($d) < 32) {
            throw new ImportException('The model file is cut off.');
        }
        $o = 32;
        while ($o + 16 <= strlen($d)) {
            $name = substr($d, $o, 4);
            [$cl, $ul] = array_values(unpack('V2', substr($d, $o + 4, 8)));
            $o += 16;
            $raw = substr($d, $o, $cl ?: $ul);
            $o += $cl ?: $ul;
            $body = $cl ? self::decompress($raw, $ul) : $raw;

            match ($name) {
                'INST' => $this->readInst($body),
                'PROP' => $this->readProp($body),
                'PRNT' => $this->readPrnt($body),
                'SSTR' => $this->readSstr($body),
                default => null,
            };
            if ($name === "END\0") {
                break;
            }
        }

        return $this->writeXml();
    }

    private static function decompress(string $raw, int $size): string
    {
        if (str_starts_with($raw, "\x28\xB5\x2F\xFD")) {
            if (function_exists('zstd_uncompress')) {
                return zstd_uncompress($raw);
            }
            throw new ImportException('This model is zstd-compressed, which needs the PHP zstd extension. Save it again from Studio as .rbxmx (XML) instead.');
        }
        return Lz4::decompressBlock($raw, $size);
    }

    private function readInst(string $b): void
    {
        $o = 0;
        $id = self::i32($b, $o);
        $name = self::str($b, $o);
        $o += 1;
        $n = self::u32($b, $o);
        $refs = self::refs($b, $o, $n);
        $this->classes[$id] = [$name, $refs];
        foreach ($refs as $r) {
            $this->classOf[$r] = $name;
        }
    }

    private function readPrnt(string $b): void
    {
        $o = 1;
        $n = self::u32($b, $o);
        $children = self::refs($b, $o, $n);
        $parents = self::refs($b, $o, $n);
        foreach ($children as $i => $c) {
            $this->parent[$c] = $parents[$i];
        }
    }

    private function readSstr(string $b): void
    {
        $o = 4;
        $n = self::u32($b, $o);
        for ($i = 0; $i < $n; $i++) {
            $o += 16;
            $this->sharedStrings[] = self::str($b, $o);
        }
    }

    private function readProp(string $b): void
    {
        $o = 0;
        $id = self::i32($b, $o);
        $name = self::str($b, $o);
        $type = ord($b[$o]);
        $o++;
        if (!isset($this->classes[$id])) {
            return;
        }
        [$class, $refs] = $this->classes[$id];
        $n = count($refs);

        if ($name === 'Color3uint8' && $type === 26) {
            $vals = [];
            for ($i = 0; $i < $n; $i++) {
                $vals[] = ['int', (string) self::nearestBrickColor(ord($b[$o + $i]), ord($b[$o + $n + $i]), ord($b[$o + 2 * $n + $i]))];
            }
            $this->set($refs, 'BrickColor', $vals, false);
            return;
        }
        if (in_array($name, self::SKIP, true)) {
            return;
        }

        $vals = $this->values($name, $type, $b, $o, $n);
        if ($vals !== null) {
            $this->set($refs, $name, $vals, true);
        }
    }

    private function set(array $refs, string $name, array $vals, bool $overwrite): void
    {
        foreach ($refs as $i => $r) {
            if ($overwrite || !isset($this->props[$r][$name])) {
                $this->props[$r][$name] = $vals[$i];
            }
        }
    }

    private function values(string $name, int $type, string $b, int $o, int $n): ?array
    {
        $out = [];
        switch ($type) {
            case 1:
                for ($i = 0; $i < $n; $i++) {
                    $s = self::str($b, $o);
                    if ($name === 'Source') {
                        $out[] = ['ProtectedString', self::cdata($s)];
                    } elseif (in_array($name, self::CONTENT, true)) {
                        $out[] = ['Content', $s === '' ? '<null></null>' : '<url>' . self::esc($s) . '</url>'];
                    } else {
                        $out[] = ['string', self::esc($s)];
                    }
                }
                return $out;
            case 2:
                for ($i = 0; $i < $n; $i++) {
                    $out[] = ['bool', ord($b[$o + $i]) ? 'true' : 'false'];
                }
                return $out;
            case 3:
                foreach (self::ints($b, $o, $n) as $v) {
                    $out[] = ['int', (string) $v];
                }
                return $out;
            case 4:
                foreach (self::floats($b, $o, $n) as $v) {
                    $out[] = ['float', self::num($v)];
                }
                return $out;
            case 5:
                for ($i = 0; $i < $n; $i++) {
                    $out[] = ['double', self::num(unpack('e', substr($b, $o + 8 * $i, 8))[1])];
                }
                return $out;
            case 7:
                $sx = self::floats($b, $o, $n); $sy = self::floats($b, $o, $n);
                $ox = self::ints($b, $o, $n); $oy = self::ints($b, $o, $n);
                for ($i = 0; $i < $n; $i++) {
                    $out[] = ['UDim2', '<XS>' . self::num($sx[$i]) . '</XS><XO>' . $ox[$i] . '</XO><YS>' . self::num($sy[$i]) . '</YS><YO>' . $oy[$i] . '</YO>'];
                }
                return $out;
            case 9:
            case 10:
                $tag = $type === 9 ? 'faces' : 'axes';
                for ($i = 0; $i < $n; $i++) {
                    $out[] = [$type === 9 ? 'Faces' : 'Axes', "<{$tag}>" . ord($b[$o + $i]) . "</{$tag}>"];
                }
                return $out;
            case 11:
                foreach (self::uints($b, $o, $n) as $v) {
                    $out[] = ['int', (string) $v];
                }
                return $out;
            case 12:
                $r = self::floats($b, $o, $n); $g = self::floats($b, $o, $n); $bl = self::floats($b, $o, $n);
                for ($i = 0; $i < $n; $i++) {
                    $c = fn($x) => max(0, min(255, (int) round($x * 255)));
                    $out[] = ['Color3', (string) ((0xFF << 24) | ($c($r[$i]) << 16) | ($c($g[$i]) << 8) | $c($bl[$i]))];
                }
                return $out;
            case 13:
                $x = self::floats($b, $o, $n); $y = self::floats($b, $o, $n);
                for ($i = 0; $i < $n; $i++) {
                    $out[] = ['Vector2', '<X>' . self::num($x[$i]) . '</X><Y>' . self::num($y[$i]) . '</Y>'];
                }
                return $out;
            case 14:
                $x = self::floats($b, $o, $n); $y = self::floats($b, $o, $n); $z = self::floats($b, $o, $n);
                for ($i = 0; $i < $n; $i++) {
                    $out[] = ['Vector3', self::vec($x[$i], $y[$i], $z[$i])];
                }
                return $out;
            case 16:
                $rots = [];
                for ($i = 0; $i < $n; $i++) {
                    $id = ord($b[$o++]);
                    if ($id === 0) {
                        $rots[] = array_values(unpack('g9', substr($b, $o, 36)));
                        $o += 36;
                    } else {
                        $rots[] = self::basicRotation($id);
                    }
                }
                $x = self::floats($b, $o, $n); $y = self::floats($b, $o, $n); $z = self::floats($b, $o, $n);
                for ($i = 0; $i < $n; $i++) {
                    $out[] = ['CoordinateFrame', self::cframe([$x[$i], $y[$i], $z[$i]], $rots[$i])];
                }
                return $out;
            case 18:
                foreach (self::uints($b, $o, $n) as $v) {
                    $out[] = ['token', (string) $v];
                }
                return $out;
            case 19:
                foreach (self::refs($b, $o, $n) as $r) {
                    $out[] = ['Ref', $r < 0 ? 'null' : 'RBX' . $r];
                }
                return $out;
            case 20:
                for ($i = 0; $i < $n; $i++) {
                    [, $x, $y, $z] = unpack('s3', substr($b, $o + 6 * $i, 6));
                    $out[] = ['Vector3int16', "<X>{$x}</X><Y>{$y}</Y><Z>{$z}</Z>"];
                }
                return $out;
            case 21:
            case 22:
                for ($i = 0; $i < $n; $i++) {
                    $k = self::u32($b, $o);
                    $per = $type === 21 ? 3 : 5;
                    $f = array_values(unpack("g" . ($k * $per), substr($b, $o, 4 * $k * $per)));
                    $o += 4 * $k * $per;
                    $out[] = [$type === 21 ? 'NumberSequence' : 'ColorSequence', implode(' ', array_map([self::class, 'num'], $f)) . ' '];
                }
                return $out;
            case 23:
                for ($i = 0; $i < $n; $i++) {
                    [, $a, $c] = unpack('g2', substr($b, $o + 8 * $i, 8));
                    $out[] = ['NumberRange', self::num($a) . ' ' . self::num($c) . ' '];
                }
                return $out;
            case 24:
                $a = self::floats($b, $o, $n); $c = self::floats($b, $o, $n); $e = self::floats($b, $o, $n); $f = self::floats($b, $o, $n);
                for ($i = 0; $i < $n; $i++) {
                    $out[] = ['Rect2D', '<min><X>' . self::num($a[$i]) . '</X><Y>' . self::num($c[$i]) . '</Y></min><max><X>' . self::num($e[$i]) . '</X><Y>' . self::num($f[$i]) . '</Y></max>'];
                }
                return $out;
            case 27:
                $bytes = self::deinterleave($b, $o, $n, 8);
                foreach ($bytes as $v) {
                    $x = unpack('J', $v)[1];
                    $out[] = ['int64', (string) (($x >> 1) ^ -($x & 1))];
                }
                return $out;
            default:
                return null;
        }
    }

    private function writeXml(): string
    {
        $children = [];
        foreach ($this->classOf as $r => $_) {
            $children[$this->parent[$r] ?? -1][] = $r;
        }
        $xml = '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4">' . "\n";
        $xml .= "\t<External>null</External>\n\t<External>nil</External>\n";
        foreach ($children[-1] ?? [] as $r) {
            $xml .= $this->item($r, $children, 1);
        }
        return $xml . "</roblox>\n";
    }

    private function item(int $r, array $children, int $depth): string
    {
        $t = str_repeat("\t", $depth);
        $s = "{$t}<Item class=\"" . self::esc($this->classOf[$r]) . "\" referent=\"RBX{$r}\">\n{$t}\t<Properties>\n";
        foreach ($this->props[$r] ?? [] as $name => [$type, $inner]) {
            $s .= "{$t}\t\t<{$type} name=\"" . self::esc($name) . "\">{$inner}</{$type}>\n";
        }
        $s .= "{$t}\t</Properties>\n";
        foreach ($children[$r] ?? [] as $c) {
            $s .= $this->item($c, $children, $depth + 1);
        }
        return $s . "{$t}</Item>\n";
    }

    public static function nearestBrickColor(int $r, int $g, int $b): int
    {
        $best = 194;
        $bestD = PHP_INT_MAX;
        foreach (self::PALETTE as $id => [$pr, $pg, $pb]) {
            $d = ($r - $pr) ** 2 + ($g - $pg) ** 2 + ($b - $pb) ** 2;
            if ($d < $bestD) {
                $bestD = $d;
                $best = $id;
            }
        }
        return $best;
    }

    public static function cframe(array $p, array $r): string
    {
        $s = '<X>' . self::num($p[0]) . '</X><Y>' . self::num($p[1]) . '</Y><Z>' . self::num($p[2]) . '</Z>';
        foreach (['R00', 'R01', 'R02', 'R10', 'R11', 'R12', 'R20', 'R21', 'R22'] as $i => $k) {
            $s .= "<{$k}>" . self::num($r[$i]) . "</{$k}>";
        }
        return $s;
    }

    public static function vec(float $x, float $y, float $z): string
    {
        return '<X>' . self::num($x) . '</X><Y>' . self::num($y) . '</Y><Z>' . self::num($z) . '</Z>';
    }

    public static function num(float $v): string
    {
        if (is_nan($v) || is_infinite($v)) {
            return '0';
        }
        $s = sprintf('%.9g', $v);
        return $s === '-0' ? '0' : $s;
    }

    public static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', mb_convert_encoding($s, 'UTF-8', 'UTF-8'));
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function cdata(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', mb_convert_encoding($s, 'UTF-8', 'UTF-8'));
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $s) . ']]>';
    }

    private static function basicRotation(int $id): array
    {
        $vectors = [[1, 0, 0], [0, 1, 0], [0, 0, 1], [-1, 0, 0], [0, -1, 0], [0, 0, -1]];
        $id -= 1;
        $xv = $vectors[intdiv($id, 6)];
        $yv = $vectors[$id % 6];
        $zv = [$xv[1] * $yv[2] - $xv[2] * $yv[1], $xv[2] * $yv[0] - $xv[0] * $yv[2], $xv[0] * $yv[1] - $xv[1] * $yv[0]];

        return [$xv[0], $yv[0], $zv[0], $xv[1], $yv[1], $zv[1], $xv[2], $yv[2], $zv[2]];
    }

    private static function u32(string $b, int &$o): int
    {
        $v = unpack('V', substr($b, $o, 4))[1];
        $o += 4;
        return $v;
    }

    private static function i32(string $b, int &$o): int
    {
        $v = unpack('l', substr($b, $o, 4))[1];
        $o += 4;
        return $v;
    }

    private static function str(string $b, int &$o): string
    {
        $n = self::u32($b, $o);
        $s = substr($b, $o, $n);
        $o += $n;
        return $s;
    }

    private static function deinterleave(string $b, int &$o, int $n, int $size): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $v = '';
            for ($j = 0; $j < $size; $j++) {
                $v .= $b[$o + $j * $n + $i];
            }
            $out[] = $v;
        }
        $o += $n * $size;
        return $out;
    }

    private static function uints(string $b, int &$o, int $n): array
    {
        return array_map(fn($v) => unpack('N', $v)[1], self::deinterleave($b, $o, $n, 4));
    }

    private static function ints(string $b, int &$o, int $n): array
    {
        return array_map(function ($x) {
            $v = ($x >> 1) ^ -($x & 1);
            return $v > 0x7FFFFFFF ? $v - 0x100000000 : ($v < -0x80000000 ? $v + 0x100000000 : $v);
        }, self::uints($b, $o, $n));
    }

    private static function floats(string $b, int &$o, int $n): array
    {
        return array_map(function ($x) {
            $bits = (($x >> 1) | (($x & 1) << 31)) & 0xFFFFFFFF;
            return unpack('g', pack('V', $bits))[1];
        }, self::uints($b, $o, $n));
    }

    private static function refs(string $b, int &$o, int $n): array
    {
        $acc = 0;
        return array_map(function ($v) use (&$acc) {
            return $acc += $v;
        }, self::ints($b, $o, $n));
    }
}
