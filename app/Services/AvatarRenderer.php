<?php

namespace App\Services;

use App\Models\User;
use App\Http\Controllers\RobloxUtilities;
use App\Http\Controllers\RobloxArbiterUtilities;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class AvatarRenderer
{
    const DEFAULT_PFP = 'avatar/682b8bd6cbc48.png';

    const POSES = [
        'default' => 'Standing',
        'walking' => 'Walking',
        'sitting' => 'Sitting',
        'overlord' => 'Overlord',
        'retro' => 'is this dude serious',
        'peter' => 'peter grifin',
    ];

    public static function poseFor(User $user): string
    {
        $avatar = is_array($user->avatar) ? $user->avatar : [];
        $pose = (string) ($avatar[0]['pose'] ?? 'default');

        return self::poseExists($pose) ? $pose : 'default';
    }

    public static function customPoses(): array
    {
        return Cache::rememberForever('custom_poses', function () {
            try {
                return \Illuminate\Support\Facades\DB::connection('finobe')->table('custom_poses')->orderBy('id')->get(['id', 'name', 'joints'])
                    ->mapWithKeys(fn ($row) => ['custom' . $row->id => ['name' => (string) $row->name, 'joints' => (string) $row->joints]])
                    ->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    public static function forgetCustomPoses(): void
    {
        Cache::forget('custom_poses');
    }

    public static function poses(): array
    {
        $poses = self::POSES;
        foreach (self::customPoses() as $key => $pose) {
            $poses[$key] = $pose['name'];
        }

        return $poses;
    }

    public static function poseExists(string $pose): bool
    {
        return isset(self::POSES[$pose]) || isset(self::customPoses()[$pose]);
    }

    public static function poseJoints(string $pose): string
    {
        return self::customPoses()[$pose]['joints'] ?? '';
    }

    const SCRIPT = '
        local characterUrl, url, fileExtension, x, y, pose, outfitBodyColorIds, poseJoints = ...

        settings()["Task Scheduler"].ThreadPoolConfig = Enum.ThreadPoolConfig.PerCore4;
        game:GetService("ContentProvider"):SetThreadPool(16)
        game:GetService("ScriptContext").ScriptsDisabled = true
        pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)

        local player = game:GetService("Players"):CreateLocalPlayer(0)
        player.CharacterAppearance = characterUrl
        player:LoadCharacter(false)

        if player.Character and type(outfitBodyColorIds) == "string" then
            local ids = {}
            for number in string.gmatch(outfitBodyColorIds, "%d+") do
                ids[#ids + 1] = tonumber(number)
            end
            if #ids == 6 then
                local colourValues = {
                    BrickColor.new(ids[1]), BrickColor.new(ids[2]),
                    BrickColor.new(ids[3]), BrickColor.new(ids[4]),
                    BrickColor.new(ids[5]), BrickColor.new(ids[6])
                }

                local bodyColors = player.Character:FindFirstChild("Body Colors")
                if not bodyColors then
                    for _, object in pairs(player.Character:GetChildren()) do
                        if object:IsA("BodyColors") then
                            bodyColors = object
                            break
                        end
                    end
                end
                if not bodyColors then
                    bodyColors = Instance.new("BodyColors")
                    bodyColors.Name = "Body Colors"
                    bodyColors.Parent = player.Character
                end

                bodyColors.HeadColor = colourValues[1]
                bodyColors.TorsoColor = colourValues[2]
                bodyColors.LeftArmColor = colourValues[3]
                bodyColors.RightArmColor = colourValues[4]
                bodyColors.LeftLegColor = colourValues[5]
                bodyColors.RightLegColor = colourValues[6]

                local parts = {
                    ["Head"] = colourValues[1],
                    ["Torso"] = colourValues[2],
                    ["Left Arm"] = colourValues[3],
                    ["Right Arm"] = colourValues[4],
                    ["Left Leg"] = colourValues[5],
                    ["Right Leg"] = colourValues[6]
                }
                for name, colour in pairs(parts) do
                    local part = player.Character:FindFirstChild(name)
                    if part and part:IsA("BasePart") then
                        part.BrickColor = colour
                    end
                end
            end
        end

        if player.Character then
            pcall(function()
                local torso = player.Character.Torso
                if pose == "overlord" then
                    torso["Left Shoulder"].C0 = CFrame.new(-1, 0.5, 0, -4.37113883e-08, 0, -1, 0, 0.99999994, 0, 1, 0, -4.37113883e-08)
                    torso["Left Shoulder"].C1 = CFrame.new(0.49999997, 0.49999997, 4.47034836e-08, 0.163175777, -0.229498923, -0.959533036, -0.33284384, 0.90274477, -0.272519022, 0.928756475, 0.363843203, 0.0709187835)
                    torso["Right Shoulder"].C0 = CFrame.new(1, 0.5, 0, -4.37113883e-08, 0, 1, -0, 0.99999994, 0, -1, 0, -4.37113883e-08)
                    torso["Right Shoulder"].C1 = CFrame.new(-0.5, 0.5, 0, 0.163175479, 0.229498848, 0.959533155, 0.332843512, 0.902745068, -0.272518843, -0.928756654, 0.363842756, 0.0709186569)
                elseif pose == "retro" then
                    local joints = {
                        ["RootJoint"] = { CFrame.new(0, 0, 0, -1, 0, 0, 0, 0, 1, 0, 1, 0), CFrame.new(0, 0, 0, -0.965925813, -0.258819044, 0, 0, 0, 1, -0.258819044, 0.965925813, 0) },
                        ["Neck"] = { CFrame.new(0, 1, 0, -0.965925813, -0.258819044, 0, 0, 0, 1, -0.258819044, 0.965925813, 0), CFrame.new(0, -0.500181615, -0.0259598941, -1, 0, 0, 0, -0.0909212455, 0.995858073, 0, 0.995858073, 0.0909212455) },
                        ["Right Shoulder"] = { CFrame.new(1, 0.5, 0, 0, 0, 1, 0, 1, 0, -1, 0, 0), CFrame.new(-0.499844164, 0.603283346, 0.0946160182, 0.519456625, -0.709804773, 0.475757807, -0.313241839, 0.359834641, 0.878864467, -0.795014024, -0.605558693, -0.035421472) },
                        ["Left Shoulder"] = { CFrame.new(-1, 0.5, 0, 0, 0, -1, 0, 1, 0, 1, 0, 0), CFrame.new(0.549822807, 0.725586295, 0.36120984, 0.531002045, 0.666525722, -0.523244858, 0.29324466, 0.434795856, 0.851447642, 0.795014024, -0.605558753, 0.0354217514) },
                        ["Right Hip"] = { CFrame.new(1, -1, 0, 0, 0, 1, 0, 1, 0, -1, 0, 0), CFrame.new(0.438973665, 1, -0.0388257056, -0.258819014, 0, 0.965925694, 0, 1, 0, -0.965925694, 0, -0.258819014) },
                        ["Left Hip"] = { CFrame.new(-1, -1, 0, 0, 0, -1, 0, 1, 0, 1, 0, 0), CFrame.new(-0.35476619, 1, 0.0116389096, -0.258819044, 0, -0.965925813, 0, 1, 0, 0.965925813, 0, -0.258819044) },
                    }
                    local root = player.Character:FindFirstChild("HumanoidRootPart")
                    for name, cf in pairs(joints) do
                        local joint = torso:FindFirstChild(name) or (root and root:FindFirstChild(name))
                        if joint then
                            joint.C0 = cf[1]
                            joint.C1 = cf[2]
                        end
                    end
                elseif pose == "peter" then
                    local joints = {
                        ["RootJoint"] = { CFrame.new(0, 0, 0, -1, 0, 0, 0, 0, 1, 0, 1, 0), CFrame.new(0, 0, 0, 0.346894313, -0.485215706, -0.802639245, 0.725988373, 0.680724649, -0.0977488384, 0.59380559, -0.548798243, 0.58840047) },
                        ["Neck"] = { CFrame.new(0, 1, 0, -1, 0, 0, 0, 0, 1, 0, 1, 0), CFrame.new(4.020735e-05, -0.499989733, 2.8695507e-05, -0.770034022, 0.591979798, -0.237922, -0.174891193, 0.162775468, 0.971039, 0.613563907, 0.789343857, -0.0218109) },
                        ["Right Shoulder"] = { CFrame.new(1, 0.5, 0, 0, 0, 1, 0, 1, 0, -1, 0, 0), CFrame.new(0.503889555, 0.669370932, -0.844736625, 0.399422189, 0.0455668, 0.915633551, 0.578480136, 0.762297, -0.290283336, -0.711212519, 0.645622, 0.278119137) },
                        ["Left Shoulder"] = { CFrame.new(-1, 0.5, 0, 0, 0, -1, 0, 1, 0, 1, 0, 0), CFrame.new(0.351814727, 0.478529428, -0.0163721115, -0.699657707, 0.472547, -0.535890269, -0.390041646, 0.375802, 0.840618884, 0.598620536, 0.797165, -0.0786198768) },
                        ["Right Hip"] = { CFrame.new(1, -1, 0, 0, 0, 1, 0, 1, 0, -1, 0, 0), CFrame.new(0.325164817, 1, 0.0996476282, 0.271099713, 0, 0.962551112, 0, 1, 0, -0.962551112, 0, 0.271099713) },
                        ["Left Hip"] = { CFrame.new(-1, -1, 0, 0, 0, -1, 0, 1, 0, 1, 0, 0), CFrame.new(0.213162716, 1.41776845, 0.023569402, -0.850370536, 0.373962, -0.370163538, -0.296162836, 0.241308, 0.924152623, 0.434921754, 0.895501, -0.0944475995) },
                    }
                    local root = player.Character:FindFirstChild("HumanoidRootPart")
                    for name, cf in pairs(joints) do
                        local joint = torso:FindFirstChild(name) or (root and root:FindFirstChild(name))
                        if joint then
                            joint.C0 = cf[1]
                            joint.C1 = cf[2]
                        end
                    end
                elseif pose == "walking" then
                    torso["Left Hip"].CurrentAngle = 0.2
                    torso["Left Shoulder"].CurrentAngle = -0.5
                    torso["Right Hip"].CurrentAngle = 0.5
                    torso["Right Shoulder"].CurrentAngle = -0.5
                elseif pose == "sitting" then
                    torso["Left Hip"].CurrentAngle = -1.57
                    torso["Left Shoulder"].CurrentAngle = -1.57
                    torso["Right Hip"].CurrentAngle = 1.57
                    torso["Right Shoulder"].CurrentAngle = 1.57
                end

                if type(poseJoints) == "string" and poseJoints ~= "" then
                    local root = player.Character:FindFirstChild("HumanoidRootPart")
                    for name, numbers in string.gmatch(poseJoints, "([^|;]+)|([^;]+)") do
                        local v = {}
                        for number in string.gmatch(numbers, "%S+") do v[#v + 1] = tonumber(number) or 0 end
                        local joint = torso:FindFirstChild(name) or (root and root:FindFirstChild(name))
                        if joint and #v == 24 then
                            joint.C0 = CFrame.new(v[1], v[2], v[3], v[4], v[5], v[6], v[7], v[8], v[9], v[10], v[11], v[12])
                            joint.C1 = CFrame.new(v[13], v[14], v[15], v[16], v[17], v[18], v[19], v[20], v[21], v[22], v[23], v[24])
                        end
                    end
                end
            end)

            local backpack = player.Backpack or player:FindFirstChild("Backpack")
            if backpack then
                for _, gear in pairs(backpack:GetChildren()) do
                    if gear:IsA("Tool") then
                        pcall(function() player.Character.Humanoid:EquipTool(gear) end)
                        break
                    end
                end
            end

            for _, child in pairs(player.Character:GetChildren()) do
                if child:IsA("Tool") and pose ~= "retro" and pose ~= "peter" and pose ~= "overlord" and (type(poseJoints) ~= "string" or poseJoints == "") then
                    player.Character.Torso["Right Shoulder"].CurrentAngle = math.rad(90)
                    break
                end
            end
        end

        pcall(function()
            local head = player.Character:FindFirstChild("Head")
            local mesh = head and head:FindFirstChild("Mesh")
            if mesh and mesh:IsA("SpecialMesh") and mesh.Offset.magnitude > 0.05 then
                local box = Instance.new("Part")
                box.Name = "MeshBounds"
                box.Anchored = true
                box.CanCollide = false
                box.Transparency = 1
                box.FormFactor = Enum.FormFactor.Custom
                box.Size = head.Size
                box.CFrame = head.CFrame * CFrame.new(mesh.Offset)
                box.Parent = player.Character
            end
        end)
        game:GetService("ThumbnailGenerator").GraphicsMode = 4
        return game:GetService("ThumbnailGenerator"):Click(fileExtension, x, y, true)
    ';

    public static function needsRender(?string $pfp): bool
    {
        if (!$pfp || $pfp === self::DEFAULT_PFP) {
            return true;
        }

        return !File::exists(config('finobe.cdn_path') . '/avatar/' . basename($pfp));
    }

    public static function renderIfMissing(int $userId, ?string $pfp): void
    {
        if (self::needsRender($pfp) && Cache::add("avatar_autorender_{$userId}", true, 600)) {
            \App\Jobs\RenderAvatar::dispatchAfterResponse($userId);
        }
    }

    public static function renderPosePreview(User $user, string $joints): string
    {
        $domain = config('finobe.domain');
        $site = "https://www.{$domain}";

        $arbiter = new RobloxArbiterUtilities(config('finobe.arbiter.host'), (int) config('finobe.arbiter.port'));
        $job = $arbiter->ConstructJob(
            RobloxUtilities::GenerateGUID(),
            self::SCRIPT,
            60,
            0,
            2,
            "ScriptExecution",
            ["{$site}/asset/CharacterFetch.ashx?userId={$user->id}", $site, "PNG", 352, 352, "custom", "", $joints]
        );

        $image = base64_decode((string) $arbiter->OpenJobEx($job), true);
        if (empty($image) || !str_starts_with($image, "\x89PNG")) {
            throw new \RuntimeException('The pose preview render returned no image.');
        }

        return $image;
    }

    public static function render(User $user): string
    {
        $domain = config('finobe.domain');
        $site = "https://www.{$domain}";

        $arbiter = new RobloxArbiterUtilities(config('finobe.arbiter.host'), (int) config('finobe.arbiter.port'));
        $job = $arbiter->ConstructJob(
            RobloxUtilities::GenerateGUID(),
            self::SCRIPT,
            60,
            0,
            2,
            "ScriptExecution",
            ["{$site}/asset/CharacterFetch.ashx?userId={$user->id}", $site, "PNG", 768, 768, self::poseFor($user), "", self::poseJoints(self::poseFor($user))]
        );

        $image = base64_decode((string) $arbiter->OpenJobEx($job), true);

        if (empty($image) || !str_starts_with($image, "\x89PNG")) {
            throw new \RuntimeException("Avatar render for user {$user->id} returned no image (is the arbiter running?)");
        }

        $filename = uniqid() . '.png';
        $directory = config('finobe.cdn_path') . '/avatar';

        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0775, true);
        }

        File::put("{$directory}/{$filename}", $image);

        $old = $user->fresh()->pfp;
        if ($old && $old !== self::DEFAULT_PFP && str_starts_with($old, 'avatar/')) {
            File::delete(config('finobe.cdn_path') . '/avatar/' . basename($old));
        }

        User::where('id', $user->id)->update(['pfp' => "avatar/{$filename}"]);

        Cache::forget('pfp_' . $user->id);

        return "avatar/{$filename}";
    }

    public static function model3dDirectory(): string
    {
        return storage_path('app/avatar3d');
    }

    public static function render3d(User $user): array
    {
        $pose = self::poseFor($user);
        $key = substr(hash('sha256', json_encode($user->avatar) . '|' . $pose . '|' . self::poseJoints($pose) . '|' . $user->pfp), 0, 24);
        $name = "{$user->id}-{$key}";
        $directory = self::model3dDirectory() . "/{$name}";

        if (File::exists("{$directory}/manifest.json")) {
            $manifest = json_decode(File::get("{$directory}/manifest.json"), true);
            if (is_array($manifest)) {
                return $manifest;
            }
        }

        $domain = config('finobe.domain');
        $site = "https://www.{$domain}";

        $arbiter = new RobloxArbiterUtilities(config('finobe.arbiter.host'), (int) config('finobe.arbiter.port'));
        $job = $arbiter->ConstructJob(
            RobloxUtilities::GenerateGUID(),
            self::SCRIPT,
            90,
            0,
            2,
            "ScriptExecution",
            ["{$site}/asset/CharacterFetch.ashx?userId={$user->id}", $site, "OBJ", 768, 768, $pose, "", self::poseJoints($pose)]
        );

        $scene = json_decode((string) $arbiter->OpenJobEx($job), true);
        if (!is_array($scene) || !isset($scene['files']['scene.obj']['content'], $scene['files']['scene.mtl']['content'])) {
            throw new \RuntimeException("3D render for user {$user->id} returned no scene (is the arbiter running?)");
        }

        File::ensureDirectoryExists($directory, 0775);
        foreach ($scene['files'] as $file => $entry) {
            if (!is_string($file) || !preg_match('/^[A-Za-z0-9_.-]{1,64}\.(obj|mtl|png)$/', $file) || !isset($entry['content'])) {
                continue;
            }
            $content = base64_decode((string) $entry['content'], true);
            if ($content === false) {
                continue;
            }
            if ($file === 'scene.mtl') {
                $content = preg_replace('/^(map_d|Material).*$/m', '', $content);
            }
            File::put("{$directory}/{$file}", $content);
        }

        $vec = fn($v) => ['x' => (float) ($v['x'] ?? 0), 'y' => (float) ($v['y'] ?? 0), 'z' => (float) ($v['z'] ?? 0)];
        $manifest = [
            'base' => "/app/avatar-3d/{$name}/",
            'obj' => 'scene.obj',
            'mtl' => 'scene.mtl',
            'camera' => ['position' => $vec($scene['camera']['position'] ?? []), 'direction' => $vec($scene['camera']['direction'] ?? [])],
            'aabb' => ['min' => $vec($scene['AABB']['min'] ?? []), 'max' => $vec($scene['AABB']['max'] ?? [])],
        ];
        File::put("{$directory}/manifest.json", json_encode($manifest));

        foreach (File::directories(self::model3dDirectory()) as $old) {
            if (str_starts_with(basename($old), "{$user->id}-") && basename($old) !== $name) {
                File::deleteDirectory($old);
            }
        }

        return $manifest;
    }
}
