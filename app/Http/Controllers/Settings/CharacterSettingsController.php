<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AnimationPacks;
use App\Services\AvatarRenderer;
use Illuminate\Http\Request;

class CharacterSettingsController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return $this->page('Settings/Character', [
            'poses' => AvatarRenderer::POSES,
            'pose' => AvatarRenderer::poseFor($user),
            'animationPacks' => AnimationPacks::PACKS,
            'animationPack' => AnimationPacks::forUser($user),
        ], 'Character Settings');
    }
}
