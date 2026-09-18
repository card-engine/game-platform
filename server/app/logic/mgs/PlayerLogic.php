<?php

namespace app\logic\mgs;

use app\model\mgs\Game;
use app\model\mgs\User;
use app\service\mgs\MgsConfigService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use support\Db;

class PlayerLogic extends BaseLogic
{
    public function profile(User $user): array
    {
        return [
            'mgs_user_id' => $user->id,
            'unique_id' => $user->unique_id,
            'nickname' => $user->nickname,
            'language' => $user->language,
        ];
    }

    public function update(User $user, array $data): array
    {
        $user->update(['nickname' => $data['nickname']]);
        return $this->profile($user->fresh());
    }

    public function games(User $user, string $currency, int $seed): array
    {
        $played = collect();
        $month = new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
        for ($i = 0; $i < 2; $i++) {
            $table = 'mgs_bets_' . $month->modify("-{$i} month")->format('ym');
            if (!Db::connection()->getSchemaBuilder()->hasTable($table)) continue;
            $played = $played->concat(Db::table($table)->where(['user_id' => $user->id, 'currency_code' => $currency])->whereNull('delete_time')
                ->selectRaw('game_id, MAX(create_time) AS played_time')->groupBy('game_id')->get());
        }

        $recentIds = $played->groupBy('game_id')->map(fn ($rows) => $rows->max('played_time'))->sortDesc()->keys()->map(fn ($id) => (int) $id)->take(8)->values();
        $recent = $this->available($currency)->whereIn('id', $recentIds)->get()->sortBy(fn ($game) => $recentIds->search($game->id))->values();
        $hot = $this->available($currency)->where('is_hot', 1)->orderBy('sort')->orderByDesc('id')->limit(12)->get();
        if ($hot->count() < 12) {
            $hot = $hot->concat($this->available($currency)->whereNotIn('id', $hot->pluck('id'))->orderByDesc('is_new')->orderBy('sort')->orderByDesc('id')->limit(12 - $hot->count())->get());
        }
        $excluded = $recent->pluck('id')->merge($hot->pluck('id'))->unique();
        $day = (new DateTimeImmutable('now', new DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')))))->format('Y-m-d');
        $brands = $recent->pluck('brand_id');
        $types = $recent->pluck('game_type');
        $discover = $this->available($currency)->whereNotIn('id', $excluded)
            ->orderByRaw('CRC32(CONCAT(id, ?))', [$user->unique_id . ':' . $day . ':' . $seed])->limit(80)->get()
            ->sortByDesc(fn ($game) => ($brands->contains($game->brand_id) ? 2 : 0) + ($types->contains($game->game_type) ? 1 : 0))->take(12)->values();

        return ['recent' => $this->data($recent), 'hot' => $this->data($hot), 'discover' => $this->data($discover)];
    }

    private function available(string $currency)
    {
        return Game::with('brand:id,platform_brand_code,name')->whereJsonContains('currency_codes', $currency)
            ->where(['status' => 1, 'upstream_status' => 1, 'platform_status' => 1, 'merchant_status' => 1])->whereNull('unavailable_reason');
    }

    private function data(Collection $games): array
    {
        return $games->map(fn ($game) => [
            'mgs_game_id' => $game->id,
            'name' => $game->name,
            'icon_url' => $game->icon_url,
            'mgs_brand_id' => $game->brand_id,
            'brand_code' => $game->brand?->platform_brand_code,
            'brand_name' => $game->brand?->name,
            'game_type' => $game->game_type,
            'currency_codes' => $game->currency_codes,
            'is_hot' => $game->is_hot,
            'is_new' => $game->is_new,
            'status' => 1,
        ])->values()->all();
    }
}
