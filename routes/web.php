<?php

use App\Http\Controllers\api;
use App\Http\Controllers\rbxAPIs;
use App\Http\Controllers\Admin\AssetApprovalController;
use App\Http\Controllers\Admin\ServerManagementController;
use App\Http\Controllers\Admin\WorkbenchController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\AdminController;
use App\Http\Controllers\Web\AdminToolsController;
use App\Http\Controllers\Web\ForumController;
use App\Http\Controllers\Web\UsersController;
use App\Http\Controllers\Web\GamesController;
use App\Http\Controllers\Web\LegalController;
use App\Http\Controllers\Web\VideoController;
use App\Http\Controllers\Web\TradesController;
use App\Http\Controllers\Web\AvatarController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Settings\AccountSettingsController;
use App\Http\Controllers\Settings\CharacterSettingsController;
use App\Http\Controllers\Settings\LanguageController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PlaceSlotsController;
use App\Http\Controllers\Settings\PrivacyController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\ThemeController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\FriendsController;
use App\Http\Controllers\Web\InvitesController;
use App\Http\Controllers\Web\ElectionController;
use App\Http\Controllers\Web\CurrencyController;
use App\Http\Controllers\Web\MessagesController;
use App\Http\Middleware\Moderation;
use App\Http\Middleware\RequireLinkToken;
use App\Http\Middleware\SerializeWrites;
use App\Support\Paths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$domain = config('finobe.domain');
$siteHost = parse_url(config('app.url'), PHP_URL_HOST);

Route::domain(parse_url(config('blog.url'), PHP_URL_HOST))->group(base_path('routes/blog.php'));
Route::domain($siteHost)->prefix('admin/blog')
    ->middleware(\App\Http\Middleware\BlogStaff::class)->group(base_path('routes/admin-blog.php'));

Route::middleware([Moderation::class, SerializeWrites::class])->group(function () use ($domain, $siteHost) {
    Route::domain($siteHost)->group(function () {
        Route::fallback(fn () => response()->view('v2/404', [
            'data' => ['embeds' => ['title' => '404 - Finobe', 'image' => config('app.url') . '/s/img/finnobe3logo.png']],
        ], 404));

        Route::get('/', [HomeController::class, 'index']);
        Route::post('/', [HomeController::class, 'reactivate'])->middleware('limit:10,1');
        Route::post('/logout', [LoginController::class, 'logout']);
        Route::post('/moderation/acknowledge', [WorkbenchController::class, 'acknowledge'])->withoutMiddleware([Moderation::class, SerializeWrites::class]);
        Route::match(['post', 'get'], '/api/locale', [LanguageController::class, 'update'])->middleware('limit:30,1');
        Route::get('/email/verify/{id}/{token}', [EmailVerificationController::class, 'verify'])->whereNumber('id');

        Route::middleware('guest')->group(function () {
            Route::get('/login', [LoginController::class, 'show']);
            Route::post('/login', [LoginController::class, 'store'])->middleware('limit:10,1');
            Route::get('/register', [RegisterController::class, 'landing']);
            Route::post('/register', [RegisterController::class, 'store'])->middleware('limit:5,1');
            Route::get('/register/form', [RegisterController::class, 'form']);
            Route::get('/2fa/validate', [TwoFactorController::class, 'show']);
            Route::post('/2fa/validate', [TwoFactorController::class, 'verify'])->middleware('limit:10,1');
            Route::get('/password/reset', [PasswordResetController::class, 'request']);
            Route::post('/password/email', [PasswordResetController::class, 'send'])->middleware('limit:5,1');
            Route::get('/password/verify/{id}/{token}', [PasswordResetController::class, 'show'])->whereNumber('id');
            Route::post('/password/verify/{id}/{token}', [PasswordResetController::class, 'reset'])->whereNumber('id')->middleware('limit:10,1');
        });

        Route::post('/verify/email', [EmailVerificationController::class, 'send'])->middleware(['auth', 'limit:3,10']);

        Route::get('/download', fn () => redirect('https://prod1-setup.' . config('finobe.domain') . '/FinobeLauncher.exe'));
        Route::get('/create', [HomeController::class, 'create']);
        Route::get('/users', [UsersController::class, 'index']);
        Route::get('/videos', [VideoController::class, 'index']);
        Route::get('/video/{id}', [VideoController::class, 'video']);
        Route::get('/video/data/{id}', [VideoController::class, 'video_data']);
        Route::get('/video/thumb/{id}', [VideoController::class, 'video_thumb']);
        Route::get('/invites', [InvitesController::class, 'index']);
        Route::match(['post', 'get'], '/invites/new', [InvitesController::class, 'new']);
        Route::match(['post', 'get'], '/election', [ElectionController::class, 'election']);
        Route::get('/transparency/bans', [LegalController::class, 'transparency_bans']);
        Route::get('/friends/incoming', [FriendsController::class, 'incoming']);

        Route::get('/trades', [TradesController::class, 'trades']);
        Route::match(['get', 'post'], '/trade/info/{id}', [TradesController::class, 'info'])->whereNumber('id')->middleware('limit:30,1');
        Route::match(['get', 'post'], '/trade/new/{userId}', [TradesController::class, 'create'])->whereNumber('userId')->middleware('limit:30,1');

        Route::get('/item/{id}', [CatalogController::class, 'item']);
        Route::match(['post', 'get'], '/item/{id}/settings', [CatalogController::class, 'settings']);
        Route::post('/item/{id}/delete', [CatalogController::class, 'delete'])->whereNumber('id');

        Route::get('/catalog', fn () => redirect('/catalog/hats'));
        Route::match(['post', 'get', 'options'], '/catalog/new', [CatalogController::class, 'new']);
        Route::get('/catalog/{section}', [CatalogController::class, 'index']);

        Route::get('/place/{id}', [GamesController::class, 'place']);
        Route::match(['post', 'get'], '/publish/place-edit', function (Request $request) {
            $placeId = (int) $request->query('placeid');
            abort_if($placeId < 1, 404);

            return app(GamesController::class)->place_settings($request, $placeId);
        });
        Route::match(['post', 'get'], '/games/new', [GamesController::class, 'place_new']);

        Route::prefix('legal')->group(function () {
            Route::get('/rules', [LegalController::class, 'rules']);
            Route::get('/terms', [LegalController::class, 'terms']);
            Route::get('/privacy', [LegalController::class, 'privacy']);
            Route::get('/welcome', [LegalController::class, 'welcome']);
            Route::get('/about-us', [LegalController::class, 'about_us']);
        });

        Route::prefix('app')->group(function () {
            Route::get('/places', [GamesController::class, 'index']);
            Route::get('/character', [AvatarController::class, 'index']);
            Route::get('/inbox', [MessagesController::class, 'inbox']);
            Route::get('/inbox/sent', [MessagesController::class, 'sent']);
            Route::get('/inbox/archive', [MessagesController::class, 'archive']);
            Route::match(['post', 'get'], '/inbox/message', [MessagesController::class, 'message']);
            Route::match(['post', 'get'], '/inbox/compose', [MessagesController::class, 'compose']);
            Route::middleware('auth')->group(function () {
                Route::get('/settings', [AccountSettingsController::class, 'show']);
                Route::post('/settings', [AccountSettingsController::class, 'update'])->middleware('limit:20,1');
                Route::get('/theme', [ThemeController::class, 'show']);
                Route::get('/avatar-3d', [AvatarController::class, 'model3d'])->middleware('limit:20,1');
                Route::get('/avatar-3d/{name}/{file}', [AvatarController::class, 'model3dFile']);
                Route::post('/theme', [ThemeController::class, 'update']);
                Route::get('/character-settings', [CharacterSettingsController::class, 'show']);
                Route::get('/password', [PasswordController::class, 'show']);
                Route::post('/password', [PasswordController::class, 'update'])->middleware('limit:10,1');
                Route::get('/security', [SecurityController::class, 'show']);
                Route::post('/security', [SecurityController::class, 'update'])->middleware('limit:20,1');
                Route::get('/security/backup-codes', [SecurityController::class, 'backupCodes']);
                Route::get('/privacy', [PrivacyController::class, 'show']);
                Route::post('/privacy', [PrivacyController::class, 'update']);
                Route::get('/language', [LanguageController::class, 'show']);
                Route::post('/language', [LanguageController::class, 'update']);
                Route::get('/games', [PlaceSlotsController::class, 'show']);
                Route::post('/games', [PlaceSlotsController::class, 'purchase'])->middleware('limit:10,1');
            });
            Route::match(['post', 'get'], '/forum/new/post', [ForumController::class, 'new_post']);
        });

        Route::prefix('user')->group(function () {
            Route::get('/gettoken', [UsersController::class, 'gettoken']);
            Route::get('/transaction-log', [CurrencyController::class, 'transactions']);
            Route::get('/{id}', [ProfileController::class, 'index']);
            Route::get('/{id}/friends', [FriendsController::class, 'list']);
            Route::get('/{id}/add', [FriendsController::class, 'add'])->middleware(RequireLinkToken::class);
            Route::get('/{id}/accept', [FriendsController::class, 'accept'])->middleware(RequireLinkToken::class);
            Route::get('/{id}/remove', [FriendsController::class, 'remove'])->middleware(RequireLinkToken::class);
        });

        Route::prefix('forum')->group(function () {
            Route::get('/', [ForumController::class, 'index']);
            Route::get('/home', [ForumController::class, 'index']);
            Route::get('/home/{section}', [ForumController::class, 'section']);
            Route::get('/subscribe', [ForumController::class, 'subscribe'])->middleware(RequireLinkToken::class);
            Route::get('/search', [ForumController::class, 'search']);
            Route::match(['post', 'get'], '/post', [ForumController::class, 'post']);
            Route::match(['post', 'get'], '/edit', [ForumController::class, 'edit_reply']);
            Route::match(['post', 'get'], '/new/reply', [ForumController::class, 'reply']);
            Route::post('/api/notifications/oneread', [api::class, 'markOne'])->middleware('limit:60,1');
            Route::post('/api/notifications/allread', [api::class, 'markAll'])->middleware('limit:20,1');
        });

        Route::prefix('api')->group(function () {
            Route::get('/mark', [api::class, 'mark']);
            Route::get('/places', [api::class, 'places']);
            Route::get('/inventory', [api::class, 'inventory']);
            Route::post('/rate', [api::class, 'rate']);
            Route::post('/render', [api::class, 'render']);
            Route::post('/purchase', [api::class, 'purchase']);
            Route::post('/character', [api::class, 'character']);
            Route::post('/rating_number', [api::class, 'rating_number']);
            Route::post('/video/rate', [VideoController::class, 'rate']);
            Route::post('/video/rating_number', [VideoController::class, 'rating_number']);
        });

        Route::prefix('admin')->group(function () {
            Route::get('/', [AdminController::class, 'index']);
            Route::get('/pin', [AdminController::class, 'pin'])->middleware(RequireLinkToken::class);
            Route::get('/lock', [AdminController::class, 'lock'])->middleware(RequireLinkToken::class);
            Route::get('/unpin', [AdminController::class, 'unpin'])->middleware(RequireLinkToken::class);
            Route::get('/stick', [AdminController::class, 'stick'])->middleware(RequireLinkToken::class);
            Route::get('/unlock', [AdminController::class, 'unlock'])->middleware(RequireLinkToken::class);
            Route::get('/unstick', [AdminController::class, 'unstick'])->middleware(RequireLinkToken::class);
            Route::get('/decider', [AdminController::class, 'decider']);
            Route::match(['post', 'get'], '/warn', [AdminController::class, 'warn']);
            Route::match(['post', 'get'], '/bans', [AdminController::class, 'bans']);
            Route::match(['post', 'get'], '/elections', [AdminController::class, 'elections']);
            Route::match(['post', 'get'], '/give_dius', [AdminController::class, 'give_dius']);
            Route::match(['post', 'get'], '/createxml', [AdminController::class, 'createxml']);
            Route::match(['post', 'get'], '/give_badges', [AdminController::class, 'give_badges']);
            Route::match(['post', 'get'], '/prune-posts', [AdminController::class, 'prune_posts']);
            Route::match(['post', 'get'], '/rbxcreatexml', [AdminController::class, 'rbxcreatexml']);
            Route::match(['post', 'get'], '/announcements', [AdminController::class, 'announcements']);
            Route::match(['post', 'get'], '/changeversions', [AdminController::class, 'changeversions']);
            Route::match(['post', 'get'], '/ipbans', [AdminToolsController::class, 'ipBans']);
            Route::match(['post', 'get'], '/force-regen', [AdminToolsController::class, 'forceRegen']);
            Route::match(['post', 'get'], '/purge-games', [AdminToolsController::class, 'purgeGames']);
            Route::get('/purchase-log', [AdminToolsController::class, 'purchaseLog']);
            Route::match(['post', 'get'], '/give-asset/{mode}', [AdminToolsController::class, 'giveAsset'])->where('mode', 'dependent|non-dependent');
            Route::get('/approval-log', [AdminToolsController::class, 'approvalLog']);
            Route::match(['post', 'get'], '/player-thumbnails', [AdminToolsController::class, 'playerListThumbs']);
            Route::match(['post', 'get'], '/rank', [AdminToolsController::class, 'setRank']);
            Route::match(['post', 'get'], '/clear-cache', [AdminToolsController::class, 'clearCache']);
            Route::match(['post', 'get'], '/force-username', [AdminToolsController::class, 'forceUsername']);
            Route::get('/invite-tree', [AdminToolsController::class, 'inviteTree']);
            Route::match(['post', 'get'], '/invite-keys', [AdminToolsController::class, 'inviteKeys']);

            Route::withoutMiddleware([Moderation::class, SerializeWrites::class])->group(function () {
                Route::get('/users', [WorkbenchController::class, 'users']);
                Route::get('/users/{id}', [WorkbenchController::class, 'manageUser']);
                Route::get('/users/{id}/{section}', [WorkbenchController::class, 'manageUser']);
                Route::post('/users/{id}/actions', [WorkbenchController::class, 'userAction'])->middleware('limit:60,1');
                Route::get('/assets', [AssetApprovalController::class, 'index']);
                Route::get('/assets/{id}', [AssetApprovalController::class, 'show'])->whereNumber('id');
                Route::post('/assets/{id}/{decision}', [AssetApprovalController::class, 'decide'])->whereNumber('id')->where('decision', 'approve|deny')->middleware('limit:30,1');
                Route::get('/servers', [ServerManagementController::class, 'index']);
                Route::post('/servers', [ServerManagementController::class, 'create'])->middleware('limit:20,1');
                Route::post('/servers/clear', [ServerManagementController::class, 'clear'])->middleware('limit:10,1');
                Route::delete('/servers/{id}', [ServerManagementController::class, 'remove'])->whereNumber('id')->middleware('limit:20,1');
            });
        });

        Route::permanentRedirect('/auth/login', '/login');
        Route::permanentRedirect('/auth/register', '/register');
        Route::permanentRedirect('/auth/form', '/register/form');
        Route::permanentRedirect('/auth/2fa', '/2fa/validate');
        Route::permanentRedirect('/app/place/new', '/games/new');
        Route::permanentRedirect('/download2012', '/download');
        Route::get('/place/{id}/settings', fn ($id) => redirect(Paths::placeEdit((int) $id), 301))->whereNumber('id');
    });

    Route::domain('clientsettings.' . $domain)->group(base_path('routes/services/clientsettings.php'));
    Route::domain('clientsettings.api.' . $domain)->name('api.')->group(base_path('routes/services/clientsettings.php'));
    Route::domain('assetgame.' . $domain)->group(base_path('routes/services/assetgame.php'));
    Route::domain('www.' . $domain)->group(base_path('routes/services/assetgame.php'));
    Route::domain('gamepersistence.' . $domain)->group(base_path('routes/services/gamepersistence.php'));
    Route::domain('prod1-setup.' . $domain)->group(base_path('routes/services/setup.php'));
    Route::domain('versioncompatibility.' . $domain)->group(base_path('routes/services/versioncompatibility.php'));

    Route::domain('api.' . $domain)->group(function () {
        Route::get('/universes/validate-place-join', [rbxAPIs::class, 'validatePlaceJoin']);
        Route::any('/marketplace/productinfo', [rbxAPIs::class, 'productInfo']);
        Route::get('/ownership/hasasset', [\App\Http\Controllers\Client\MarketplaceController::class, 'ownsAsset']);
        Route::get('/currency/balance', [\App\Http\Controllers\Client\MarketplaceController::class, 'balance']);
        Route::get('/my/economy-status', [\App\Http\Controllers\Client\MarketplaceController::class, 'economyStatus']);
        Route::post('/marketplace/purchase', [\App\Http\Controllers\Client\MarketplaceController::class, 'purchase'])->middleware('limit:30,1');
        Route::post('/login/v1', [\App\Http\Controllers\Client\LoginApiController::class, 'login'])->middleware('limit:10,1');
        Route::post('/mobileapi/login', [\App\Http\Controllers\Client\LoginApiController::class, 'mobile'])->middleware('limit:10,1');
    });
});
