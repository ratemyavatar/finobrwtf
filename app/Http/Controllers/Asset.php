<?php

namespace App\Http\Controllers;

use FFMpeg\FFMpeg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class Asset extends Controller
{
    protected $db;

    public function __construct() {
        $this->db = DB::connection('finobe');
    }

    /*
        ensureDirectory
         - creates a folder the asset pipeline writes into if it doesn't exist yet
    */
    public static function ensureDirectory(string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }

    /*
        grantToCreator
         - the person who makes an item owns it (so it's in their inventory and avatar editor)
    */
    public static function grantToCreator(int $assetId, int $authorId): void
    {
        $username = \App\Models\User::where('id', $authorId)->value('username');

        if (!$username) {
            return;
        }

        $owned = \App\Models\Purchases::where('username', $username)->where('assetid', $assetId)->where('type', 1)->exists();

        if (!$owned) {
            \App\Models\Purchases::create([
                'username' => $username,
                'assetid' => $assetId,
                'serial' => 0,
                'author' => $authorId,
                'amount' => 0,
                'type' => 1,
            ]);
        }
    }

    public static function createAsset(string $name, int $assetType, int $author, $file, string $description, string $visibility, array $additional, bool $isEncodable = false): int
    {
        $instance = new self();
        $fileHash = uniqid();

        if($isEncodable) {
            // PHP's temp folder is always writable by the web server, unlike storage/ after a root-owned deploy
            $tmp = tempnam(sys_get_temp_dir(), 'finobe_asset_');
            $ffmpeg = FFMpeg::create();
            file_put_contents($tmp, $file);

            try {
                $png = $ffmpeg->open($tmp);
                $png->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds(0))->save($tmp . '_tmp.png');
                $file = file_get_contents($tmp . '_tmp.png');
            } finally {
                @unlink($tmp);
                @unlink($tmp . '_tmp.png');
            }
        }

        $additional = json_encode($additional);
        $asset = $instance->db->table('assets')->insertGetId([
            'asset_type' => $assetType,
            'title' => $name,
            'author' => $author,
            'file' => $fileHash,
            'description' => $description,
            'visibility' => $visibility,
            'additional' => $additional
        ]);
        file_put_contents(self::ensureDirectory(config('finobe.cdn_path') . '/assets') . "/{$fileHash}", $file);
        return $asset;
    }

    public static function render(int $id, int $assetType): string
    {
        if (self::isAssetExist($id))
        {
            switch ($assetType) {
                case 2:
                    return self::renderTShirt($id);
                case 8:
                    return self::renderHat($id);
                case 11:
                    return self::renderShirt($id);
                case 12:
                    return self::renderPants($id);
                case 17:
                    return self::renderHead($id);
                case 19:
                    return self::renderGear($id);
                default:
                    return "";
            }
        }

        return "";
    }

    public static function renderHat(int $id) : string
    {
        if (self::isAssetExist($id)) {
            $arbiter = new RobloxArbiterUtilities(DataController::arbiter_pool(), (int) config('finobe.arbiter.port'));
            $constructedJob = $arbiter->ConstructJob(
                RobloxUtilities::GenerateGUID(),
                '
                    local assetid, asseturl, url, fileExtension, x, y = ...
                    
                    print("Render Hat " .. assetid)
                    
                    pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)
                    game:GetService("ThumbnailGenerator").GraphicsMode = 4
                    game:GetService("ScriptContext").ScriptsDisabled = true
                    game:GetObjects(asseturl)[1].Parent = workspace
                    t = game:GetService("ThumbnailGenerator")
                    return t:Click(fileExtension, x, y, true, true)
                ',
                60,
                0,
                2,
                "ScriptExecution",
                [$id, "https://www.finobe.wtf/asset/?id={$id}", "https://www.finobe.wtf", "PNG", 768, 768]
            );

            $jobEx = $arbiter->OpenJobEx($constructedJob);
            $filename = uniqid() . ".png";

            if(empty(base64_decode($jobEx))) {
                throw new \Exception('There was an error while rendering. Please try again later.');
            }

            file_put_contents(config('finobe.cdn_path') . "/thumbnails/{$filename}", base64_decode($jobEx));

            $instance = new self();
            $hatData = self::getAssetData($id);
            $additional = json_decode($hatData->additional);
            $additional->media->thumbnail = "https://cdn.finobe.wtf/thumbnails/{$filename}";
            $additional = json_encode($additional);
            $instance->db->table('assets')
                ->where('id', $id)
                ->update([
                    'additional' => $additional
                ]);

            return "https://cdn.finobe.wtf/thumbnails/{$filename}";
        }
        return "";
    }

    /**
     * The 2016 client/RCC XML parser rejects self-closing tags ("Unknown tag 'null/'"),
     * so an item saved with them renders blank and can't be worn. Expand empty tags to paired form.
     */
    public static function normalizeItemXml(string $xml): string
    {
        if (!str_contains($xml, '/>')) {
            return $xml;
        }

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        if (!@$doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            return $xml;
        }

        // the 2016 loader doesn't expect an XML declaration
        return preg_replace('/^<\?xml[^>]*>\s*/', '', $doc->saveXML(null, LIBXML_NOEMPTYTAG));
    }

    public static function createHatOrGear(string $name, $texture = false, $mesh = false, array $xml, int $author, string $description, int $price, bool $onSale = false, bool $isLimited = false, array $historicalPrice = [], int $type): int
    {
        if(is_array($texture)) {
            $textureId = self::createAsset("{$name} Texture", 1, $author, file_get_contents($texture['tmp_name']), "", "n", [], true);
        }
        
        if(is_array($mesh)) {
            $meshId = self::createAsset("{$name} Mesh", 4, $author, file_get_contents($mesh['tmp_name']), "", "n", []);
        }

        $xmltemplate = file_get_contents($xml['tmp_name']);

        // finds and replaces roblox links with MESHURLPLACEHOLDER or TEXTUREURLPLACEHOLDER automatically EDIT: also check rbxassetid format
        if(is_array($mesh)) {
            $xmltemplate = preg_replace_callback(
                '/<Content name="MeshId">\s*<url>(https?:\/\/www\.roblox\.com\/asset\/\?id=\d+|rbxassetid:\/\/\d+|https?:\/\/assetdelivery\.roblox\.com\/v1\/asset\/\?id=\d+)<\/url>\s*<\/Content>/i',
                function ($matches) {
                    return str_replace($matches[1], 'MESHURLPLACEHOLDER', $matches[0]);
                },
                $xmltemplate
            );
        }
        
        if(is_array($texture)) {
            $xmltemplate = preg_replace_callback(
                '/<Content name="TextureId">\s*<url>(https?:\/\/www\.roblox\.com\/asset\/\?id=\d+|rbxassetid:\/\/\d+|https?:\/\/assetdelivery\.roblox\.com\/v1\/asset\/\?id=\d+)<\/url>\s*<\/Content>/i',
                function ($matches) {
                    return str_replace($matches[1], 'TEXTUREURLPLACEHOLDER', $matches[0]);
                },
                $xmltemplate
            );
        }

        if(is_array($texture)) {
            $xmltemplate =str_replace("TEXTUREURLPLACEHOLDER", "http://www.finobe.wtf/asset/?id=" . $textureId, $xmltemplate);
        }

        if(is_array($mesh)) {
            $xmltemplate =str_replace("MESHURLPLACEHOLDER",  "http://www.finobe.wtf/asset/?id=" . $meshId, $xmltemplate);
        }

        $xmltemplate = self::normalizeItemXml($xmltemplate);
        $assetId = self::createAsset($name, $type, $author, $xmltemplate, $description, "n", [
            "price" => $price,
            "onSale" => $onSale,
            "isLimited" => $isLimited,
            "historicPrice" => $historicalPrice,
            "media" => [
                "thumbnail" => ""
            ]
        ]);

        self::grantToCreator($assetId, $author);

        // a failed render (arbiter down) shouldn't undo the upload
        try {
            if($type == 8) {
                self::renderHat($assetId);
            } else {
                self::renderGear($assetId);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $assetId;
    }

    public static function createAccessory(string $name, array $texture, int $author, string $description, int $price, bool $onSale = false, bool $isLimited = false, string $type = "face"): int //type: shirt/pants/tshirt/face
    {
        $textureId = self::createAsset("{$name} Texture", 1, $author, file_get_contents($texture['tmp_name']), '', "r", [], true);
        $assetType = 18;
        $xmlTemplate = "";
        if($type == "face") {
            $assetType = 18;
            $xmlTemplate = '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4">
                <External>null</External>
                <External>nil</External>
                <Item class="Decal" referent="RBX0">
                    <Properties>
                        <token name="Face">5</token>
                        <string name="Name">face</string>
                        <float name="Shiny">20</float>
                        <float name="Specular">0</float>
                        <Content name="Texture"><url>TEXTUREURLPLACEHOLDER</url></Content>
                        <bool name="archivable">true</bool>
                    </Properties>
                </Item>
            </roblox>';
        } elseif($type == "tshirt") {
            $assetType = 2;
            $xmlTemplate = '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4">
                <External>null</External>
                <External>nil</External>
                <Item class="ShirtGraphic" referent="RBX0">
                    <Properties>
                    <Content name="Graphic">
                        <url>TEXTUREURLPLACEHOLDER</url>
                    </Content>
                    <string name="Name">Shirt Graphic</string>
                    <bool name="archivable">true</bool>
                    </Properties>
                </Item>
            </roblox>';
        } elseif($type == "shirt") {
            $assetType = 11;
            $xmlTemplate = '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4">
                <External>null</External>
                <External>nil</External>
                <Item class="Shirt" referent="RBX0">
                    <Properties>
                    <Content name="ShirtTemplate">
                        <url>TEXTUREURLPLACEHOLDER</url>
                    </Content>
                    <string name="Name">Shirt</string>
                    <bool name="archivable">true</bool>
                    </Properties>
                </Item>
            </roblox>';
        } elseif($type == "pants") {
            $assetType = 12;
            $xmlTemplate = '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4">
                <External>null</External>
                <External>nil</External>
                <Item class="Pants" referent="RBX0">
                    <Properties>
                    <Content name="PantsTemplate">
                        <url>TEXTUREURLPLACEHOLDER</url>
                    </Content>
                    <string name="Name">Pants</string>
                    <bool name="archivable">true</bool>
                    </Properties>
                </Item>
            </roblox>';
        }
        $xmlTemplate =str_replace("TEXTUREURLPLACEHOLDER", "http://www.finobe.wtf/asset/?id=" . $textureId, $xmlTemplate);
        $xmlTemplate = self::normalizeItemXml($xmlTemplate);
        $accessoryId = self::createAsset($name, $assetType, $author, $xmlTemplate, $description, "r", [
            "price" => $price,
            "onSale" => $onSale,
            "media" => [
                "thumbnail" => "",
                "textureAssetId" => $textureId
            ]
        ]);
        if ($assetType == 18)
        {
            if($assetType == 18) {
                $thumb = self::getImage($accessoryId, $textureId);
            } else {
                $thumb = self::getImage($accessoryId);
            }
            
            $instance = new self();
            $hatData = self::getAssetData($accessoryId);
            $additional = json_decode($hatData->additional);
            $additional->media->thumbnail = $thumb;
            $additional = json_encode($additional);
            $instance->db->table('assets')
                ->where('id', $accessoryId)
                ->update([
                    'additional' => $additional
                ]);
        }
        self::grantToCreator($accessoryId, $author);

        // a failed render (arbiter down) shouldn't undo the upload
        if ($assetType != 18) {
            try {
                self::render($accessoryId, $assetType);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        return $accessoryId;
    }

    public static function getImage(int $id, int $textureId = 0): string
    {
        if (self::isAssetExist($id))
        {
            $imageData = self::getAssetData($id);
            $additional = json_decode($imageData->additional, true);

            if($imageData->asset_type == 18) {
                $textureData = self::getAssetData($textureId);
                return "https://cdn.finobe.wtf/assets/{$textureData->file}";
            }
            
            return $additional->media->thumbnail ?? "";
        }

        return "";
    }

    public static function isAssetExist(int $assetId) : bool
    {
        $instance = new self();
        return $instance->db->table('assets')->where('id', $assetId)->exists();
    }

    public static function getAssetData(int $assetId) : object
    {
        $instance = new self();
        if ($instance->db->table('assets')->where('id', $assetId)->exists()) {
            return $instance->db->table('assets')->where('id', $assetId)->first();
        }
        return (object)[];
    }

    public static function getCdnLink(int $assetId): string
    {
        If (self::isAssetExist($assetId)) {
            $assetinfo = (object)self::getAssetData($assetId);
            //return "http://cdn.finobe.wtf/assets/{$assetinfo->file}"; why?
            return config('finobe.cdn_path') . "/assets/{$assetinfo->file}";
        }
        return "http://cdn.finobe.wtf/";
    }

    public static function isAllowedToDownload(int $assetId): bool
    {
        $user = Auth::user();

        If (self::isAssetExist($assetId)) {
            $assetinfo = (object)self::getAssetData($assetId);
            $additional = json_decode($assetinfo->additional);

            if (RobloxUtilities::IsFinobeCloudAuthorized())
            {
                return true;
            }

            if ($assetinfo->asset_type != 9 || (($assetinfo->asset_type == 9 && $additional->uncopylocked) || ($additional->onSale ?? false))) {
                return true;
            } else {
                if ($assetinfo->author == ($user->id ?? 0)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function renderPants(int $id)
    {
        if (self::isAssetExist($id)) {
            $arbiter = new RobloxArbiterUtilities(DataController::arbiter_pool(), (int) config('finobe.arbiter.port'));
            $constructedJob = $arbiter->ConstructJob(
                RobloxUtilities::GenerateGUID(),
                '
                    local assetid, asseturl, url, fileExtension, x, y = ...
                    
                    print("Render Pants " .. assetid)
                    
                    pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)
                    game:GetService("ThumbnailGenerator").GraphicsMode = 4
                    game:GetService("ScriptContext").ScriptsDisabled = true
                    player = game:GetService("Players"):CreateLocalPlayer(0)
                    player:LoadCharacter(false)
                    c = Instance.new("Pants")
                    c.PantsTemplate = game:GetObjects(asseturl)[1].PantsTemplate
                    c.Parent = player.Character

                    t = game:GetService("ThumbnailGenerator")
                    return t:Click(fileExtension, x, y, true, true)
                ',
                60,
                0,
                2,
                "ScriptExecution",
                [$id, "https://www.finobe.wtf/asset/?id={$id}", "https://www.finobe.wtf", "PNG", 768, 768]
            );

            $jobEx = $arbiter->OpenJobEx($constructedJob);
            $filename = uniqid() . ".png";

            if(empty(base64_decode($jobEx))) {
                throw new \Exception('There was an error while rendering. Please try again later.');
            }

            file_put_contents(config('finobe.cdn_path') . "/thumbnails/{$filename}", base64_decode($jobEx));

            $instance = new self();
            $hatData = self::getAssetData($id);
            $additional = json_decode($hatData->additional);
            $additional->media->thumbnail = "https://cdn.finobe.wtf/thumbnails/{$filename}";
            $additional = json_encode($additional);
            $instance->db->table('assets')
                ->where('id', $id)
                ->update([
                    'additional' => $additional
                ]);

            return "https://cdn.finobe.wtf/thumbnails/{$filename}";
        }
        return "";
    }

    public static function renderShirt(int $id)
    {
        if (self::isAssetExist($id)) {
            $arbiter = new RobloxArbiterUtilities(DataController::arbiter_pool(), (int) config('finobe.arbiter.port'));
            $constructedJob = $arbiter->ConstructJob(
                RobloxUtilities::GenerateGUID(),
                '
                    local assetid, asseturl, url, fileExtension, x, y = ...
                    
                    print("Render Shirt " .. assetid)
                    
                    pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)
                    game:GetService("ThumbnailGenerator").GraphicsMode = 4
                    game:GetService("ScriptContext").ScriptsDisabled = true
                    player = game:GetService("Players"):CreateLocalPlayer(0)
                     player:LoadCharacter(false)
                    c = Instance.new("Shirt")
                    c.ShirtTemplate = game:GetObjects(asseturl)[1].ShirtTemplate
                    c.Parent = player.Character

                    t = game:GetService("ThumbnailGenerator")
                    return t:Click(fileExtension, x, y, true, true)
                ',
                60,
                0,
                2,
                "ScriptExecution",
                [$id, "https://www.finobe.wtf/asset/?id={$id}", "https://www.finobe.wtf", "PNG", 768, 768]
            );

            $jobEx = $arbiter->OpenJobEx($constructedJob);
            $filename = uniqid() . ".png";

            if(empty(base64_decode($jobEx))) {
                throw new \Exception('There was an error while rendering. Please try again later.');
            }

            file_put_contents(config('finobe.cdn_path') . "/thumbnails/{$filename}", base64_decode($jobEx));

            $instance = new self();
            $hatData = self::getAssetData($id);
            $additional = json_decode($hatData->additional);
            $additional->media->thumbnail = "https://cdn.finobe.wtf/thumbnails/{$filename}";
            $additional = json_encode($additional);
            $instance->db->table('assets')
                ->where('id', $id)
                ->update([
                    'additional' => $additional
                ]);

            return "https://cdn.finobe.wtf/thumbnails/{$filename}";
        }
        return "";
    }

    public static function renderTShirt(int $id)
    {
        if (self::isAssetExist($id)) {
            $arbiter = new RobloxArbiterUtilities(DataController::arbiter_pool(), (int) config('finobe.arbiter.port'));
            $constructedJob = $arbiter->ConstructJob(
                RobloxUtilities::GenerateGUID(),
                '
                    local assetid, asseturl, url, fileExtension, x, y = ...
                    
                    print("Render Shirt " .. assetid)
                    
                    pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)
                    game:GetService("ThumbnailGenerator").GraphicsMode = 4
                    game:GetService("ScriptContext").ScriptsDisabled = true
                    player = game:GetService("Players"):CreateLocalPlayer(0)
                     player:LoadCharacter(false)
                    c = Instance.new("ShirtGraphic")
                    c.Graphic = game:GetObjects(asseturl)[1].Graphic
                    c.Parent = player.Character

                    t = game:GetService("ThumbnailGenerator")
                    return t:Click(fileExtension, x, y, true, true)
                ',
                60,
                0,
                2,
                "ScriptExecution",
                [$id, "https://www.finobe.wtf/asset/?id={$id}", "https://www.finobe.wtf", "PNG", 768, 768]
            );

            $jobEx = $arbiter->OpenJobEx($constructedJob);
            $filename = uniqid() . ".png";

            if(empty(base64_decode($jobEx))) {
                throw new \Exception('There was an error while rendering. Please try again later.');
            }

            file_put_contents(config('finobe.cdn_path') . "/thumbnails/{$filename}", base64_decode($jobEx));

            $instance = new self();
            $hatData = self::getAssetData($id);
            $additional = json_decode($hatData->additional);
            $additional->media->thumbnail = "https://cdn.finobe.wtf/thumbnails/{$filename}";
            $additional = json_encode($additional);
            $instance->db->table('assets')
                ->where('id', $id)
                ->update([
                    'additional' => $additional
                ]);

            return "https://cdn.finobe.wtf/thumbnails/{$filename}";
        }
        return "";
    }

    public static function renderGear(int $id) : string
    {
        if (self::isAssetExist($id)) {
            $arbiter = new RobloxArbiterUtilities(DataController::arbiter_pool(), (int) config('finobe.arbiter.port'));
            $constructedJob = $arbiter->ConstructJob(
                RobloxUtilities::GenerateGUID(),
                '
                    local assetid, asseturl, url, fileExtension, x, y = ...
                    
                    print("Render Gear " .. assetid)
                    
                    pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)
                    game:GetService("ThumbnailGenerator").GraphicsMode = 4
                    game:GetService("ScriptContext").ScriptsDisabled = true
                    game:GetObjects(asseturl)[1].Parent = workspace
                    t = game:GetService("ThumbnailGenerator")
                    return t:Click(fileExtension, x, y, true, true)
                ',
                60,
                0,
                2,
                "ScriptExecution",
                [$id, "https://www.finobe.wtf/asset/?id={$id}", "https://www.finobe.wtf", "PNG", 768, 768]
            );

            $jobEx = $arbiter->OpenJobEx($constructedJob);
            $filename = uniqid() . ".png";

            if(empty(base64_decode($jobEx))) {
                throw new \Exception('There was an error while rendering. Please try again later.');
            }

            file_put_contents(config('finobe.cdn_path') . "/thumbnails/{$filename}", base64_decode($jobEx));

            $instance = new self();
            $hatData = self::getAssetData($id);
            $additional = json_decode($hatData->additional);
            $additional->media->thumbnail = "https://cdn.finobe.wtf/thumbnails/{$filename}";
            $additional = json_encode($additional);
            $instance->db->table('assets')
                ->where('id', $id)
                ->update([
                    'additional' => $additional
                ]);

            return "https://cdn.finobe.wtf/thumbnails/{$filename}";
        }
        return "";
    }

    /*
        renderHead
         - a custom head is a bare SpecialMesh; put it on a grey head part and click
    */
    public static function renderHead(int $id) : string
    {
        if (self::isAssetExist($id)) {
            $arbiter = new RobloxArbiterUtilities(DataController::arbiter_pool(), (int) config('finobe.arbiter.port'));
            $constructedJob = $arbiter->ConstructJob(
                RobloxUtilities::GenerateGUID(),
                '
                    local assetid, asseturl, url, fileExtension, x, y = ...

                    print("Render Head " .. assetid)

                    pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)
                    game:GetService("ThumbnailGenerator").GraphicsMode = 4
                    game:GetService("ScriptContext").ScriptsDisabled = true
                    local head = Instance.new("Part")
                    head.Name = "Head"
                    head.Size = Vector3.new(2, 1, 1)
                    head.BrickColor = BrickColor.new("Medium stone grey")
                    head.Anchored = true
                    head.Parent = workspace
                    local mesh = game:GetObjects(asseturl)[1]
                    mesh.Parent = head
                    t = game:GetService("ThumbnailGenerator")
                    return t:Click(fileExtension, x, y, true, true)
                ',
                60,
                0,
                2,
                "ScriptExecution",
                [$id, "https://www.finobe.wtf/asset/?id={$id}", "https://www.finobe.wtf", "PNG", 768, 768]
            );

            $jobEx = $arbiter->OpenJobEx($constructedJob);
            $filename = uniqid() . ".png";

            if(empty(base64_decode($jobEx))) {
                throw new \Exception('There was an error while rendering. Please try again later.');
            }

            file_put_contents(config('finobe.cdn_path') . "/thumbnails/{$filename}", base64_decode($jobEx));

            $instance = new self();
            $hatData = self::getAssetData($id);
            $additional = json_decode($hatData->additional);
            $additional->media->thumbnail = "https://cdn.finobe.wtf/thumbnails/{$filename}";
            $additional = json_encode($additional);
            $instance->db->table('assets')
                ->where('id', $id)
                ->update([
                    'additional' => $additional
                ]);

            return "https://cdn.finobe.wtf/thumbnails/{$filename}";
        }
        return "";
    }

    /*
        renderPackage
         - a package (bundle) has no file of its own: show a character wearing everything
           it contains, so the catalog thumbnail matches what buying it gives you
    */
    public static function renderPackage(int $id) : string
    {
        if (!self::isAssetExist($id)) {
            return "";
        }

        $assetIds = self::getPackageAssets($id);
        if (!$assetIds) {
            return "";
        }

        $arbiter = new RobloxArbiterUtilities(DataController::arbiter_pool(), (int) config('finobe.arbiter.port'));
        $constructedJob = $arbiter->ConstructJob(
            RobloxUtilities::GenerateGUID(),
            '
                local assetids, url, fileExtension, x, y = ...

                print("Render Package " .. assetids)

                pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)
                game:GetService("ThumbnailGenerator").GraphicsMode = 4
                game:GetService("ScriptContext").ScriptsDisabled = true
                local player = game:GetService("Players"):CreateLocalPlayer(0)
                player:LoadCharacter(false)
                local character = player.Character
                for assetid in string.gmatch(assetids, "%d+") do
                    local ok, asset = pcall(function()
                        return game:GetObjects("https://www.finobe.wtf/asset/?id=" .. assetid)[1]
                    end)
                    if ok and asset then
                        local class = asset.ClassName
                        local head = character:FindFirstChild("Head")
                        if (class == "SpecialMesh" or class == "FileMesh" or class == "Decal" or class == "Texture") and head then
                            asset.Parent = head
                        elseif class == "Shirt" or class == "Pants" or class == "ShirtGraphic" or class == "Hat" or class == "Accessory" then
                            asset.Parent = character
                        elseif class == "Tool" then
                            asset.Parent = character
                            pcall(function() character.Humanoid:EquipTool(asset) end)
                        else
                            asset.Parent = character
                        end
                    end
                end
                t = game:GetService("ThumbnailGenerator")
                return t:Click(fileExtension, x, y, true)
            ',
            60,
            0,
            2,
            "ScriptExecution",
            [implode(",", $assetIds), "https://www.finobe.wtf", "PNG", 768, 768]
        );

        $jobEx = $arbiter->OpenJobEx($constructedJob);
        $filename = uniqid() . ".png";

        if(empty(base64_decode($jobEx))) {
            throw new \Exception('There was an error while rendering. Please try again later.');
        }

        file_put_contents(config('finobe.cdn_path') . "/thumbnails/{$filename}", base64_decode($jobEx));

        $instance = new self();
        $packageData = self::getAssetData($id);
        $additional = json_decode($packageData->additional);
        $additional->media->thumbnail = "https://cdn.finobe.wtf/thumbnails/{$filename}";
        $additional = json_encode($additional);
        $instance->db->table('assets')
            ->where('id', $id)
            ->update([
                'additional' => $additional
            ]);

        return "https://cdn.finobe.wtf/thumbnails/{$filename}";
    }

    /*
        createPackage
         - a package is a catalog item that hands out the assets it lists when bought
    */
    public static function createPackage(string $name, array $assetIds, int $author, string $description, int $price, bool $onSale = false, bool $isLimited = false): int
    {
        $assetId = self::createAsset($name, 32, $author, '', $description, 'n', [
            'price' => $price,
            'onSale' => $onSale,
            'isLimited' => $isLimited,
            'historicPrice' => [],
            'media' => ['thumbnail' => ''],
        ]);

        self::setPackageAssets($assetId, $assetIds);
        self::grantToCreator($assetId, $author);

        // a failed render (arbiter down) shouldn't undo the upload
        try {
            self::renderPackage($assetId);
        } catch (\Throwable $e) {
            report($e);
        }

        return $assetId;
    }

    /** @return int[] the asset ids a package contains */
    public static function getPackageAssets(int $assetId): array
    {
        $instance = new self();
        return $instance->db->table('asset_package')
            ->where('package_asset_id', $assetId)
            ->pluck('asset_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /** replace the contents of a package (only items that actually exist) */
    public static function setPackageAssets(int $assetId, array $assetIds): void
    {
        $instance = new self();
        $instance->db->table('asset_package')->where('package_asset_id', $assetId)->delete();

        $valid = array_filter(
            array_unique(array_map('intval', $assetIds)),
            fn($id) => $id > 0 && $instance->db->table('assets')->where('id', $id)->exists()
        );

        foreach ($valid as $id) {
            $instance->db->table('asset_package')->insert([
                'package_asset_id' => $assetId,
                'asset_id' => $id,
            ]);
        }
    }
}
