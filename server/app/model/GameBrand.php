<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class GameBrand extends BaseModel
{
    protected $table = 'mg_game_brands';
    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['names' => 'array', 'extra' => 'array', 'is_gc' => 'boolean']);
    }

    public function games()
    {
        return $this->hasMany(Game::class, 'brand_id');
    }

    public function uniqueBrand()
    {
        return $this->belongsTo(UniqueBrand::class, 'unique_brand_id');
    }
}
