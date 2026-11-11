<?php
namespace Tests\Feature;

use App\Support\AvatarImage;
use Tests\TestCase;

class AvatarPlaceholderTest extends TestCase
{
    public function test_unrendered_and_legacy_defaults_use_the_bundled_finobe_image(): void
    {
        foreach ([null, '', ' ', AvatarImage::LEGACY_DEFAULT, 'avatar/', '/s/img/default-avatar.png'] as $path) {
            $this->assertSame('/s/img/default-avatar.png', AvatarImage::url($path));
        }
        $this->assertSame('image/png', mime_content_type(public_path(AvatarImage::DEFAULT_URL)));
    }

    public function test_rendered_avatars_keep_their_cdn_image(): void
    {
        config(['admin.cdn_url'=>'https://cdn.example.test/']);
        $this->assertSame('https://cdn.example.test/avatar/rendered-123.png', AvatarImage::url('avatar/rendered-123.png'));
    }

    public function test_both_user_list_themes_show_the_default_and_preserve_rendered_avatars(): void
    {
        config(['admin.cdn_url'=>'https://cdn.example.test']);
        $users=[['id'=>1,'username'=>'Unrendered','pfp'=>AvatarImage::LEGACY_DEFAULT],['id'=>2,'username'=>'Rendered','pfp'=>'avatar/rendered-123.png']];
        foreach (['v1','v2'] as $theme) {
            $html=view($theme.'/Users',['users'=>$users,'data'=>['users'=>$users,'user'=>[],'siteusername'=>false]])->render();
            $this->assertStringContainsString('src="/s/img/default-avatar.png"', $html);
            $this->assertStringContainsString('src="https://cdn.example.test/avatar/rendered-123.png"', $html);
            $this->assertStringNotContainsString('cdn.example.test/'.AvatarImage::LEGACY_DEFAULT, $html);
        }
    }
}
