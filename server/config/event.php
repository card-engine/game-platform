<?php

return [
    'mgs.recharge.paid' => [app\service\telegram\handler\RechargeHandler::class, 'paid'],
    'mgs.tron.status' => [app\service\telegram\handler\SystemHandler::class, 'scanChanged'],
];
