<?php

namespace App\Services;

use App\Models\User;
use App\Http\Controllers\RobloxUtilities;
use App\Http\Controllers\RobloxArbiterUtilities;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

/*
    AvatarRenderer
     - asks the arbiter (RCC) to render a user's character and makes it their avatar (users.pfp)
*/
class AvatarRenderer
{
    const DEFAULT_PFP = 'avatar/682b8bd6cbc48.png';

    /** Render poses picked on the Character page (avatar[0]['pose']). */
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

        return isset(self::POSES[$pose]) ? $pose : 'default';
    }

    const SCRIPT = '
        local characterUrl, url, fileExtension, x, y, pose, outfitBodyColorIds = ...

        settings()["Task Scheduler"].ThreadPoolConfig = Enum.ThreadPoolConfig.PerCore4;
        game:GetService("ContentProvider"):SetThreadPool(16)
        game:GetService("ScriptContext").ScriptsDisabled = true
        pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)

        local player = game:GetService("Players"):CreateLocalPlayer(0)
        player.CharacterAppearance = characterUrl
        player:LoadCharacter(false)

        -- Saved outfit thumbnails must use the six body colours captured when the
        -- outfit was saved. The normal avatar renderer passes no seventh argument.
        -- Applying them directly also covers old RCC builds which fail to apply
        -- a separate BodyColors appearance URL after loading shirts and hats.
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
                    -- joints from the "Retro Character Posing" model (only the C0/C1 values, not the model)
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
                    -- rebuilt from the part positions of the "dead" ragdoll model; the whole body is turned so
                    -- the 2016 thumbnail camera sees it from the same angle as the model\'s ThumbnailCamera
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
            end)

            -- Gear assets arrive as Tools in the Backpack, so they never appear on the
            -- avatar until equipped. Equip the first gear so it is held in the render.
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
                if child:IsA("Tool") and pose ~= "retro" and pose ~= "peter" and pose ~= "overlord" then
                    player.Character.Torso["Right Shoulder"].CurrentAngle = math.rad(90)
                    break
                end
            end
        end

        game:GetService("ThumbnailGenerator").GraphicsMode = 4
        return game:GetService("ThumbnailGenerator"):Click(fileExtension, x, y, true)
    ';

    /**
     * True when the user has no real render yet: still on the default picture,
     * no picture at all, or the file is missing from the CDN.
     */
    public static function needsRender(?string $pfp): bool
    {
        if (!$pfp || $pfp === self::DEFAULT_PFP) {
            return true;
        }

        return !File::exists(config('finobe.cdn_path') . '/avatar/' . basename($pfp));
    }

    /**
     * Queues a background render for a user whose avatar isn't rendered.
     * At most one attempt per user every 10 minutes, so a down arbiter isn't hammered.
     */
    public static function renderIfMissing(int $userId, ?string $pfp): void
    {
        if (self::needsRender($pfp) && Cache::add("avatar_autorender_{$userId}", true, 600)) {
            \App\Jobs\RenderAvatar::dispatchAfterResponse($userId);
        }
    }

    /**
     * Renders the user's current outfit, stores it on the CDN and points users.pfp at it.
     * Returns the new pfp path (e.g. "avatar/abc123.png").
     */
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
            ["{$site}/asset/CharacterFetch.ashx?userId={$user->id}", $site, "PNG", 768, 768, self::poseFor($user)]
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

        // delete the previous render, never the shared default
        $old = $user->fresh()->pfp;
        if ($old && $old !== self::DEFAULT_PFP && str_starts_with($old, 'avatar/')) {
            File::delete(config('finobe.cdn_path') . '/avatar/' . basename($old));
        }

        User::where('id', $user->id)->update(['pfp' => "avatar/{$filename}"]);

        // friends' pages read the avatar through this cache; drop it so the new render shows
        Cache::forget('pfp_' . $user->id);

        return "avatar/{$filename}";
    }
}
