<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RobloxUtilities extends Controller
{
    protected $db;

    public function __construct() {
        $this->db = DB::connection('finobe');
    }

    public static function GenerateClientTicket(int $userId, string $username, string $charApp, string $jobId): string
    {
        $currentDate = date("n\/j\/Y\ g\:i\:s\ A");
        $ticket = "{$userId}\n{$jobId}\n{$currentDate}";
        $signedTicket = SecurityNotary::CreateSignature($ticket);

        $ticket2 = "{$userId}\n{$username}\n{$charApp}\n{$jobId}\n{$currentDate}";
        $signedTicket2 = SecurityNotary::CreateSignature($ticket2);

        return "{$currentDate};{$signedTicket2};{$signedTicket}";
    }

    public static function GenerateGUID(): string
    {
        if (function_exists('com_create_guid') === true)
        {
            return trim(com_create_guid(), '{}');
        }

        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }

    public static function IsFinobeCloudAuthorized()
    {
        $requestKey = request()->header('AccessKey') ?? request()->query('accesskey', "");
        $exceptKey = config('finobe.cloud_key');
        return is_string($exceptKey) && $exceptKey !== '' && hash_equals($exceptKey, (string)$requestKey);
    }

    public static function ConstructPlaceLauncher(string $jobId, int $status, string $joinScriptUrl, string $authenticationUrl, string $authenticationToken, string $message)
    {
        return json_encode(
            [
                "jobId" => $jobId,
                "status" => $status,
                "joinScriptUrl" => $joinScriptUrl,
                "authenticationUrl" => $authenticationUrl,
                "authenticationTicket" => $authenticationToken,
                "message" => $message
            ],
            JSON_UNESCAPED_SLASHES
        );
    }

    public static function ConstructJoinScript(object $userInformation, object $placeInformation, object $serverInformation, object $additionalInfo)
    {
        $format = \App\Services\ClientVersions::joinFormat((string) $additionalInfo->version);
        switch ($format === 'json' ? '2016' : (string) $additionalInfo->version) {
            case "2016":
                $domain = config('finobe.domain');
                $charApp = "https://www.{$domain}/Asset/CharacterFetch.ashx?userId={$userInformation->id}&placeId={$placeInformation->id}";
                $created = is_numeric($userInformation->created ?? null) ? (int) $userInformation->created : (int) strtotime((string) ($userInformation->created ?? ""));

                return json_encode([
                    "ClientPort" => 0,
                    "MachineAddress" => $serverInformation->ip,
                    "ServerPort" => (int) $serverInformation->port,
                    "PingUrl" => "",
                    "PingInterval" => 0,
                    "UserName" => $userInformation->username,
                    "SeleniumTestMode" => false,
                    "UserId" => (int) $userInformation->id,
                    "SuperSafeChat" => false,
                    "CharacterAppearance" => $charApp,
                    "ClientTicket" => RobloxUtilities::GenerateClientTicket((int) $userInformation->id, $userInformation->username, $charApp, $serverInformation->jobId),
                    "GameId" => $serverInformation->jobId,
                    "PlaceId" => (int) $placeInformation->id,
                    "MeasurementUrl" => "",
                    "WaitingForCharacterGuid" => RobloxUtilities::GenerateGUID(),
                    "BaseUrl" => "https://www.{$domain}/",
                    "ChatStyle" => "ClassicAndBubble",
                    "VendorId" => "0",
                    "ScreenShotInfo" => "",
                    "VideoInfo" => "",
                    "CreatorId" => (int) $placeInformation->author,
                    "CreatorTypeEnum" => "User",
                    "MembershipType" => \App\Services\PlayerBadges::membershipType((int) $userInformation->id),
                    "AccountAge" => $created > 0 ? (int) floor((time() - $created) / 86400) : 0,
                    "CookieStoreFirstTimePlayKey" => "rbx_evt_ftp",
                    "CookieStoreFiveMinutePlayKey" => "rbx_evt_fmp",
                    "CookieStoreEnabled" => true,
                    "IsRobloxPlace" => (int) $placeInformation->author === 1,
                    "GenerateTeleportJoin" => false,
                    "IsUnknownOrUnder13" => false,
                    "SessionId" => RobloxUtilities::GenerateGUID() . "|{$serverInformation->jobId}|0|127.0.0.1|8|" . date('dmy') . "|0|null|null",
                    "DataCenterId" => 0,
                    "UniverseId" => (int) $placeInformation->id,
                    "BrowserTrackerId" => 0,
                    "UsePortraitMode" => false,
                    "FollowUserId" => 0,
                    "characterAppearanceId" => (int) $userInformation->id
                ], JSON_UNESCAPED_SLASHES);
            case "2012":

                $joinscript = file_get_contents(storage_path("rbx/files/scripts/join2012.lua"));

                if (!file_exists(storage_path("rbx/files/scripts/join2012.lua"))) {
                    return "I'm so tired bruhh";
                }

                $joinscript = str_replace("{placeId}", $placeInformation->id, $joinscript);
                $joinscript = str_replace("{jobId}", $serverInformation->jobId, $joinscript);

                $serverAddress = 'localhost';
                $joinscript = str_replace("{serverip}", $serverAddress, $joinscript);
                $joinscript = str_replace("{creatorId}", $placeInformation->author, $joinscript);
                $joinscript = str_replace("{chatstyle}", "ClassicAndBubble", $joinscript);
                $joinscript = str_replace("{serverport}", $serverInformation->port, $joinscript);
                $joinscript = str_replace("{userid}", $userInformation->id, $joinscript);
                $joinscript = str_replace("{username}", $userInformation->username, $joinscript);

                $charAppUrl = "http://assetgame." . config('finobe.domain') . "/Asset/CharacterFetch.ashx?userId={$userInformation->id}&placeId={$placeInformation->id}";
                $joinscript = str_replace("{charapp}", $charAppUrl, $joinscript);
                return $joinscript;
            default:
                return "Not implemented yet!";
        }
    }

    public static function RequestGame(int $placeId, object $user): object
    {
        $instance = new self();

        if (!$instance->db->table('assets')->where('id', $placeId)->exists()) {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "Invalid placeId"
            ];
        }
        $asset = $instance->db->table('assets')
            ->where('id', $placeId)
            ->first();

        $additional = json_decode($asset->additional);
        if ($additional->allowplaying === false && (int) $asset->author !== (int) $user->id)
        {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "You don't have permission to join this game"
            ];
        }

        if ($reason = \App\Services\PlaceAccess::denyReason($asset, $user))
        {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => $reason
            ];
        }

        if ($asset->asset_type == 9) {
            if (!$instance->db->table('servers')->where('placeid', $placeId)->whereIn('status', [1, 2])->exists()) {
                $port = self::FreeGameServerPort();
                $ip = config('finobe.gameserver.host');
                $aaaaaaaaaaa = "[]";
                $godIhatepdo = 1;
                $jobId = self::startGame($placeId, $asset->author, $port);

                $instance->db->table('servers')->insert([
                    'ip' => $ip,
                    'port' => $port,
                    'placeid' => $placeId,
                    'players' => $aaaaaaaaaaa,
                    'jobId' => $jobId,
                    'status' => $godIhatepdo
                ]);

                return (object)[
                    "success" => true,
                    "data" => [
                        "status" => 0,
                        "jobId" => $jobId,
                    ],
                    "publicMessage" => "Requested a new server"
                ];
            }

            $server = $instance->db->table('servers')
                ->where('placeid', $placeId)
                ->whereIn('status', [1, 2])
                ->first();

            if (count(json_decode($server->players)) >= $additional->maxplayers) {
                $port = self::FreeGameServerPort();
                $ip = config('finobe.gameserver.host');
                $aaaaaaaaaaa = "[]";
                $godIhatepdo = 1;
                $jobId = self::startGame($placeId, $asset->author, $port);

                $instance->db->table('servers')->insert([
                    'ip' => $ip,
                    'port' => $port,
                    'placeid' => $placeId,
                    'players' => $aaaaaaaaaaa,
                    'jobId' => $jobId,
                    'status' => $godIhatepdo
                ]);

                return (object)[
                    "success" => true,
                    "data" => [
                        "status" => 0,
                        "jobId" => $jobId,
                    ],
                    "publicMessage" => "Requested a new server"
                ];
            }

            return (object)[
                "success" => true,
                "data" => [
                    "status" => $server->status,
                    "jobId" => $server->jobId,
                ]
            ];
        }

        return (object)[
            "success" => false,
            "data" => [],
            "publicMessage" => "Invalid placeId"
        ];
    }

    private static function FreeGameServerPort(): int
    {
        $min = (int) config('finobe.gameserver.port_min');
        $max = (int) config('finobe.gameserver.port_max');

        $used = (new self())->db->table('servers')
            ->whereIn('status', [1, 2])
            ->pluck('port')
            ->map(fn($port) => (int) $port)
            ->all();

        $free = array_values(array_diff(range($min, $max), $used));

        if (empty($free)) {
            throw new \RuntimeException('No free game server ports in the configured range');
        }

        return $free[random_int(0, count($free) - 1)];
    }

    private static function startGame(int $placeId, int $creatorId, int $port)
    {
        $arbiter = new RobloxArbiterUtilities(config('finobe.arbiter.host'), (int) config('finobe.arbiter.port'));
        $jobId = self::GenerateGUID();
        $gamescript = file_get_contents(storage_path("rbx/files/scripts/gameserver2016.lua"));
        $constructJob = $arbiter->constructJob(
            $jobId,
            "{$gamescript}",
            90,
            0,
            1,
            "ScriptExecution",
            [
                $placeId,
                $port,
                "https://www." . config('finobe.domain'),
                $creatorId,
                (string) config('finobe.cloud_key')
            ]
        );

        $arbiter->OpenJobEx($constructJob);

        return $jobId;
    }

    public static function RequestGameJob(int $placeId, string $jobId, object $user,)
    {
        $instance = new self();

        if (!$instance->db->table('assets')->where('id', $placeId)->exists()) {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "Invalid placeId"
            ];
        }
        $asset = $instance->db->table('assets')
            ->where('id', $placeId)
            ->first();

        $additional = json_decode($asset->additional);
        if ($additional->allowplaying === false && (int) $asset->author !== (int) $user->id)
        {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "You don't have permission to join this game"
            ];
        }

        if ($reason = \App\Services\PlaceAccess::denyReason($asset, $user))
        {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => $reason
            ];
        }
        if ($asset->asset_type == 9) {
            if ($instance->db->table('servers')->where('placeid', $placeId)->where('jobId', $jobId)->where('status', 2)->exists()) {
                return (object)[
                    "success" => true,
                    "data" => [
                        "status" => 2,
                        "jobId" => $jobId,
                    ],
                    "publicMessage" => "Joining a server"
                ];
            }
        } else {
            return (object)[
                "success" => false,
                "data" => [],
                "publicMessage" => "Job doesnt exists"
            ];
        }

        return (object)[
            "success" => false,
            "data" => [],
            "publicMessage" => "Unknown error"
        ];
    }
}
