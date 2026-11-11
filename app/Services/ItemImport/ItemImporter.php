<?php

namespace App\Services\ItemImport;

use App\Http\Controllers\Asset as AssetHelper;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;

class ItemImporter
{
    const HAT = 8;
    const HEAD = 17;
    const GEAR = 19;

    const DROP_CLASSES = ['SurfaceAppearance', 'WrapLayer', 'WrapTarget', 'WeldConstraint', 'NoCollisionConstraint',
        'HumanoidDescription', 'BodyPartDescription', 'AccessoryDescription', 'MaterialVariant', 'Animator'];

    private array $meshes = [];
    private array $textures = [];
    private array $meshCenters = [];
    public ?array $headOffset = null;

    public function __construct(private int $author, private string $title)
    {
    }

    public static function import(string $file, string $title, int $author, string $description, int $price, bool $onSale, ?int $expectType = null, ?array $headOffset = null): int
    {
        $importer = new self($author, $title);
        $importer->headOffset = $headOffset;
        [$xml, $type] = $importer->convert($file, $expectType);

        $assetId = AssetHelper::createAsset($title, $type, $author, $xml, $description, 'n', [
            'price' => $price,
            'onSale' => $onSale,
            'isLimited' => false,
            'historicPrice' => [],
            'media' => ['thumbnail' => ''],
        ]);
        AssetHelper::grantToCreator($assetId, $author);

        try {
            match ($type) {
                self::HAT => AssetHelper::renderHat($assetId),
                self::HEAD => AssetHelper::renderHead($assetId),
                default => AssetHelper::renderGear($assetId),
            };
        } catch (\Throwable $e) {
            report($e);
        }

        return $assetId;
    }

    public static function importBodyPart(string $file, string $title, int $author, int $type, ?array $rig = null): int
    {
        $importer = new self($author, $title);
        $xml = $importer->bodyPart($file, $type, $rig);
        $assetId = AssetHelper::createAsset($title, $type, $author, $xml, '', 'n', [
            'price' => 0,
            'onSale' => false,
            'isLimited' => false,
            'historicPrice' => [],
            'media' => ['thumbnail' => ''],
        ]);
        AssetHelper::grantToCreator($assetId, $author);

        return $assetId;
    }

    const BODY_PARTS = [27 => 1, 28 => 3, 29 => 2, 30 => 4, 31 => 5];

    public function bodyPart(string $file, int $type = 0, ?array $rig = null): string
    {
        if (!R15ToR6::hasR6($file) && isset(self::BODY_PARTS[$type])) {
            return $this->r15BodyPart($file, self::BODY_PARTS[$type], $rig);
        }

        $doc = new DOMDocument();
        if (!@$doc->loadXML(RbxmReader::toXml($file), LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new ImportException("The body part file couldn't be read.");
        }
        $xp = new DOMXPath($doc);
        $mesh = $xp->query('//Item[@class="Folder" and Properties/string[@name="Name"]="R6"]/Item[@class="CharacterMesh"]')->item(0)
            ?? $xp->query('//Item[@class="CharacterMesh"]')->item(0);
        if (!$mesh instanceof DOMElement) {
            throw new ImportException('That body part has no R6 version, so the 2016 client can\'t use it.');
        }

        $num = fn(string $name) => (int) trim((string) $this->prop($xp, $mesh, $name));
        $bodyPart = $num('BodyPart');
        $meshId = $num('MeshId');
        if ($bodyPart < 1 || $bodyPart > 5 || $meshId <= 0) {
            throw new ImportException('That body part has no usable R6 mesh.');
        }
        $base = $num('BaseTextureId');
        $overlay = $num('OverlayTextureId');

        $props = [
            '<int64 name="BaseTextureId">' . ($base > 0 ? $this->texture($base) : 0) . '</int64>',
            '<token name="BodyPart">' . $bodyPart . '</token>',
            '<int64 name="MeshId">' . $this->mesh($meshId) . '</int64>',
            '<string name="Name">' . RbxmReader::esc($this->title) . '</string>',
            '<int64 name="OverlayTextureId">' . ($overlay > 0 ? $this->texture($overlay) : 0) . '</int64>',
        ];

        return $this->characterMesh($props);
    }

    private function r15BodyPart(string $file, int $bodyPart, ?array $rig): string
    {
        $parts = R15ToR6::parts($file);
        if (!$parts) {
            throw new ImportException('That body part has no R6 or R15 version the 2016 client can use.');
        }
        [$mesh, $texture] = R15ToR6::build($bodyPart, $parts, $rig, fn(int $id) => self::download($id));
        $meshId = AssetHelper::createAsset("{$this->title} Mesh", 4, $this->author, $mesh, '', 'n', []);
        $textureId = $texture !== null ? AssetHelper::createAsset("{$this->title} Texture", 1, $this->author, $texture, '', 'n', [], true) : 0;

        return $this->characterMesh([
            '<int64 name="BaseTextureId">' . $textureId . '</int64>',
            '<token name="BodyPart">' . $bodyPart . '</token>',
            '<int64 name="MeshId">' . $meshId . '</int64>',
            '<string name="Name">' . RbxmReader::esc($this->title) . '</string>',
            '<int64 name="OverlayTextureId">0</int64>',
        ]);
    }

    private function characterMesh(array $props): string
    {
        return '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4">' . "\n"
            . "\t<External>null</External>\n\t<External>nil</External>\n"
            . "\t<Item class=\"CharacterMesh\" referent=\"RBX0\">\n\t\t<Properties>\n\t\t\t" . implode("\n\t\t\t", $props) . "\n\t\t</Properties>\n\t</Item>\n</roblox>";
    }

    public function convert(string $file, ?int $expectType = null): array
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (!@$doc->loadXML(RbxmReader::toXml($file), LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new ImportException("The model file couldn't be read.");
        }
        $xp = new DOMXPath($doc);

        $root = $xp->query('//Item[@class="Hat" or @class="Accessory" or @class="Accoutrement" or @class="Tool" or @class="HopperBin"]')->item(0);
        if (!$root instanceof DOMElement) {
            $mesh = $xp->query('//Item[@class="SpecialMesh" or @class="FileMesh"]')->item(0);
            if ($mesh instanceof DOMElement) {
                return $this->head($xp, $mesh, $expectType);
            }
            throw new ImportException('The model has no Accessory, Hat, Tool or Head mesh in it.');
        }
        $type = in_array($root->getAttribute('class'), ['Tool', 'HopperBin'], true) ? self::GEAR : self::HAT;
        if ($expectType !== null && $expectType !== $type) {
            throw new ImportException('That model is ' . ($type === self::HAT ? 'a hat/accessory' : 'a tool') . ', pick "' . ($type === self::HAT ? 'Hat' : 'Gear') . '" as the asset type.');
        }

        $wasAccessory = $root->getAttribute('class') === 'Accessory';
        if (in_array($root->getAttribute('class'), ['Accessory', 'Accoutrement'], true)) {
            $root->setAttribute('class', 'Hat');
        }

        foreach (iterator_to_array($xp->query('.//Item', $root)) as $item) {
            if (!$item->parentNode) {
                continue;
            }
            $class = $item->getAttribute('class');
            if (in_array($class, self::DROP_CLASSES, true)
                || ($class === 'Weld' && $this->prop($xp, $item, 'Name') === 'AccessoryWeld')) {
                $item->parentNode->removeChild($item);
            } elseif ($class === 'MeshPart') {
                $this->meshPartToPart($doc, $xp, $item);
            }
        }

        if ($type === self::HAT && !$xp->query('./Item[@class="Part" and Properties/string[@name="Name"]="Handle"]', $root)->length) {
            throw new ImportException('The accessory has no part named "Handle".');
        }

        if ($type === self::HAT && $wasAccessory) {
            $this->attachmentToHatPoint($doc, $xp, $root);
        }

        $kept = [];
        foreach ($xp->query('descendant-or-self::Item', $root) as $item) {
            $kept[$item->getAttribute('referent')] = true;
        }
        foreach ($xp->query('.//Ref', $root) as $ref) {
            if ($ref->textContent !== 'null' && !isset($kept[$ref->textContent])) {
                $ref->textContent = 'null';
            }
        }

        foreach ($xp->query('.//Content', $root) as $content) {
            $url = $xp->query('./url', $content)->item(0);
            $id = $url ? self::robloxAssetId($url->textContent) : null;
            if ($id === null) {
                continue;
            }
            $name = $content->getAttribute('name');
            $class = $content->parentNode->parentNode->getAttribute('class');
            if ($name === 'MeshId') {
                $url->textContent = $this->siteUrl($this->mesh($id));
                $owner = $content->parentNode->parentNode;
                if ($owner->getAttribute('data-from-meshpart') && ($c = $this->meshCenters[$id] ?? null)) {
                    $this->recenter($doc, $xp, $owner, $c);
                }
            } elseif (in_array($name, ['TextureId', 'TextureID', 'Texture'], true) && in_array($class, ['SpecialMesh', 'FileMesh', 'Decal', 'Texture', 'Tool', 'HopperBin'], true)) {
                $url->textContent = $this->siteUrl($this->texture($id));
            }
        }

        foreach (iterator_to_array($xp->query('.//Item[@data-from-meshpart]', $root)) as $m) {
            $m->removeAttribute('data-from-meshpart');
        }

        $out = new DOMDocument('1.0', 'utf-8');
        $out->formatOutput = true;
        $robloxEl = $out->appendChild($out->createElement('roblox'));
        foreach (['xmlns:xmime' => 'http://www.w3.org/2005/05/xmlmime', 'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
            'xsi:noNamespaceSchemaLocation' => 'http://www.roblox.com/roblox.xsd', 'version' => '4'] as $k => $v) {
            $robloxEl->setAttribute($k, $v);
        }
        $robloxEl->appendChild($out->createElement('External', 'null'));
        $robloxEl->appendChild($out->createElement('External', 'nil'));
        $robloxEl->appendChild($out->importNode($root, true));

        return [preg_replace('/^<\?xml[^>]*>\s*/', '', $out->saveXML(null, LIBXML_NOEMPTYTAG)), $type];
    }

    private function meshPartToPart(DOMDocument $doc, DOMXPath $xp, DOMElement $item): void
    {
        $props = $xp->query('./Properties', $item)->item(0);
        $meshId = $this->contentUrl($xp, $props, 'MeshId');
        $textureId = $this->contentUrl($xp, $props, 'TextureID');

        $sa = $xp->query('./Item[@class="SurfaceAppearance"]', $item)->item(0);
        if ($textureId === '' && $sa) {
            $textureId = $this->contentUrl($xp, $xp->query('./Properties', $sa)->item(0), 'ColorMap');
        }

        $size = $this->vector($xp, $props, 'size') ?? [1, 1, 1];
        $initial = $this->vector($xp, $props, 'InitialSize') ?? $size;
        $scale = array_map(fn($s, $i) => abs($i) > 1e-6 ? $s / $i : 1, $size, $initial);

        foreach (['MeshId', 'TextureID', 'InitialSize', 'RenderFidelity', 'CollisionFidelity', 'DoubleSided', 'HasJointOffset', 'HasSkinnedMesh', 'JointOffset', 'FluidFidelityInternal'] as $p) {
            foreach (iterator_to_array($xp->query("./*[@name='{$p}']", $props)) as $node) {
                $props->removeChild($node);
            }
        }
        $item->setAttribute('class', 'Part');

        $mesh = $doc->createElement('Item');
        $mesh->setAttribute('class', 'SpecialMesh');
        $mesh->setAttribute('referent', $item->getAttribute('referent') . 'M');
        $mesh->setAttribute('data-from-meshpart', '1');
        $mp = $mesh->appendChild($doc->createElement('Properties'));
        $add = function (string $tag, string $name, string $inner) use ($doc, $mp) {
            $f = $doc->createDocumentFragment();
            $f->appendXML("<{$tag} name=\"{$name}\">{$inner}</{$tag}>");
            $mp->appendChild($f);
        };
        $add('token', 'MeshType', '5');
        $add('Content', 'MeshId', $meshId === '' ? '<null></null>' : '<url>' . RbxmReader::esc($meshId) . '</url>');
        $add('Content', 'TextureId', $textureId === '' ? '<null></null>' : '<url>' . RbxmReader::esc($textureId) . '</url>');
        $add('string', 'Name', 'Mesh');
        $add('Vector3', 'Scale', RbxmReader::vec(...$scale));
        $add('Vector3', 'VertexColor', RbxmReader::vec(1, 1, 1));
        $item->insertBefore($mesh, $props->nextSibling);
    }

    const HEAD_ATTACHMENTS = [
        'HairAttachment' => [0, 0.6, 0],
        'HatAttachment' => [0, 0.6, 0],
        'FaceFrontAttachment' => [0, 0, -0.6],
        'FaceCenterAttachment' => [0, 0, 0],
        'NeckAttachment' => [0, -0.5, 0],
        'BodyFrontAttachment' => [0, -1.5, -0.5],
        'BodyBackAttachment' => [0, -1.5, 0.5],
        'LeftCollarAttachment' => [-1, -0.5, 0],
        'RightCollarAttachment' => [1, -0.5, 0],
        'WaistFrontAttachment' => [0, -2.5, -0.5],
        'WaistCenterAttachment' => [0, -2.5, 0],
        'WaistBackAttachment' => [0, -2.5, 0.5],
        'LeftShoulderAttachment' => [-1.5, -0.5, 0],
        'RightShoulderAttachment' => [1.5, -0.5, 0],
        'LeftFootAttachment' => [-0.5, -4, 0],
        'RightFootAttachment' => [0.5, -4, 0],
        'RootAttachment' => [0, -1.5, 0],
    ];

    const HAT_MOUNT = [0, 0.5, 0];

    private function attachmentToHatPoint(DOMDocument $doc, DOMXPath $xp, DOMElement $root): void
    {
        $handle = $xp->query('./Item[@class="Part" and Properties/string[@name="Name"]="Handle"]', $root)->item(0);
        if (!$handle instanceof DOMElement) {
            return;
        }

        foreach ($xp->query('./Item[@class="Attachment"]', $handle) as $att) {
            $name = $this->prop($xp, $att, 'Name');
            if (!isset(self::HEAD_ATTACHMENTS[$name])) {
                continue;
            }
            $cf = $this->cframe($xp, $xp->query('./Properties/CoordinateFrame[@name="CFrame"]', $att)->item(0));
            if ($cf === null) {
                continue;
            }
            $mount = self::HEAD_ATTACHMENTS[$name];
            $d = [self::HAT_MOUNT[0] - $mount[0], self::HAT_MOUNT[1] - $mount[1], self::HAT_MOUNT[2] - $mount[2]];
            $r = $cf['r'];
            $pos = [];
            for ($i = 0; $i < 3; $i++) {
                $pos[$i] = $cf['p'][$i] + $r[$i * 3] * $d[0] + $r[$i * 3 + 1] * $d[1] + $r[$i * 3 + 2] * $d[2];
            }

            $props = $xp->query('./Properties', $root)->item(0);
            foreach (iterator_to_array($xp->query('./*[@name="AttachmentPoint"]', $props)) as $old) {
                $props->removeChild($old);
            }
            $inner = '<X>' . RbxmReader::num($pos[0]) . '</X><Y>' . RbxmReader::num($pos[1]) . '</Y><Z>' . RbxmReader::num($pos[2]) . '</Z>';
            foreach (['R00', 'R01', 'R02', 'R10', 'R11', 'R12', 'R20', 'R21', 'R22'] as $k => $tag) {
                $inner .= "<{$tag}>" . RbxmReader::num($r[$k]) . "</{$tag}>";
            }
            $f = $doc->createDocumentFragment();
            $f->appendXML('<CoordinateFrame name="AttachmentPoint">' . $inner . '</CoordinateFrame>');
            $props->appendChild($f);
            return;
        }
    }

    private function cframe(DOMXPath $xp, $node): ?array
    {
        if (!$node) {
            return null;
        }
        $get = fn($k) => (float) ($xp->query("./{$k}", $node)->item(0)?->textContent ?? 0);
        return [
            'p' => [$get('X'), $get('Y'), $get('Z')],
            'r' => array_map($get, ['R00', 'R01', 'R02', 'R10', 'R11', 'R12', 'R20', 'R21', 'R22']),
        ];
    }

    private function prop(DOMXPath $xp, DOMElement $item, string $name): ?string
    {
        $n = $xp->query("./Properties/*[@name='{$name}']", $item)->item(0);
        return $n?->textContent;
    }

    private function head(DOMXPath $xp, DOMElement $mesh, ?int $expectType): array
    {
        if ($expectType !== null && $expectType !== self::HEAD) {
            throw new ImportException('That model is a head mesh, pick "Head" as the asset type.');
        }

        $props = $xp->query('./Properties', $mesh)->item(0);
        $meshId = $this->robloxAssetId($this->contentUrl($xp, $props, 'MeshId'));
        $textureId = $this->robloxAssetId($this->contentUrl($xp, $props, 'TextureId'));

        $out = new DOMDocument('1.0', 'utf-8');
        $out->formatOutput = true;
        $robloxEl = $out->appendChild($out->createElement('roblox'));
        foreach (['xmlns:xmime' => 'http://www.w3.org/2005/05/xmlmime', 'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
            'xsi:noNamespaceSchemaLocation' => 'http://www.roblox.com/roblox.xsd', 'version' => '4'] as $k => $v) {
            $robloxEl->setAttribute($k, $v);
        }
        $robloxEl->appendChild($out->createElement('External', 'null'));
        $robloxEl->appendChild($out->createElement('External', 'nil'));

        $item = $robloxEl->appendChild($out->createElement('Item'));
        $item->setAttribute('class', 'SpecialMesh');
        $item->setAttribute('referent', 'RBX0');
        $mp = $item->appendChild($out->createElement('Properties'));
        $add = function (string $tag, string $name, string $inner) use ($out, $mp) {
            $f = $out->createDocumentFragment();
            $f->appendXML("<{$tag} name=\"{$name}\">{$inner}</{$tag}>");
            $mp->appendChild($f);
        };

        $meshType = $this->prop($xp, $mesh, 'MeshType');
        $add('token', 'MeshType', $meshType !== null && $meshType !== '' ? $meshType : ($meshId === null ? '0' : '5'));
        $add('Content', 'MeshId', $meshId === null ? '<null></null>' : '<url>' . $this->siteUrl($this->mesh($meshId)) . '</url>');
        $add('Content', 'TextureId', $textureId === null ? '<null></null>' : '<url>' . $this->siteUrl($this->texture($textureId)) . '</url>');
        $add('string', 'Name', 'Mesh');
        $scale = $this->vector($xp, $props, 'Scale') ?? [1, 1, 1];
        $offset = $this->vector($xp, $props, 'Offset') ?? [0, 0, 0];
        if ($this->headOffset !== null && $meshId !== null) {
            $c = $this->meshCenters[$meshId] ?? [0, 0, 0];
            $offset = [$this->headOffset[0] - $c[0] * $scale[0], $this->headOffset[1] - $c[1] * $scale[1], $this->headOffset[2] - $c[2] * $scale[2]];
        }
        if ($offset !== [0, 0, 0]) {
            $add('Vector3', 'Offset', RbxmReader::vec(...$offset));
        }
        $add('Vector3', 'Scale', RbxmReader::vec(...$scale));
        $add('Vector3', 'VertexColor', RbxmReader::vec(1, 1, 1));

        return [preg_replace('/^<\?xml[^>]*>\s*/', '', $out->saveXML(null, LIBXML_NOEMPTYTAG)), self::HEAD];
    }

    private function contentUrl(DOMXPath $xp, $props, string $name): string
    {
        $n = $props ? $xp->query("./Content[@name='{$name}']/url", $props)->item(0) : null;
        return $n ? trim($n->textContent) : '';
    }

    private function vector(DOMXPath $xp, $props, string $name): ?array
    {
        $n = $xp->query("./Vector3[@name='{$name}']", $props)->item(0);
        if (!$n) {
            return null;
        }
        return array_map(fn($k) => (float) ($xp->query("./{$k}", $n)->item(0)?->textContent ?? 1), ['X', 'Y', 'Z']);
    }

    public static function robloxAssetId(string $url): ?int
    {
        $url = trim($url);
        if (preg_match('#^rbxassetid://(\d+)#i', $url, $m)
            || preg_match('#^https?://(?:www\.|assetgame\.|assetdelivery\.)?roblox\.com/(?:v1/)?asset/?\?(?:.*&)?id=(\d+)#i', $url, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function siteUrl(int $siteId): string
    {
        return 'http://www.' . config('finobe.domain') . '/asset/?id=' . $siteId;
    }

    private function mesh(int $robloxId): int
    {
        if (!isset($this->meshes[$robloxId])) {
            $mesh = MeshConverter::toLegacy(self::download($robloxId));
            $this->meshCenters[$robloxId] = MeshConverter::center($mesh);
            $this->meshes[$robloxId] = AssetHelper::createAsset("{$this->title} Mesh", 4, $this->author, $mesh, '', 'n', []);
        }
        return $this->meshes[$robloxId];
    }

    private function recenter(DOMDocument $doc, DOMXPath $xp, DOMElement $mesh, array $center): void
    {
        $props = $xp->query('./Properties', $mesh)->item(0);
        $scale = $this->vector($xp, $props, 'Scale') ?? [1, 1, 1];
        $f = $doc->createDocumentFragment();
        $f->appendXML('<Vector3 name="Offset">' . RbxmReader::vec(-$center[0] * $scale[0], -$center[1] * $scale[1], -$center[2] * $scale[2]) . '</Vector3>');
        $props->appendChild($f);
    }

    private function texture(int $robloxId): int
    {
        if (isset($this->textures[$robloxId])) {
            return $this->textures[$robloxId];
        }
        $data = self::download($robloxId);

        if (str_starts_with(ltrim($data), '<roblox') && preg_match('#<Content name="Texture">\s*<url>([^<]+)</url>#', RbxmReader::toXml($data), $m)
            && ($inner = self::robloxAssetId($m[1])) !== null) {
            $data = self::download($inner);
        }
        if (!str_starts_with($data, "\x89PNG") && !str_starts_with($data, "\xFF\xD8") && !str_starts_with($data, 'RIFF') && !str_starts_with($data, 'GIF8')) {
            throw new ImportException("Texture {$robloxId} isn't an image.");
        }

        return $this->textures[$robloxId] = AssetHelper::createAsset("{$this->title} Texture", 1, $this->author, $data, '', 'n', [], true);
    }

    public static function download(int $id): string
    {
        $headers = ['User-Agent' => 'Roblox/WinInet'];
        $cookie = (string) config('finobe.roblosecurity');
        if ($cookie !== '') {
            $headers['Cookie'] = '.ROBLOSECURITY=' . $cookie;
        }

        try {
            $r = Http::withHeaders($headers)->timeout(30)->retry(2, 500, throw: false)
                ->get(rtrim((string) config('finobe.assetdelivery'), '/') . '/v1/asset/', ['id' => $id]);
        } catch (\Throwable $e) {
            throw new ImportException("Couldn't reach Roblox to download asset {$id}, try again.");
        }

        if ($r->status() === 401 || $r->status() === 403) {
            throw new ImportException("Roblox only lets a logged-in account download asset {$id}"
                . ($cookie === '' ? ' (set FINOBE_ROBLOSECURITY in the site\'s .env).' : ' (FINOBE_ROBLOSECURITY may have expired).'));
        }
        if (!$r->ok() || $r->body() === '') {
            throw new ImportException("Roblox didn't give asset {$id} (HTTP {$r->status()}).");
        }
        return $r->body();
    }
}
