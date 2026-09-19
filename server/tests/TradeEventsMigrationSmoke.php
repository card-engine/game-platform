<?php

use app\command\TradeEventsCommand;
use app\service\game\trade\TradeService;
use app\service\mgs\MgsCallbackService;
use support\Db;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';
if (config('database.connections.mysql.database') !== '__mgs_ui_test') throw new RuntimeException('仅运行于隔离测试库');
function migrationCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
(new app\service\mgs\MgsTableService())->recent();
(new app\service\game\trade\MonthlyTableService())->precreate();
$month = gmdate('ym');$suffix = bin2hex(random_bytes(5));
$mgUser = app\model\User::findOrFail(1);$mgsUser = app\model\mgs\User::findOrFail(1);
$mgGame = app\model\Game::firstOrFail();
$mgsGame = app\model\mgs\Game::firstOrCreate(['platform_game_id'=>(string)id2big($mgGame->id)], ['platform_game_code'=>'test','platform_brand_code'=>'test','brand_id'=>1,'name'=>'Test','currency_codes'=>['USD'],'status'=>1]);
$mgBet = 'BT'.$month.'01000000'.$suffix;$mgsBet = 'MB'.$month.'01000000'.$suffix;
$mgBill = 'BL'.$month.'01000000'.$suffix;$mgsBill = 'ML'.$month.'01000000'.$suffix;
$source = 'zero-migration-'.$suffix;
$operation = ['action'=>'credit','player_id'=>'mg_'.id2big($mgUser->id).'_usd','source_no'=>$source,'round_id'=>$source,'game_code'=>$mgGame->provider_game_code,'amount'=>'0.00000000','finished'=>true];
$params = ['user_id'=>(string)$mgsUser->unique_id,'currency'=>'USD','game_id'=>(string)id2big($mgGame->id),'transaction_id'=>$source,'round_id'=>$source,'win_amount'=>'0.00000000','is_end'=>1];
ksort($params);
$now=gmdate('Y-m-d H:i:s');$wallet=app\model\mgs\Wallet::where(['user_id'=>$mgsUser->id,'currency_code'=>'USD'])->firstOrFail();$balance=$wallet->balance;$version=$wallet->version;
try {
    Db::table('mg_bets_'.$month)->insert(['bet_no'=>$mgBet,'merchant_id'=>$mgUser->merchant_id,'user_id'=>$mgUser->id,'game_id'=>$mgGame->id,'brand_id'=>$mgGame->brand_id,'platform_code'=>'wxgame','currency_code'=>'USD','round_key'=>hash('sha256',$source),'provider_round_id'=>$source,'business_date'=>gmdate('Y-m-d'),'platform_date'=>gmdate('Y-m-d'),'status'=>2,'settled_time'=>$now,'credit_count'=>1,'actions'=>json_encode([['bill_no'=>$mgBill,'type'=>'credit','amount'=>'0','source_no'=>$source]]),'create_time'=>$now,'update_time'=>$now]);
    Db::table('mgs_bets_'.$month)->insert(['bet_no'=>$mgsBet,'user_id'=>$mgsUser->id,'game_id'=>$mgsGame->id,'currency_code'=>'USD','round_key'=>hash('sha256',$source),'platform_round_id'=>$source,'business_date'=>gmdate('Y-m-d'),'platform_date'=>gmdate('Y-m-d'),'status'=>2,'settled_time'=>$now,'actions'=>json_encode([['type'=>'win','amount'=>'0','is_end'=>1,'transaction_id'=>$source]]),'create_time'=>$now,'update_time'=>$now]);
    Db::table('mg_bills_'.$month)->insert(['bill_no'=>$mgBill,'bet_no'=>$mgBet,'merchant_id'=>$mgUser->merchant_id,'user_id'=>$mgUser->id,'game_id'=>$mgGame->id,'type'=>2,'source'=>'wxgame','source_no'=>$source,'amount'=>'0','currency_code'=>'USD','idempotency_key'=>hash('sha256',"credit|{$mgUser->id}|USD|{$source}"),'request_hash'=>hash('sha256',json_encode($operation,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'status'=>2,'data'=>json_encode(['request'=>$operation,'wallet_response'=>['code'=>0,'message'=>'success','data'=>['balance'=>$balance,'balance_after'=>$balance]]]),'business_date'=>gmdate('Y-m-d'),'platform_date'=>gmdate('Y-m-d'),'received_time'=>$now,'create_time'=>$now,'update_time'=>$now]);
    Db::table('mgs_bills_'.$month)->insert(['bill_no'=>$mgsBill,'bet_no'=>$mgsBet,'user_id'=>$mgsUser->id,'game_id'=>$mgsGame->id,'type'=>'win','direction'=>1,'transaction_id'=>$source,'amount'=>0,'currency_code'=>'USD','before_balance'=>$balance,'after_balance'=>$balance,'status'=>2,'request_hash'=>hash('sha256',json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'data'=>json_encode($params),'create_time'=>$now,'update_time'=>$now]);
    $unknown = (array) Db::table('mg_bills_'.$month)->where('bill_no', $mgBill)->first();
    unset($unknown['id']);
    $unknown['bill_no'] = 'BL'.$month.'02000000'.$suffix;
    $unknown['source_no'] = 'unknown-'.$source;
    $unknown['idempotency_key'] = hash('sha256', $unknown['source_no']);
    $unknown['amount'] = '2.00000000';
    $unknown['status'] = 4;
    Db::table('mg_bills_'.$month)->insert($unknown);
    $command=new CommandTester(new TradeEventsCommand());migrationCheck($command->execute([])===0,$command->getDisplay());
    migrationCheck(Db::table('mg_bills_'.$month)->where('bill_no',$mgBill)->exists(),'预览删除了流水');
    foreach(range(1,2) as $i) migrationCheck($command->execute(['--apply'=>true])===0,$command->getDisplay());
    migrationCheck(Db::table('mg_trade_events_'.$month)->where('source_no', $unknown['source_no'])->value('bill_no') === $unknown['bill_no'], '待重试非零事件丢失预留流水号');
    migrationCheck(!Db::table('mg_bills_'.$month)->where('bill_no',$mgBill)->exists() && !Db::table('mgs_bills_'.$month)->where('bill_no',$mgsBill)->exists(),'零流水没有物理删除');
    migrationCheck(Db::table('mg_trade_events_'.$month)->where('source_no',$source)->count()===1 && Db::table('mgs_trade_events_'.$month)->where('transaction_id',$source)->count()===1,'事件凭据未保留或重复');
    migrationCheck((int)Db::table('mg_bets_'.$month)->where('bet_no',$mgBet)->value('credit_count')===0,'零派奖仍计入资金流水笔数');
    migrationCheck((new TradeService())->handle('wxgame',$operation)['status']===2,'旧零交易重试失败');
    migrationCheck((new MgsCallbackService())->handle('win',$params)['balance']===$balance,'MGS旧零交易重试失败');
    migrationCheck($wallet->fresh()->balance===$balance && $wallet->fresh()->version===$version,'清理改变了钱包');
    echo "PASS: 清理预览、两套零流水物理删除、事件移交、重复执行、旧请求重放及钱包不变\n";
} finally {
    foreach(['mg','mgs'] as $prefix) {
        Db::table($prefix.'_bills_'.$month)->where('bet_no',$prefix==='mg'?$mgBet:$mgsBet)->delete();
        Db::table($prefix.'_trade_events_'.$month)->where('bet_no',$prefix==='mg'?$mgBet:$mgsBet)->delete();
        Db::table($prefix.'_bets_'.$month)->where('bet_no',$prefix==='mg'?$mgBet:$mgsBet)->delete();
    }
}
