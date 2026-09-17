<?php

namespace app\queue\redis\parallel;

use app\logic\mgs\TransferLogic;
use RuntimeException;
use Webman\RedisQueue\Consumer;

class RechargeCredit implements Consumer
{
    public string $queue = 'mgs_recharge_credit';
    public string $connection = 'default';

    public function consume($data): void
    {
        try {
            (new TransferLogic())->credit((int) $data['transfer_id']);
        } catch (\Throwable $error) {
            throw new RuntimeException('充值入账失败，收款ID=' . (int) $data['transfer_id'] . '，类型=' . $error::class);
        }
    }
}
