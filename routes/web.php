<?php

use App\Http\Controllers\api;
use App\Http\Controllers\admin;
use App\Http\Controllers\rbxAPIs;
use App\Http\Controllers\frontEnd;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\AdminController;
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
use Illuminate\Support\Facades\Route;

$domain = env('FINOBE_DOMAIN', 'finobe.wtf');

// The public blog is independent of the game's authentication/Redis middleware.
Route::domain(parse_url(config('blog.url'), PHP_URL_HOST))->group(base_path('routes/blog.php'));
Route::domain(parse_url(env('APP_URL'), PHP_URL_HOST))->prefix('admin/blog')
    ->middleware(\App\Http\Middleware\BlogStaff::class)->group(base_path('routes/admin-blog.php'));

Route::middleware([SetClientIp::class, \App\Http\Middleware\IpBanned::class, LimitRequestPerIp::class, ModerationMiddleware::class])->group(function() use ($domain) {
    Route::domain(parse_url(env('APP_URL'), PHP_URL_HOST))->group(function() {
        Route::fallback(function() {
            return response()->view('v2/404', [
                'data' => [
                    'embeds' => [
                        'title' => '404 - Finobe',
                        'image' => env('APP_URL') . '/s/img/finnobe3logo.png'
                    ]
                ]
            ], 404);
        });

        Route::post('/moderation/acknowledge', [\App\Http\Controllers\Admin\WorkbenchController::class, 'acknowledge'])->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
        Route::get('/forum', fn() => redirect('/forum/home'));
        // the Finobe launcher (tools/bootstrapper), served by prod1-setup
        Route::get('/download', fn() => redirect('https://prod1-setup.' . env('FINOBE_DOMAIN', 'finobe.wtf') . '/FinobeLauncher.exe'));
        Route::get('/download2012', fn() => redirect('/download'));   // old 2012 links: 2012 installs through FinobeLauncher now
        Route::get('/users', [UsersController::class, 'index']);
        Route::get('/videos', [VideoController::class, 'index']);
        Route::get('/create', [HomeController::class, 'create']);
        Route::get('/trades', [TradesController::class, 'trades']);
        Route::match(['get', 'post'], '/trade/info/{id}', [TradesController::class, 'info'])->whereNumber('id')->middleware('throttle:30,1');
        Route::match(['get', 'post'], '/trade/new/{userId}', [TradesController::class, 'create'])->whereNumber('userId')->middleware('throttle:30,1');
        Route::get('/item/{id}', [CatalogController::class, 'item']);
        Route::get('/invites', [InvitesController::class, 'index']); // testtestestt
        Route::get('/video/{id}', [VideoController::class, 'video']);
        Route::get('/video/data/{id}', [VideoController::class, 'video_data']);
        Route::get('/video/thumb/{id}', [VideoController::class, 'video_thumb']);
        Route::get('/password/reset', [AccountController::class, 'password_reset']);
        Route::get('/friends/incoming', [FriendsController::class, 'incoming']);
        Route::get('/transparency/bans', [LegalController::class, 'transparency_bans']);
        Route::get('/email/verify/{id}/{verifyid}', [AccountController::class, 'email_verify']);
        Route::post('/verify/email', [AccountController::class, 'verify_email']);
        Route::post('/password/email', [AccountController::class, 'password_email']);
        Route::match(['post', 'get'], '/', [HomeController::class, 'index']);
        Route::post('/logout', [AccountController::class, 'logout']);
        Route::match(['post', 'get'], '/election', [ElectionController::class, 'election']);
        Route::match(['post', 'get'], '/invites/new', [InvitesController::class, 'new']);
        Route::match(['post', 'get'], '/item/{id}/settings', [CatalogController::class, 'settings']);
        Route::post('/item/{id}/delete', [CatalogController::class, 'delete'])->whereNumber('id');
        Route::match(['post', 'get'], '/password/verify/{id}/{resetid}', [AccountController::class, 'password_verify']);

        Route::prefix('legal')->group(function() {
            Route::get('/rules', [LegalController::class, 'rules']);
            Route::get('/terms', [LegalController::class, 'terms']);
            Route::get('/privacy', [LegalController::class, 'privacy']);
            Route::get('/welcome', [LegalController::class, 'welcome']);
            Route::get('/about-us', [LegalController::class, 'about_us']);
        });

        Route::prefix('place')->group(function() {
            Route::get('/{id}', [GamesController::class, 'place']);
            Route::match(['post', 'get'], '/{id}/settings', [GamesController::class, 'place_settings']);
        });

        Route::prefix('app')->group(function() {
            Route::get('/inbox', [MessagesController::class, 'inbox']);
            Route::get('/places', [GamesController::class, 'index']);
            Route::get('/character', [AvatarController::class, 'index']);
            Route::get('/inbox/sent', [MessagesController::class, 'sent']);
            Route::get('/inbox/archive', [MessagesController::class, 'archive']);
            Route::match(['post', 'get'], '/theme', [AccountController::class, 'theme']);
            Route::get('/character-settings', [AccountController::class, 'characterSettings']);
            Route::match(['post', 'get'], '/password', [AccountController::class, 'password'])->middleware('throttle:10,1');
            Route::match(['post', 'get'], '/security', [AccountController::class, 'security'])->middleware('throttle:20,1');
            Route::get('/security/backup-codes', [AccountController::class, 'backupCodes']);
            Route::match(['post', 'get'], '/privacy', [AccountController::class, 'privacy']);
            Route::match(['post', 'get'], '/language', [AccountController::class, 'language']);
            Route::match(['post', 'get'], '/games', [AccountController::class, 'games']);
            Route::match(['post', 'get'], '/place/new', [GamesController::class, 'place_new']);
            Route::match(['post', 'get'], '/inbox/message', [MessagesController::class, 'message']);
            Route::match(['post', 'get'], '/inbox/compose', [MessagesController::class, 'compose']);
            Route::match(['post', 'get'], '/forum/new/post', [ForumController::class, 'new_post']);
            Route::match(['post', 'get', 'options'], '/settings', [AccountController::class, 'settings']);
        });

        Route::prefix('catalog')->group(function() {
            Route::match(['post', 'get', 'options'], '/new', [CatalogController::class, 'new']);
            Route::get('/{section}', [CatalogController::class, 'index']);
            Route::get('/', fn() => redirect('/catalog/hats'));
        });

        Route::prefix('user')->group(function() {
            Route::get('/gettoken', [UsersController::class, 'gettoken']);
            Route::get('/transaction-log', [CurrencyController::class, 'transactions']);
            Route::get('/{id}', [ProfileController::class, 'index']);
            Route::get('/{id}/add', [FriendsController::class, 'add']);
            Route::get('/{id}/accept', [FriendsController::class, 'accept']);
            Route::get('/{id}/remove', [FriendsController::class, 'remove']);
            Route::get('/{id}/friends', [FriendsController::class, 'list']);
        });

        Route::prefix('forum')->group(function() {
            Route::get('/home', [ForumController::class, 'index']);
            Route::get('/home/{section}', [ForumController::class, 'section']);
            Route::get('/subscribe', [ForumController::class, 'subscribe']);
            Route::get('/search', [ForumController::class, 'search']);
            Route::match(['post', 'get'], '/post', [ForumController::class, 'post']);
            Route::match(['post', 'get'], '/edit', [ForumController::class, 'edit_reply']);
            Route::match(['post', 'get'], '/new/reply', [ForumController::class, 'reply']);
        });

        Route::prefix('api')->group(function() {
            Route::get('/mark', [api::class, 'mark']); // not mathmark reference >:D

            Route::get('/places', [api::class, 'places']);

            Route::get('/inventory', [api::class, 'inventory']);
            Route::post('/rate', [api::class, 'rate']);
            Route::post('/render', [api::class, 'render']);
            Route::post('/purchase', [api::class, 'purchase']);
            Route::post('/character', [api::class, 'character']);
            Route::post('/rating_number', [api::class, 'rating_number']);

            Route::prefix('video')->group(function() {
                Route::post('/rate', [VideoController::class, 'rate']);
                Route::post('/rating_number', [VideoController::class, 'rating_number']);
            });
        });

        Route::match(['post', 'get'], '/2fa/validate', [AccountController::class, 'twoFactorChallenge'])->middleware('throttle:10,1');

        Route::prefix('auth')->group(function() {
            Route::get('/form', [AccountController::class, 'form']);
            Route::match(['post', 'get'], '/login', [AccountController::class, 'login']);
            Route::get('/2fa', fn () => redirect('/2fa/validate'));
            Route::match(['post', 'get'], '/register', [AccountController::class, 'register']);
        });

        Route::prefix('admin')->group(function() {
            Route::get('/', [AdminController::class, 'index']);
            Route::get('/users', [\App\Http\Controllers\Admin\WorkbenchController::class, 'users'])->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::get('/users/{id}', [\App\Http\Controllers\Admin\WorkbenchController::class, 'manageUser'])->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::get('/users/{id}/{section}', [\App\Http\Controllers\Admin\WorkbenchController::class, 'manageUser'])->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::post('/users/{id}/actions', [\App\Http\Controllers\Admin\WorkbenchController::class, 'userAction'])->middleware('throttle:60,1')->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::get('/pin', [AdminController::class, 'pin']);
            Route::get('/lock', [AdminController::class, 'lock']);
            Route::get('/unpin', [AdminController::class, 'unpin']);
            Route::get('/stick', [AdminController::class, 'stick']);
            Route::get('/unlock', [AdminController::class, 'unlock']);
            Route::get('/assets', [\App\Http\Controllers\Admin\AssetApprovalController::class, 'index'])->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::get('/assets/{id}', [\App\Http\Controllers\Admin\AssetApprovalController::class, 'show'])->whereNumber('id')->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::post('/assets/{id}/{decision}', [\App\Http\Controllers\Admin\AssetApprovalController::class, 'decide'])->whereNumber('id')->where('decision', 'approve|deny')->middleware('throttle:30,1')->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::get('/unstick', [AdminController::class, 'unstick']);
            Route::get('/decider', [AdminController::class, 'decider']);
            Route::match(['post', 'get'], '/warn', [AdminController::class, 'warn']);
            Route::match(['post', 'get'], '/bans', [AdminController::class, 'bans']);
            Route::get('/servers', [\App\Http\Controllers\Admin\ServerManagementController::class, 'index'])->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::post('/servers', [\App\Http\Controllers\Admin\ServerManagementController::class, 'create'])->middleware('throttle:20,1')->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::post('/servers/clear', [\App\Http\Controllers\Admin\ServerManagementController::class, 'clear'])->middleware('throttle:10,1')->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::delete('/servers/{id}', [\App\Http\Controllers\Admin\ServerManagementController::class, 'remove'])->whereNumber('id')->middleware('throttle:20,1')->withoutMiddleware([SetClientIp::class, LimitRequestPerIp::class, ModerationMiddleware::class]);
            Route::match(['post', 'get'], '/elections', [AdminController::class, 'elections']);
            Route::match(['post', 'get'], '/give_dius', [AdminController::class, 'give_dius']);
            Route::match(['post', 'get'], '/createxml', [AdminController::class, 'createxml']);
            Route::match(['post', 'get'], '/give_badges', [AdminController::class, 'give_badges']);
            Route::match(['post', 'get'], '/prune-posts', [AdminController::class, 'prune_posts']);
            Route::match(['post', 'get'], '/rbxcreatexml', [AdminController::class, 'rbxcreatexml']);
            Route::match(['post', 'get'], '/announcements', [AdminController::class, 'announcements']);
            Route::match(['post', 'get'], '/changeversions', [AdminController::class, 'changeversions']);
            // the rest of the 2020 admin panel
            Route::match(['post', 'get'], '/ipbans', [\App\Http\Controllers\Web\AdminToolsController::class, 'ipBans']);
            Route::match(['post', 'get'], '/force-regen', [\App\Http\Controllers\Web\AdminToolsController::class, 'forceRegen']);
            Route::match(['post', 'get'], '/purge-games', [\App\Http\Controllers\Web\AdminToolsController::class, 'purgeGames']);
            Route::get('/purchase-log', [\App\Http\Controllers\Web\AdminToolsController::class, 'purchaseLog']);
            Route::match(['post', 'get'], '/give-asset/{mode}', [\App\Http\Controllers\Web\AdminToolsController::class, 'giveAsset'])->where('mode', 'dependent|non-dependent');
            Route::get('/approval-log', [\App\Http\Controllers\Web\AdminToolsController::class, 'approvalLog']);
            Route::match(['post', 'get'], '/player-thumbnails', [\App\Http\Controllers\Web\AdminToolsController::class, 'playerListThumbs']);
            Route::match(['post', 'get'], '/rank', [\App\Http\Controllers\Web\AdminToolsController::class, 'setRank']);
            Route::match(['post', 'get'], '/clear-cache', [\App\Http\Controllers\Web\AdminToolsController::class, 'clearCache']);
            Route::match(['post', 'get'], '/force-username', [\App\Http\Controllers\Web\AdminToolsController::class, 'forceUsername']);
            Route::get('/invite-tree', [\App\Http\Controllers\Web\AdminToolsController::class, 'inviteTree']);
            Route::match(['post', 'get'], '/invite-keys', [\App\Http\Controllers\Web\AdminToolsController::class, 'inviteKeys']);
        });
    });

    Route::domain('clientsettings.' . $domain)
        ->group(base_path('routes/services/clientsettings.php'));

    // newer clients and RCC ask clientsettings.api.<domain> (an HTML 404 there crashes RCC)
    Route::domain('clientsettings.api.' . $domain)->name('api.')
        ->group(base_path('routes/services/clientsettings.php'));
    
    Route::domain('assetgame.' . $domain)
        ->group(base_path('routes/services/assetgame.php'));

    Route::domain('www.' . $domain)
        ->group(base_path('routes/services/assetgame.php'));

    Route::domain('prod1-setup.' . $domain)
        ->group(base_path('routes/services/setup.php'));

    Route::domain('versioncompatibility.' . $domain)
        ->group(base_path('routes/services/versioncompatibility.php'));

    Route::domain('api.' . $domain)->group(function() {
        Route::get('/universes/validate-place-join', [rbxAPIs::class, 'validatePlaceJoin']);
        Route::any('/marketplace/productinfo', [rbxAPIs::class, 'productInfo']);
    });
});
