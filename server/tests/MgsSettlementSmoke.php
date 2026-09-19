<?php

use app\logic\mgs\SettlementLogic;
use app\model\Merchant;
use app\model\MerchantCredit;
use app\model\MerchantMonthlyBill;
use app\model\mgs\Settlement;
use app\service\game\report\MonthlyBillingService;
use app\service\mgs\MgsSettlementService;
use support\Db;
use Webman\Config;

$argv = $_SERVER['argv'] ??= [];
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';
if (config('database.connections.mysql.database') !== '__mgs_ui_test') throw new RuntimeException('仅运行于隔离测试库');

if (PHP_SAPI === 'cli-server') {
    $body = json_decode(file_get_contents('php://input'), true);
    $req = new support\Request("POST /open_api/monthly-bills HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n\r\n" . json_encode($body));
    $controller = new app\controller\openapi\OpenApiController();
    try {
        $response = (new app\middleware\MerchantAuth())->process($req, fn ($req) => str_ends_with($_SERVER['REQUEST_URI'], '/generate') ? $controller->generateMonthlyBills($req) : $controller->monthlyBills($req));
        header('Content-Type: application/json');echo $response->rawBody();
    } catch (Throwable $e) { header('Content-Type: application/json');echo json_encode(['code'=>400,'message'=>$e->getMessage()]); }
    return;
}
function checkSettlement(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejectsSettlement(callable $action, string $message): void {
    try { $action(); } catch (Throwable $e) { checkSettlement(str_contains($e->getMessage(), $message), $e->getMessage()); return; }
    throw new RuntimeException('未拒绝：' . $message);
}
$port = 20000 + getmypid() % 20000;
$settings = Config::get();$settings['mgs']['platform_url'] = "http://127.0.0.1:{$port}";
(new ReflectionProperty(Config::class, 'config'))->setValue(null, $settings);
(new ReflectionProperty(Config::class, 'flatCache'))->setValue(null, []);
(new app\service\mgs\MgsConfigService())->rebuild();
$merchant = Merchant::where('mch_id', (new app\service\mgs\MgsConfigService())->get('game_platform_mch_id'))->firstOrFail();
$month = (new DateTimeImmutable('first day of last month', new DateTimeZone($merchant->timezone)))->format('Y-m');
$process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __FILE__], [STDIN, ['file','/tmp/mgs-tron-design-test/settlement-mock.log','a'], ['file','/tmp/mgs-tron-design-test/settlement-mock.log','a']], $pipes);
$currencies=['TST','TSZ'];
try {
    for ($i=0;$i<50;$i++) { if($sock=@fsockopen('127.0.0.1',$port)){fclose($sock);break;}usleep(20000); }
    foreach ($currencies as $index => $currency) {
        MerchantCredit::create(['merchant_id'=>$merchant->id,'currency_code'=>$currency,'status'=>1,'available_amount'=>'98.8','payable_amount'=>$index?'0':'1.2']);
        MerchantMonthlyBill::create(['merchant_id'=>$merchant->id,'bill_no'=>mg_no('MF'),'billing_mode'=>1,'metric_type'=>0,'metric_value'=>0,'billing_month'=>(new DateTimeImmutable($month.'-01'))->modify('+1 month')->format('Y-m-d'),'source_month'=>$month.'-01','currency_code'=>$currency,'amount'=>$index?'0':'1.2','ggr_amount'=>$index?'-30':'40','rules_snapshot'=>['timezone'=>$merchant->timezone,'rates'=>[['merchant_rate_value'=>'0.03','ggr_amount'=>'100','bet_amount'=>'100','win_amount'=>'0'],['merchant_rate_value'=>'0.03','ggr_amount'=>'-60','bet_amount'=>'10','win_amount'=>'70']]]]);
    }
    $service = new MgsSettlementService();
    rejectsSettlement(fn()=> $service->generate(gmdate('Y-m')), '已结束');
    checkSettlement($service->generate($month)===2,'未生成两个币种的正式结算');
    $rows=Settlement::where('settlement_month',$month)->whereIn('currency_code',$currencies)->get()->keyBy('currency_code');
    checkSettlement($rows['TST']->platform_fee==='1.20000000','错误使用逐笔预估费用');
    $operator = new SettlementLogic();$operator->init(['id'=>999999]);
    rejectsSettlement(fn()=> $operator->confirm($rows['TST']->id,'test'), '超管');
    $logic = new SettlementLogic();$logic->init(['id'=>1]);
    rejectsSettlement(fn()=> $logic->pay($rows['TST']->id,'test',gmdate('Y-m-d H:i:s'),'test'), '先确认');
    $logic->confirm($rows['TST']->id,'test confirm');
    checkSettlement((int)$rows['TST']->fresh()->status===1,'未确认');
    $bill=MerchantMonthlyBill::where(['merchant_id'=>$merchant->id,'currency_code'=>'TST','source_month'=>$month.'-01'])->firstOrFail();
    $bill->update(['ggr_amount'=>'50']);
    rejectsSettlement(fn()=> $logic->pay($rows['TST']->id,'test',gmdate('Y-m-d H:i:s'),'test'), '已变化');
    checkSettlement((int)$rows['TST']->fresh()->status===1,'拒绝付款后结算状态被修改');
    $logic->reopen($rows['TST']->id,'source changed');
    $service->generate($month);
    $logic->confirm($rows['TST']->id,'recheck');
    $time=gmdate('Y-m-d H:i:s',time()-30);
    $logic->pay($rows['TST']->id,'test-payment',$time,'test');
    $logic->pay($rows['TST']->id,'test-payment',$time,'test');
    $credit=MerchantCredit::where(['merchant_id'=>$merchant->id,'currency_code'=>'TST'])->firstOrFail();
    checkSettlement($credit->payable_amount==='0.00000000','未核销应付');
    checkSettlement(Db::table('mg_merchant_bills')->where('credit_id',$credit->id)->count()===1,'重复付款流水');
    checkSettlement((int)$bill->fresh()->status===1 && (int)$rows['TST']->fresh()->status===2,'两边付款状态不一致');
    $zeroCredit=MerchantCredit::where(['merchant_id'=>$merchant->id,'currency_code'=>'TSZ'])->firstOrFail();
    $logic->confirm($rows['TSZ']->id,'zero fee');
    checkSettlement((int)$rows['TSZ']->fresh()->status===2 && !Db::table('mg_merchant_bills')->where('credit_id',$zeroCredit->id)->exists(),'零费用未结清或制造资金流水');
    echo "PASS: 正式月费来源、权限、确认/撤回/付款、变化检测、重复核销、零费用结清\n";
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    $ids=MerchantCredit::where('merchant_id',$merchant->id)->whereIn('currency_code',$currencies)->pluck('id');
    Db::table('mg_merchant_bills')->whereIn('credit_id',$ids)->delete();
    Db::table('mg_merchant_credits')->whereIn('id',$ids)->delete();
    Db::table('mg_merchant_monthly_bills')->where('merchant_id',$merchant->id)->whereIn('currency_code',$currencies)->delete();
    Db::table('mgs_settlements')->whereIn('currency_code',$currencies)->delete();
}
