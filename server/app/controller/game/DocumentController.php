<?php

namespace app\controller\game;

use app\model\Game;
use app\model\Merchant;
use app\model\MerchantGame;
use app\model\User;
use app\service\game\EnterpriseScope;
use app\service\game\OpenApiService;
use app\service\game\SecretService;
use app\service\game\trade\MonthlyTableService;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Db;
use support\Request;
use support\Response;

class DocumentController extends BaseController
{
    #[Permission('查看对接文档', 'app:game:document:index')]
    public function index(Request $request): Response
    {
        $scope = EnterpriseScope::current((int) $this->adminInfo['id']);
        $ids = EnterpriseScope::merchantIds((int) $this->adminInfo['id']);
        $merchants = Merchant::with('enterprise:id,name')->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')->get(['id', 'enterprise_id', 'mch_id', 'name', 'callback_url', 'secret', 'default_language']);
        $merchant = $merchants->firstWhere('id', (int) ($request->input('merchant_id') ?: $request->header('X-Merchant-Id'))) ?: $merchants->first();
        $template = file_get_contents(base_path() . '/app/service/game/adapter/documents/MG商户接入文档.md');
        $defaults = array_fill_keys([
            '{{MCH_ID}}', '{{CALLBACK_URL}}', '{{USER_ID}}', '{{GAME_ID}}', '{{GAME_NAME}}', '{{GAME_ICON}}', '{{BRAND_CODE}}', '{{BRAND_NAME}}',
            '{{BET_TRANSACTION_ID}}', '{{PARENT_ROUND_ID}}', '{{ROUND_ID}}', '{{WIN_TRANSACTION_ID}}', '{{CANCEL_TRANSACTION_ID}}',
            '{{GAMES_SIGN}}', '{{LAUNCH_SIGN}}', '{{BETS_SIGN}}', '{{RTP_GAME_SIGN}}', '{{RTP_USER_SIGN}}', '{{BALANCE_SIGN}}', '{{BET_SIGN}}', '{{WIN_SIGN}}', '{{CANCEL_SIGN}}',
            '{{BET_ID}}', '{{BET_USER_ID}}', '{{BET_GAME_ID}}', '{{BET_GAME_NAME}}', '{{BET_CURRENCY}}', '{{BET_ROUND_ID}}', '{{BET_AMOUNT}}', '{{BET_WIN_AMOUNT}}',
            '{{BET_ROLLBACK_AMOUNT}}', '{{BET_GGR_AMOUNT}}', '{{BET_BUSINESS_DATE}}',
        ], '待配置');
        $defaults += [
            '{{TIMESTAMP}}' => (string) time(), '{{LANGUAGE}}' => 'en', '{{GAME_TYPE}}' => 'null', '{{CURRENCIES}}' => '["USD"]',
            '{{CURRENCY}}' => 'USD', '{{SUPPORT_DEMO}}' => '0', '{{SUPPORT_RTP}}' => '0', '{{RTP_OPTIONS}}' => 'null', '{{DEFAULT_RTP}}' => '95', '{{PLAYER_RTP}}' => '95',
            '{{BETS_MONTH}}' => gmdate('ym'), '{{BET_STATUS}}' => '0', '{{BET_SETTLED_TIME}}' => 'null', '{{BET_TOTAL}}' => '0',
        ];
        $merchantOptions = $merchants->map(fn ($item) => [
            'id' => $item->id,
            'label' => ($scope ? '' : $item->enterprise->name . ' / ') . $item->name . ' (' . $item->mch_id . ')',
        ])->values();
        if (!$merchant) return $this->success([
            'content' => strtr($template, $defaults), 'merchant_id' => null, 'merchants' => $merchantOptions,
            'notice' => '尚未创建商户参数。创建后，文档会自动带入真实参数并生成签名，方便联调时直接比对。',
        ]);

        $game = $credit = null;
        foreach ($merchant->credits()->where('status', 1)->get() as $item) {
            $game = (new OpenApiService())->games($merchant)->whereJsonContains('currency_codes', $item->currency_code)->orderBy('id')->first();
            if ($game) {
                $credit = $item;
                break;
            }
        }
        if (!$game) return $this->success([
            'content' => strtr($template, array_replace($defaults, [
                '{{MCH_ID}}' => $merchant->mch_id, '{{CALLBACK_URL}}' => rtrim((string) $merchant->callback_url, '/'), '{{LANGUAGE}}' => $merchant->default_language,
            ])),
            'merchant_id' => $merchant->id, 'merchants' => $merchantOptions,
            'notice' => '当前参数暂未匹配可用游戏。文档仍可查看，完成币种和游戏配置后会自动生成真实示例与签名。',
        ]);

        $user = User::where('merchant_id', $merchant->id)->orderBy('id')->first();
        $userId = $user?->merchant_user_id ?: 'demo_' . $merchant->mch_id;
        $gameId = (string) id2big((int) $game->id);
        $currency = $credit->currency_code;
        $timestamp = time();
        $defaultRtp = MerchantGame::where(['merchant_id' => $merchant->id, 'game_id' => $game->id])->value('default_rtp')
            ?? ($game->rtp_options[0] ?? '95');
        $playerRtp = $user ? Db::table('mg_user_game_rtps')->where([
            'merchant_id' => $merchant->id, 'user_id' => $user->id, 'game_id' => $game->id, 'currency_code' => $currency,
        ])->whereNull('delete_time')->value('rtp') : null;
        $playerRtp ??= $defaultRtp;
        $betExample = null;
        $betsMonth = gmdate('ym');
        foreach ([$betsMonth, gmdate('ym', strtotime('-1 month'))] as $month) {
            $betExample = Db::table((new MonthlyTableService())->table('bets', $month))->where('merchant_id', $merchant->id)->latest('id')->first();
            if ($betExample) {
                $betsMonth = $month;
                break;
            }
        }
        $betUser = $betExample ? User::find($betExample->user_id) : $user;
        $betGame = $betExample ? Game::find($betExample->game_id) : $game;
        $betUserId = $betUser?->merchant_user_id ?: $userId;
        $secret = SecretService::decrypt($merchant->getRawOriginal('secret'));
        $signed = function (array $data) use ($merchant, $timestamp, $secret): string {
            return game_platform_sign(['mch_id' => $merchant->mch_id, 'timestamp' => $timestamp] + $data, $secret);
        };

        $base = ['user_id' => $userId, 'game_id' => $gameId, 'currency' => $currency];
        $bet = $base + [
            'transaction_id' => 'bet_' . date('Ymd') . '_10001', 'parent_round_id' => 'session_' . date('Ymd') . '_10001',
            'round_id' => 'round_' . date('Ymd') . '_10001', 'bet_amount' => '10.00',
        ];
        $content = strtr($template, [
            '{{MCH_ID}}' => $merchant->mch_id,
            '{{CALLBACK_URL}}' => rtrim((string) $merchant->callback_url, '/'),
            '{{TIMESTAMP}}' => (string) $timestamp,
            '{{LANGUAGE}}' => $merchant->default_language,
            '{{USER_ID}}' => $userId,
            '{{GAME_ID}}' => $gameId,
            '{{GAME_NAME}}' => $game->name,
            '{{GAME_ICON}}' => $game->icon_url,
            '{{BRAND_CODE}}' => $game->brand->uniqueBrand->code,
            '{{BRAND_NAME}}' => $game->brand->uniqueBrand->name,
            '{{GAME_TYPE}}' => json_encode($game->game_type, JSON_UNESCAPED_UNICODE),
            '{{CURRENCIES}}' => json_encode($game->currency_codes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '{{CURRENCY}}' => $currency,
            '{{SUPPORT_DEMO}}' => (string) (int) $game->support_demo,
            '{{SUPPORT_RTP}}' => (string) (int) $game->support_rtp,
            '{{RTP_OPTIONS}}' => json_encode($game->rtp_options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '{{DEFAULT_RTP}}' => (string) $defaultRtp,
            '{{PLAYER_RTP}}' => (string) $playerRtp,
            '{{BETS_MONTH}}' => $betsMonth,
            '{{BET_ID}}' => $betExample?->bet_no ?: '暂无真实注单',
            '{{BET_USER_ID}}' => $betUserId,
            '{{BET_GAME_ID}}' => (string) id2big((int) $betGame->id),
            '{{BET_GAME_NAME}}' => $betGame->name,
            '{{BET_CURRENCY}}' => $betExample?->currency_code ?: $currency,
            '{{BET_ROUND_ID}}' => $betExample?->provider_round_id ?: '暂无真实局号',
            '{{BET_AMOUNT}}' => $betExample?->bet_amount ?: '0.00000000',
            '{{BET_WIN_AMOUNT}}' => $betExample?->win_amount ?: '0.00000000',
            '{{BET_ROLLBACK_AMOUNT}}' => $betExample ? bcadd((string) $betExample->bet_rollback_amount, (string) $betExample->win_rollback_amount, 8) : '0.00000000',
            '{{BET_GGR_AMOUNT}}' => $betExample?->ggr_amount ?: '0.00000000',
            '{{BET_STATUS}}' => (string) (int) ($betExample?->status ?? 0),
            '{{BET_BUSINESS_DATE}}' => $betExample?->business_date ?: date('Y-m-d'),
            '{{BET_SETTLED_TIME}}' => $betExample?->settled_time ? json_encode($betExample->settled_time) : 'null',
            '{{BET_TOTAL}}' => $betExample ? '1' : '0',
            '{{BET_TRANSACTION_ID}}' => $bet['transaction_id'],
            '{{PARENT_ROUND_ID}}' => $bet['parent_round_id'],
            '{{ROUND_ID}}' => $bet['round_id'],
            '{{WIN_TRANSACTION_ID}}' => 'win_' . date('Ymd') . '_10001_1',
            '{{CANCEL_TRANSACTION_ID}}' => 'cancel_' . date('Ymd') . '_10001',
            '{{GAMES_SIGN}}' => $signed(['language' => $merchant->default_language, 'game_id' => $gameId, 'page' => 1, 'limit' => 20]),
            '{{LAUNCH_SIGN}}' => $signed($base + ['language' => $merchant->default_language, 'back_url' => 'https://mgames.im']),
            '{{BETS_SIGN}}' => $signed(['month' => $betsMonth, 'user_id' => $betUserId, 'page' => 1, 'limit' => 20]),
            '{{RTP_GAME_SIGN}}' => $signed(['game_id' => $gameId, 'currency' => $currency, 'rtp' => (string) $defaultRtp]),
            '{{RTP_USER_SIGN}}' => $signed(['game_id' => $gameId, 'currency' => $currency, 'rtp' => (string) $playerRtp, 'user_ids' => $userId]),
            '{{BALANCE_SIGN}}' => $signed(['user_id' => $userId, 'currency' => $currency]),
            '{{BET_SIGN}}' => $signed($bet),
            '{{WIN_SIGN}}' => $signed($base + ['transaction_id' => 'win_' . date('Ymd') . '_10001_1', 'parent_round_id' => $bet['parent_round_id'], 'round_id' => $bet['round_id'], 'win_amount' => '6.00', 'is_end' => 1]),
            '{{CANCEL_SIGN}}' => $signed($base + ['transaction_id' => 'cancel_' . date('Ymd') . '_10001', 'original_transaction_id' => $bet['transaction_id'], 'parent_round_id' => $bet['parent_round_id'], 'round_id' => $bet['round_id']]),
        ]);

        return $this->success([
            'content' => $content,
            'merchant_id' => $merchant->id,
            'merchants' => $merchantOptions,
            'notice' => '',
        ]);
    }
}
