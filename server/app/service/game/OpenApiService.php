<?php

namespace app\service\game;

use app\model\Game;
use app\model\Merchant;
use app\model\MerchantBrand;
use app\model\MerchantGame;
use app\model\User;
use app\service\game\adapter\AdapterRegistry;
use app\service\game\trade\MonthlyTableService;
use plugin\saiadmin\exception\ApiException;
use support\Db;

class OpenApiService
{
    public function games(Merchant $merchant, array $where = [])
    {
        $currencies = $merchant->credits()->where('status', 1)->pluck('currency_code')->all();
        $overrides = MerchantGame::where('merchant_id', $merchant->id)->get(['game_id', 'status', 'merchant_status'])->keyBy('game_id');
        $enabled = $overrides->where('status', 1)->where('merchant_status', 1)->keys()->all();
        $blocked = $overrides->keys()->all();
        $brands = MerchantBrand::where(['merchant_id' => $merchant->id, 'status' => 1, 'merchant_status' => 1])->pluck('unique_brand_id')->all();

        return Game::with('brand:id,name,names,provider_brand_code,unique_brand_id,is_gc', 'brand.uniqueBrand:id,name,names,code')->where('status', 1)
            ->whereHas('brand', fn ($query) => $query->whereNotNull('unique_brand_id'))
            ->where(function ($query) use ($currencies) {
                if (!$currencies) $query->whereRaw('0 = 1');
                foreach ($currencies as $currency) $query->orWhereJsonContains('currency_codes', $currency);
            })
            ->where(fn ($query) => $query->whereIn('id', $enabled)->orWhere(fn ($q) => $q->whereHas('brand', fn ($brand) => $brand->whereIn('unique_brand_id', $brands))->whereNotIn('id', $blocked)))
            ->when($where['game_id'] ?? null, fn ($query, $value) => $query->where('id', big2id((int) $value) ?: -1))
            ->when($where['brand_code'] ?? null, fn ($query, $value) => $query->whereHas('brand.uniqueBrand', fn ($brand) => $brand->where('code', $value)))
            ->when($where['keyword'] ?? null, fn ($query, $value) => $query->where(fn ($q) => $q->where('name', 'like', "%{$value}%")->orWhere('game_code', 'like', "%{$value}%")));
    }

    public function launch(Merchant $merchant, array $data, string $ip): array
    {
        $gameId = big2id((int) $data['game_id']);
        $game = $this->games($merchant)->where('id', $gameId ?: -1)->first();
        if (!$game) throw new ApiException('游戏未授权或已停用');
        $currency = $this->currency($merchant, $game, $data['currency'] ?? null);
        $credit = $merchant->credits()->where(['currency_code' => $currency, 'status' => 1])->first();
        if ((int) $merchant->billing_mode === 1 && $credit->settlement_enabled && bccomp((string) $credit->available_amount, '0', 8) <= 0) throw new ApiException('商户服务费额度不足');
        $user = User::firstOrCreate(
            ['merchant_id' => $merchant->id, 'merchant_user_id' => (string) $data['user_id']],
            ['nickname' => $data['nickname'] ?? null, 'status' => 1],
        );
        if ((int) $user->status !== 1) throw new ApiException('玩家已冻结或关闭');

        $playerId = 'mg_' . id2big((int) $user->id) . '_' . strtolower($currency);
        $adapter = AdapterRegistry::get($game->platform_code);
        $config = AdapterRegistry::config($game->platform_code);
        $rtp = Db::table('mg_user_game_rtps')->where([
            'merchant_id' => $merchant->id, 'user_id' => $user->id, 'game_id' => $game->id, 'currency_code' => $currency,
        ])->whereNull('delete_time')->value('rtp') ?? MerchantGame::where([
            'merchant_id' => $merchant->id, 'game_id' => $game->id, 'status' => 1,
        ])->value('default_rtp');
        $result = $adapter->launch($config, $playerId, $game, [
            'currency_code' => $currency,
            'lang' => $data['language'] ?? $merchant->default_language,
            'back_url' => $data['back_url'] ?? '',
            'rtp' => $rtp,
        ]);
        if ($rtp !== null && $game->platform_code !== 'wxgame') $adapter->setRtp($config, [$playerId], $game, $currency, (string) $rtp);
        $user->update(array_filter([
            'nickname' => $data['nickname'] ?? null,
            'last_launch_time' => date('Y-m-d H:i:s'),
            'last_ip' => $ip,
        ], fn ($value) => $value !== null));
        return ['game_url' => $result['game_url'], 'user_id' => $user->merchant_user_id, 'game_id' => (string) $data['game_id'], 'currency' => $currency];
    }

    public function setRtp(Merchant $merchant, array $data): array
    {
        $gameId = big2id((int) $data['game_id']);
        $game = $this->games($merchant)->where('id', $gameId ?: -1)->first();
        if (!$game || !$game->support_rtp) throw new ApiException('该游戏不支持 RTP 调整');
        $currency = $this->currency($merchant, $game, $data['currency'] ?? null);
        $rtp = (string) $data['rtp'];
        if ($game->rtp_options && !in_array($rtp, $game->rtp_options, true)) throw new ApiException('RTP 档位无效');

        $userIds = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($data['user_ids'] ?? ''))))));
        if (!$userIds) {
            MerchantGame::updateOrCreate(
                ['merchant_id' => $merchant->id, 'game_id' => $game->id],
                ['status' => 1, 'merchant_status' => 1, 'default_rtp' => $rtp],
            );
            return ['game_id' => (string) $data['game_id'], 'currency' => $currency, 'rtp' => $rtp, 'scope' => 'game'];
        }

        $users = User::where('merchant_id', $merchant->id)->whereIn('merchant_user_id', $userIds)->get();
        if ($users->count() !== count($userIds)) throw new ApiException('玩家不存在，请先完成一次进游');
        $players = $users->map(fn ($user) => 'mg_' . id2big((int) $user->id) . '_' . strtolower($currency))->all();
        AdapterRegistry::get($game->platform_code)->setRtp(AdapterRegistry::config($game->platform_code), $players, $game, $currency, $rtp);
        $now = date('Y-m-d H:i:s');
        Db::table('mg_user_game_rtps')->upsert($users->map(fn ($user) => [
            'merchant_id' => $merchant->id, 'user_id' => $user->id, 'game_id' => $game->id,
            'currency_code' => $currency, 'rtp' => $rtp, 'create_time' => $now, 'update_time' => $now, 'delete_time' => null,
        ])->all(), ['merchant_id', 'user_id', 'game_id', 'currency_code'], ['rtp', 'update_time', 'delete_time']);
        return ['game_id' => (string) $data['game_id'], 'currency' => $currency, 'rtp' => $rtp, 'scope' => 'players', 'user_ids' => $userIds];
    }

    public function bets(Merchant $merchant, array $where): array
    {
        $month = (string) ($where['month'] ?? gmdate('ym'));
        if (!preg_match('/^\d{4}$/', $month)) throw new ApiException('month 格式应为 YYMM');
        $query = Db::table((new MonthlyTableService())->table('bets', $month) . ' as b')
            ->join('mg_users as u', 'u.id', '=', 'b.user_id')
            ->join('mg_games as g', 'g.id', '=', 'b.game_id')
            ->where('b.merchant_id', $merchant->id)
            ->whereNull('b.delete_time')
            ->when($where['user_id'] ?? null, fn ($q, $value) => $q->where('u.merchant_user_id', $value))
            ->when(($where['status'] ?? '') !== '', fn ($q) => $q->where('b.status', $where['status']))
            ->when($where['date_start'] ?? null, fn ($q, $value) => $q->where('b.business_date', '>=', $value))
            ->when($where['date_end'] ?? null, fn ($q, $value) => $q->where('b.business_date', '<=', $value))
            ->select('b.*', 'u.merchant_user_id', 'g.id as game_id', 'g.name as game_name');
        $list = $query->orderByDesc('b.id')->paginate(min(100, max(1, (int) ($where['limit'] ?? 20))), ['*'], 'page', max(1, (int) ($where['page'] ?? 1)));
        $items = collect($list->items())->map(fn ($bet) => [
            'bet_id' => $bet->bet_no,
            'user_id' => $bet->merchant_user_id,
            'game_id' => (string) id2big((int) $bet->game_id),
            'game_name' => $bet->game_name,
            'currency' => $bet->currency_code,
            'round_id' => $bet->provider_round_id,
            'bet_amount' => $bet->bet_amount,
            'win_amount' => $bet->win_amount,
            'rollback_amount' => bcadd((string) $bet->bet_rollback_amount, (string) $bet->win_rollback_amount, 8),
            'ggr_amount' => $bet->ggr_amount,
            'status' => (int) $bet->status,
            'business_date' => $bet->business_date,
            'settled_time' => $bet->settled_time,
        ])->all();
        return ['list' => $items, 'total' => $list->total()];
    }

    private function currency(Merchant $merchant, Game $game, mixed $value): string
    {
        $enabled = $merchant->credits()->where('status', 1)->pluck('currency_code')->all();
        $currencies = array_values(array_intersect($game->currency_codes, $enabled));
        if (!$currencies) throw new ApiException('商户没有该游戏可用的币种');
        $currency = strtoupper(trim((string) $value));
        if ($currency === '') $currency = in_array('SC', $currencies, true) ? 'SC' : $currencies[0];
        if (!in_array($currency, $currencies, true)) throw new ApiException('游戏不支持或商户未启用该币种');
        return $currency;
    }
}
