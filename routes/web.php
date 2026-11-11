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
use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\FriendsController;
use App\Http\Controllers\Web\InvitesController;
use App\Http\Controllers\Web\ElectionController;
use App\Http\Controllers\Web\CurrencyController;
use App\Http\Controllers\Web\MessagesController;
use App\Http\Middleware\SetClientIp;
use App\Http\Middleware\LimitRequestPerIp;
use App\Http\Middleware\ModerationMiddleware;
use App\Support\Paths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$domain = config('finobe.domain');
$siteHost = parse_url(config('app.url'), PHP_URL_HOST);

Route::domain(parse_url(config('blog.url'), PHP_URL_HOST))->group(base_path('routes/blog.php'));
Route::domain($siteHost)->prefix('admin/blog')
    ->middleware(\App\Http\Middleware\BlogStaff::class)->group(base_path('routes/admin-blog.php'));

Route::middleware([SetClientIp::class, \App\Http\Middleware\IpBanned::class, LimitRequestPerIp::class, ModerationMiddleware::class])->group(function () use ($domain, $siteHost) {
    Route::domain($siteHost)->group(function () {
        Route::fallback(fn () => response()->view('v2/404', [
            'data' => ['embeds' => ['title' => '404 - Finobe', 'image' => config('app.url') . '/s/img/finnobe3logo.png']],
        ], 404));

        Route::match(['post', 'get'], '/', [HomeController::class, 'index']);
        Route::post('/logout', [AccountController::class, 'logout']);
        Route::post('/moderation/acknowledge', [WorkbenchController::class, 'acknowledge'])->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);

        Route::match(['post', 'get'], '/login', [AccountController::class, 'login'])->middleware('throttle:10,1');
        Route::match(['post', 'get'], '/register', [AccountController::class, 'register'])->middleware('throttle:10,1');
        Route::get('/register/form', [AccountController::class, 'form']);
        Route::match(['post', 'get'], '/2fa/validate', [AccountController::class, 'twoFactorChallenge'])->middleware('throttle:10,1');

        Route::get('/password/reset', [AccountController::class, 'password_reset']);
        Route::post('/password/email', [AccountController::class, 'password_email'])->middleware('throttle:5,1');
        Route::match(['post', 'get'], '/password/verify/{id}/{resetid}', [AccountController::class, 'password_verify'])->middleware('throttle:10,1');
        Route::get('/email/verify/{id}/{verifyid}', [AccountController::class, 'email_verify']);
        Route::post('/verify/email', [AccountController::class, 'verify_email'])->middleware('throttle:5,1');

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
        Route::match(['get', 'post'], '/trade/info/{id}', [TradesController::class, 'info'])->whereNumber('id')->middleware('throttle:30,1');
        Route::match(['get', 'post'], '/trade/new/{userId}', [TradesController::class, 'create'])->whereNumber('userId')->middleware('throttle:30,1');

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
            Route::match(['post', 'get', 'options'], '/settings', [AccountController::class, 'settings']);
            Route::match(['post', 'get'], '/theme', [AccountController::class, 'theme']);
            Route::get('/character-settings', [AccountController::class, 'characterSettings']);
            Route::match(['post', 'get'], '/password', [AccountController::class, 'password'])->middleware('throttle:10,1');
            Route::match(['post', 'get'], '/security', [AccountController::class, 'security'])->middleware('throttle:20,1');
            Route::get('/security/backup-codes', [AccountController::class, 'backupCodes']);
            Route::match(['post', 'get'], '/privacy', [AccountController::class, 'privacy']);
            Route::match(['post', 'get'], '/language', [AccountController::class, 'language']);
            Route::match(['post', 'get'], '/games', [AccountController::class, 'games']);
            Route::match(['post', 'get'], '/forum/new/post', [ForumController::class, 'new_post']);
        });

        Route::prefix('user')->group(function () {
            Route::get('/gettoken', [UsersController::class, 'gettoken']);
            Route::get('/transaction-log', [CurrencyController::class, 'transactions']);
            Route::get('/{id}', [ProfileController::class, 'index']);
            Route::get('/{id}/friends', [FriendsController::class, 'list']);
            Route::get('/{id}/add', [FriendsController::class, 'add']);
            Route::get('/{id}/accept', [FriendsController::class, 'accept']);
            Route::get('/{id}/remove', [FriendsController::class, 'remove']);
        });

        Route::prefix('forum')->group(function () {
            Route::get('/', [ForumController::class, 'index']);
            Route::get('/home', [ForumController::class, 'index']);
            Route::get('/home/{section}', [ForumController::class, 'section']);
            Route::get('/subscribe', [ForumController::class, 'subscribe']);
            Route::get('/search', [ForumController::class, 'search']);
            Route::match(['post', 'get'], '/post', [ForumController::class, 'post']);
            Route::match(['post', 'get'], '/edit', [ForumController::class, 'edit_reply']);
            Route::match(['post', 'get'], '/new/reply', [ForumController::class, 'reply']);
            Route::post('/api/notifications/oneread', [api::class, 'markOne'])->middleware('throttle:60,1');
            Route::post('/api/notifications/allread', [api::class, 'markAll'])->middleware('throttle:20,1');
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
            Route::get('/pin', [AdminController::class, 'pin']);
            Route::get('/lock', [AdminController::class, 'lock']);
            Route::get('/unpin', [AdminController::class, 'unpin']);
            Route::get('/stick', [AdminController::class, 'stick']);
            Route::get('/unlock', [AdminController::class, 'unlock']);
            Route::get('/unstick', [AdminController::class, 'unstick']);
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

            Route::withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class])->group(function () {
                Route::get('/users', [WorkbenchController::class, 'users']);
                Route::get('/users/{id}', [WorkbenchController::class, 'manageUser']);
                Route::get('/users/{id}/{section}', [WorkbenchController::class, 'manageUser']);
                Route::post('/users/{id}/actions', [WorkbenchController::class, 'userAction'])->middleware('throttle:60,1');
                Route::get('/assets', [AssetApprovalController::class, 'index']);
                Route::get('/assets/{id}', [AssetApprovalController::class, 'show'])->whereNumber('id');
                Route::post('/assets/{id}/{decision}', [AssetApprovalController::class, 'decide'])->whereNumber('id')->where('decision', 'approve|deny')->middleware('throttle:30,1');
                Route::get('/servers', [ServerManagementController::class, 'index']);
                Route::post('/servers', [ServerManagementController::class, 'create'])->middleware('throttle:20,1');
                Route::post('/servers/clear', [ServerManagementController::class, 'clear'])->middleware('throttle:10,1');
                Route::delete('/servers/{id}', [ServerManagementController::class, 'remove'])->whereNumber('id')->middleware('throttle:20,1');
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
    Route::domain('prod1-setup.' . $domain)->group(base_path('routes/services/setup.php'));
    Route::domain('versioncompatibility.' . $domain)->group(base_path('routes/services/versioncompatibility.php'));

    Route::domain('api.' . $domain)->group(function () {
        Route::get('/universes/validate-place-join', [rbxAPIs::class, 'validatePlaceJoin']);
        Route::any('/marketplace/productinfo', [rbxAPIs::class, 'productInfo']);
    });
});
