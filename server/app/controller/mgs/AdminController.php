<?php

namespace app\controller\mgs;

use app\logic\mgs\MgsLogic;
use app\service\mgs\MgsSettlementService;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;
use Webman\RedisQueue\Redis;

class AdminController extends BaseController
{
    public function __construct()
    {
        $this->logic = new MgsLogic();
        parent::__construct();
    }

    #[Permission('自营平台概览', 'app:mgs:overview:index')]
    public function overview(): Response
    {
        return $this->success($this->logic->overview());
    }

    #[Permission('自营游戏列表', 'app:mgs:game:index')]
    public function games(Request $request): Response
    {
        return $this->success($this->logic->games($request->more([['status', ''], ['keyword', '']])));
    }

    #[Permission('同步自营游戏', 'app:mgs:game:sync')]
    public function sync(): Response
    {
        Redis::send('mgs_game_sync', []);
        return $this->success(['queued' => true]);
    }

    #[Permission('启停自营游戏', 'app:mgs:game:update')]
    public function status(Request $request): Response
    {
        $this->logic->gameStatus((int) $request->post('id'), (int) $request->post('status'));
        return $this->success('操作成功');
    }

    #[Permission('配置自营游戏', 'app:mgs:game:update')]
    public function config(Request $request): Response
    {
        $this->logic->gameConfig((int) $request->post('id'), $request->post());
        return $this->success('操作成功');
    }

    #[Permission('自营用户列表', 'app:mgs:user:index')]
    public function users(Request $request): Response
    {
        return $this->success($this->logic->users($request->more([['status', ''], ['keyword', '']])));
    }

    #[Permission('自营注单列表', 'app:mgs:bet:index')]
    public function bets(Request $request): Response
    {
        return $this->success($this->logic->monthly('bets', $request->more([['date_start', ''], ['date_end', ''], ['status', ''], ['keyword', '']])));
    }

    #[Permission('自营流水列表', 'app:mgs:bill:index')]
    public function bills(Request $request): Response
    {
        return $this->success($this->logic->monthly('bills', $request->more([['date_start', ''], ['date_end', ''], ['type', ''], ['status', ''], ['keyword', '']])));
    }

    #[Permission('自营日报', 'app:mgs:report:index')]
    public function reports(Request $request): Response
    {
        return $this->success($this->logic->reports($request->more([['date_start', ''], ['date_end', ''], ['currency_code', ''], ['keyword', '']])));
    }

    #[Permission('自营结算列表', 'app:mgs:settlement:index')]
    public function settlements(Request $request): Response
    {
        return $this->success($this->logic->settlements($request->more([['settlement_month', ''], ['currency_code', ''], ['status', '']])));
    }

    #[Permission('生成自营结算', 'app:mgs:settlement:update')]
    public function generateSettlement(Request $request): Response
    {
        return $this->success(['count' => (new MgsSettlementService())->generate($request->post('month'))]);
    }
}
