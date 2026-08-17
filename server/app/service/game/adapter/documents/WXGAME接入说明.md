# WXGAME 接入说明

本文说明 WXGAME 在 MG 项目中的实现方式。上游协议的中文 Markdown 版本见同目录的《WXGAME API文档》；当前代码行为以 `WxGameAdapter` 为准。

## 1. 当前实现

- Adapter：`app\service\game\adapter\platforms\WxGameAdapter`
- 配置：`server/config/game_platforms.php` 的 `platforms.wxgame`
- 游戏同步：`POST /v1/api/get_game_list`
- 获取进游地址：`POST /v1/api/get_game_url`
- 查询玩家 RTP：`POST /v1/api/get_player_rtp`
- 设置玩家 RTP：`POST /v1/api/set_player_rtp`
- 资金模式：无缝钱包

WXGAME 本身不是双币平台。MG 使用 USD 或 SC 时选择普通账号，使用 GC 时选择 GC 账号；玩家编号末尾始终保留商户实际使用的钱包编码。

## 2. 配置

```dotenv
GAME_WXGAME_URL=
GAME_WXGAME_APP_ID=
GAME_WXGAME_APP_KEY=
GAME_WXGAME_APP_SECRET=

GAME_WXGAME_GC_URL=
GAME_WXGAME_GC_APP_ID=
GAME_WXGAME_GC_APP_KEY=
GAME_WXGAME_GC_APP_SECRET=
GAME_WXGAME_GC_CURRENCY=WST
```

密钥只能放在 `.env`，禁止写入文档、日志或数据库明文字段。

## 3. 主动请求签名

请求头：

```text
AccessKeyId: {app_key}
Nonce: {随机字符串}
Timestamp: {Unix 秒级时间戳}
Sign: SHA256(app_secret + nonce + timestamp)
Content-Type: application/json
```

成功响应满足 `code=0`，业务数据位于 `data`。

## 4. WXGAME 后台回调配置

```text
验证地址  https://mgames.im/provider/wxgame/verify
余额地址  https://mgames.im/provider/wxgame/balance
下注地址  https://mgames.im/provider/wxgame/bet
派奖地址  https://mgames.im/provider/wxgame/win
退款地址  https://mgames.im/provider/wxgame/refund
```

全部使用 `POST application/json`。生产网关必须把 `/provider/*` 原样转发到 Webman。

## 5. 回调映射

| WXGAME 动作 | MG 标准动作 | 幂等来源 |
| --- | --- | --- |
| `verify` | 验证 token 并查询余额 | token |
| `balance` | 查询余额 | 不写玩家流水 |
| `bet` | `debit` | `transactionId` |
| `win` | `credit` | `transactionId` |
| `refund` | `rollback_debit` | `transactionId`，关联 `betTransactionId` |

平台玩家编号格式为 `mg_{user_id转换值}_{currency}`。WXGAME 只看到该编号，不接触商户真实玩家主键。

## 6. RTP

当前支持档位：

```text
50, 65, 75, 85, 90, 95, 97, 100, 150
```

商户只能通过 MG OpenAPI 调整已授权且标记支持 RTP 的游戏。MG 将商户玩家编号转换为上游玩家编号后调用 WXGAME。

## 7. 运行约束

- 金额进入统一交易服务前保持十进制字符串。
- 重复资金请求由 `平台 + 玩家 + 动作 + transactionId` 保证幂等。
- 商户通知超时记为结果未知，必须使用原 `transaction_id` 重试。
- `/refund` 可以部分退款；退款总额不得超过原扣款剩余金额。
- 官方 PDF 的测试域名和示例账号只作协议参考，环境参数以当前 `.env` 为准。
