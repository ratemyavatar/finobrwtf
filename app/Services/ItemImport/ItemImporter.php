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

    public function __construct(private int $author, private string $title)
    {
    }

    public static function import(string $file, string $title, int $author, string $description, int $price, bool $onSale, ?int $expectType = null): int
    {
        $importer = new self($author, $title);
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
