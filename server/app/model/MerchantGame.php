<?php

namespace app\model;

use plugin\saiadmin\basic\eloquent\BaseModel;

class MerchantGame extends BaseModel
{
    protected $table = 'mg_merchant_games';
    protected $primaryKey = 'id';

    public function game()
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
