<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class Game extends BaseModel
{
    protected $table = 'mg_games';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['names' => 'array', 'currency_codes' => 'array', 'rtp_options' => 'array', 'extra' => 'array']);
    }

    public static function makeCode(string $brandCode, int $id): string
    {
        return strtolower($brandCode) . '_' . id2big($id);
    }

    public function brand()
    {
        return $this->belongsTo(GameBrand::class, 'brand_id');
    }

}
