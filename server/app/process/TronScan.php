<?php

namespace app\process;

use app\service\mgs\TronScanService;
use support\Log;
use Workerman\Timer;
use Webman\Event\Event;

class TronScan
{
    private bool $started = false;
    private bool $busy = false;
    private int $errorTime = 0;

    public function onWorkerStart(): void
    {
        if (!config('mgs.recharge_tron_receive_address')) return;
        Timer::add(3, function () {
            if ($this->busy) return;
            $this->busy = true;
            try {
                $scan = new TronScanService();
                if (!$this->started) {
                    $scan->start();
                    $this->started = true;
                }
                $scan->batch();
                $status = $scan->status();
                if ($status['health'] && $status['checkpoint']['next_block_number'] < $status['health']['solid_number'] - 20) {
                    $scan->start();
                } elseif ($this->errorTime && $status['ready']) {
                    Event::emit('mgs.tron.status', ['ready' => true]);
                    $this->errorTime = 0;
                }
            } catch (\Throwable $error) {
                if (!$this->errorTime) Event::emit('mgs.tron.status', ['ready' => false, 'error_type' => $error::class]);
                if (time() - $this->errorTime >= 60) {
                    Log::warning('TRON扫描暂未完成', ['error_type' => $error::class]);
                    $this->errorTime = time();
                    $this->started = false;
                }
            } finally {
                $this->busy = false;
            }
        });
    }
}
