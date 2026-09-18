<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;

Route::disableDefaultRoute();

// 管理端登录续期；后台接口统一经过全局登录、权限和操作日志中间件。
Route::post('/auth/refresh', [app\controller\AuthController::class, 'refresh']);

// MG 游戏平台管理端：企业、商户、游戏供给和运营统计。
Route::group('/game', function () {
    Route::get('/context', [app\controller\game\ContextController::class, 'index']);
    fastRoute('enterprise', app\controller\game\EnterpriseController::class);
    Route::get('/enterprise/users', [app\controller\game\EnterpriseController::class, 'users']);
    Route::post('/enterprise/user', [app\controller\game\EnterpriseController::class, 'saveUser']);
    Route::put('/enterprise/user/status', [app\controller\game\EnterpriseController::class, 'userStatus']);
    Route::put('/enterprise/user/merchants', [app\controller\game\EnterpriseController::class, 'userMerchants']);
    Route::put('/enterprise/user/password', [app\controller\game\EnterpriseController::class, 'userPassword']);
    Route::get('/enterprise/roles', [app\controller\game\EnterpriseController::class, 'roles']);

    fastRoute('platform-admin', app\controller\game\PlatformAdminController::class);
    Route::get('/platform-admin/options', [app\controller\game\PlatformAdminController::class, 'options']);
    Route::put('/platform-admin/password', [app\controller\game\PlatformAdminController::class, 'password']);

    fastRoute('merchant', app\controller\game\MerchantController::class);
    Route::get('/merchant/options', [app\controller\game\MerchantController::class, 'options']);
    Route::get('/merchant/secret', [app\controller\game\MerchantController::class, 'secret']);
    Route::put('/merchant/secret', [app\controller\game\MerchantController::class, 'resetSecret']);
    Route::get('/merchant/grants', [app\controller\game\MerchantController::class, 'grantState']);
    Route::put('/merchant/grants', [app\controller\game\MerchantController::class, 'grants']);
    Route::put('/merchant/credits', [app\controller\game\MerchantController::class, 'credits']);
    Route::post('/merchant/credit', [app\controller\game\MerchantController::class, 'adjustCredit']);
    Route::get('/merchant/billing', [app\controller\game\MerchantController::class, 'billingState']);
    Route::put('/merchant/billing', [app\controller\game\MerchantController::class, 'billing']);
    Route::put('/merchant/monthly-bill', [app\controller\game\MerchantController::class, 'billStatus']);

    Route::get('/brands', [app\controller\game\IndexController::class, 'brands']);
    Route::get('/unique-brands', [app\controller\game\IndexController::class, 'uniqueBrands']);
    Route::get('/lists', [app\controller\game\IndexController::class, 'lists']);
    Route::post('/trial', [app\controller\game\IndexController::class, 'trial']);
    Route::post('/sync', [app\controller\game\IndexController::class, 'sync']);
    Route::put('/status', [app\controller\game\IndexController::class, 'status']);
    Route::get('/brand-impact', [app\controller\game\IndexController::class, 'mappingImpact']);
    Route::put('/brand-map', [app\controller\game\IndexController::class, 'mapBrand']);
    Route::put('/brand-mode', [app\controller\game\IndexController::class, 'brandMode']);

    Route::get('/document', [app\controller\game\DocumentController::class, 'index']);

    Route::get('/settings', [app\controller\game\SettingsController::class, 'configs']);
    Route::get('/settings/rebuild-status', [app\controller\game\SettingsController::class, 'rebuildStatus']);
    Route::put('/settings', [app\controller\game\SettingsController::class, 'save']);
    Route::get('/exchange-rates', [app\controller\game\SettingsController::class, 'exchangeRates']);
    Route::post('/exchange-rates/sync', [app\controller\game\SettingsController::class, 'syncExchangeRate']);

    Route::get('/operations/overview', [app\controller\game\OperationsController::class, 'overview']);
    Route::get('/operations/users', [app\controller\game\OperationsController::class, 'users']);
    Route::get('/operations/bets', [app\controller\game\OperationsController::class, 'bets']);
    Route::get('/operations/bills', [app\controller\game\OperationsController::class, 'bills']);
    Route::get('/operations/merchant-bills', [app\controller\game\OperationsController::class, 'merchantBills']);
    Route::get('/operations/reports', [app\controller\game\OperationsController::class, 'reports']);
})->middleware([app\middleware\EnterpriseStatus::class]);

// MG 面向下游商户的服务端接口：商户签名认证，提供目录、进游、RTP 和注单查询。
Route::group('/open_api', function () {
    Route::post('/games', [app\controller\openapi\OpenApiController::class, 'games']);
    Route::post('/launch', [app\controller\openapi\OpenApiController::class, 'launch']);
    Route::post('/rtp', [app\controller\openapi\OpenApiController::class, 'setRtp']);
    Route::post('/bets', [app\controller\openapi\OpenApiController::class, 'bets']);
})->middleware([app\middleware\MerchantAuth::class]);

// 上游游戏平台回调：按平台前缀区分适配器。
Route::post('/provider/{platform:wxgame|acewin|tada|goldengatex}/{action:[A-Za-z-]+}', [app\controller\provider\ProviderController::class, 'callback']);

// 自营平台管理接口：后台登录后访问，统一使用 /mgs 前缀。
Route::group('/mgs', function () {
    Route::get('/recharges', [app\controller\mgs\RechargeController::class, 'index']);
    Route::get('/recharges/{id:\d+}', [app\controller\mgs\RechargeController::class, 'read']);
    Route::get('/transfers', [app\controller\mgs\RechargeController::class, 'transfers']);
    Route::get('/transfers/{id:\d+}', [app\controller\mgs\RechargeController::class, 'transfer']);
    Route::post('/transfers/{id:\d+}/credit', [app\controller\mgs\RechargeController::class, 'credit']);
    Route::put('/transfers/{id:\d+}/review', [app\controller\mgs\RechargeController::class, 'review']);
    Route::get('/recharge-scan', [app\controller\mgs\RechargeController::class, 'scan']);
    Route::get('/overview', [app\controller\mgs\AdminController::class, 'overview']);
    Route::get('/games', [app\controller\mgs\AdminController::class, 'games']);
    Route::post('/games/sync', [app\controller\mgs\AdminController::class, 'sync']);
    Route::put('/games/status', [app\controller\mgs\AdminController::class, 'status']);
    Route::put('/games/config', [app\controller\mgs\AdminController::class, 'config']);
    Route::post('/games/trial', [app\controller\mgs\AdminController::class, 'trial'])->middleware([app\middleware\EnterpriseStatus::class]);
    Route::get('/users', [app\controller\mgs\AdminController::class, 'users']);
    Route::get('/bets', [app\controller\mgs\AdminController::class, 'bets']);
    Route::get('/bills', [app\controller\mgs\AdminController::class, 'bills']);
    Route::get('/reports', [app\controller\mgs\AdminController::class, 'reports']);
    Route::get('/settlements', [app\controller\mgs\AdminController::class, 'settlements']);
    Route::post('/settlements/generate', [app\controller\mgs\AdminController::class, 'generateSettlement']);
});

// MGS 自营平台玩家接口：浏览器凭证认证；资金回调仍单独验签。
Route::group('/api', function () {
    Route::post('/session', [app\controller\mgs\ApiController::class, 'session']);
    Route::get('/brands', [app\controller\mgs\ApiController::class, 'brands']);
    Route::get('/games', [app\controller\mgs\ApiController::class, 'games']);
    Route::post('/games/launch', [app\controller\mgs\ApiController::class, 'launch']);
    Route::get('/user/games', [app\controller\mgs\ApiController::class, 'userGames']);
    Route::get('/user', [app\controller\mgs\ApiController::class, 'user']);
    Route::put('/user', [app\controller\mgs\ApiController::class, 'updateUser']);
    Route::get('/wallet', [app\controller\mgs\ApiController::class, 'wallet']);
    Route::get('/recharges/options', [app\controller\mgs\ApiController::class, 'rechargeOptions']);
    Route::post('/recharges', [app\controller\mgs\ApiController::class, 'createRecharge']);
    Route::get('/recharges/current', [app\controller\mgs\ApiController::class, 'currentRecharge']);
    Route::get('/recharges/{id:\d+}', [app\controller\mgs\ApiController::class, 'recharge']);
    // MG -> MGS 钱包回调：由 MgsCallbackService 独立验签，不属于浏览器余额接口。
    Route::post('/mgames/{action:balance|bet|win|cancel}', [app\controller\mgs\CallbackController::class, 'callback']);
});
