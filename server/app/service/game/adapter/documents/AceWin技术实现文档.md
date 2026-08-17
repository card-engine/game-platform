# AceWin 技术实现文档

本文由旧项目 AceWin 文档迁移，并已按当前 MG Webman 架构修订。

## 1. 当前实现

- Adapter：`app\service\game\adapter\platforms\AceWinAdapter`
- 通用协议基类：`app\service\game\adapter\AgentAdapter`
- 配置：`server/config/game_platforms.php` 的 `platforms.acewin`
- 游戏同步：`GET /api3/GetGameList`
- 获取进游地址：`GET /singleWallet/LoginWithoutRedirect`
- 回调：`auth`、`bet`、`cancelBet`
- 资金模式：Seamless Single Wallet

AceWin 在一次 `bet` 回调中同时传入下注额和派彩额。Adapter 将其拆成 MG 的 `debit` 和 `credit` 两个标准动作；`cancelBet` 分别回滚派奖和下注。

## 2. 配置

```dotenv
GAME_ACEWIN_URL=
GAME_ACEWIN_AGENT_ID=
GAME_ACEWIN_AGENT_KEY=
GAME_ACEWIN_BASIC_AUTH_USERNAME=
GAME_ACEWIN_BASIC_AUTH_PASSWORD=
```

Basic Auth 用户名和密码同时为空时不校验；生产环境应配置 Basic Auth，并在网关限制 AceWin 回调来源 IP。

## 3. 主动请求签名

```text
KeyG = MD5(yyMMd + AgentId + AgentKey)
Key = 000000 + MD5(queryString + KeyG) + 000000
```

- 日期固定使用 UTC-4，代码时区为 `America/Puerto_Rico`。
- `queryString` 按接口规定字段顺序拼接，不按字母排序。
- 每次请求自动追加 `AgentId` 和 `Key`。
- `ErrorCode=0` 表示成功，数据位于 `Data`。

当前签名字段：

| 接口 | 字段顺序 |
| --- | --- |
| `LoginWithoutRedirect` | `Token, GameId, Lang, AgentId` |
| `GetGameList` | `AgentId`，`IconSize` 不参与签名 |

## 4. AceWin 后台回调配置

```text
Auth       https://mgames.im/provider/acewin/auth
Bet        https://mgames.im/provider/acewin/bet
CancelBet  https://mgames.im/provider/acewin/cancelBet
```

回调使用 `POST`。`auth` 和 `bet` 使用进游 token 定位玩家；`cancelBet` 在 token 失效时可使用 `userId` 定位。

## 5. Token

```text
payload = player_id + "|" + expires_at
token = HEX(payload) + HMAC_SHA256(payload, GAME_SECRET_KEY)
```

Token 有效期 24 小时，只包含十六进制字符。MG 不在 token 中暴露商户玩家真实主键。

## 6. 回调映射

| AceWin 动作 | MG 标准动作 | 说明 |
| --- | --- | --- |
| `auth` | 查询余额 | 返回玩家编号、币种、余额和 token |
| `bet` | `debit` + `credit` | `betAmount` 扣款，`winloseAmount` 加款 |
| `cancelBet` | `rollback_credit` + `rollback_debit` | 整笔撤销 |

`round`、`gameNo` 和 `reqId` 用于局号、父局号及幂等。最终资金结果统一由 `TradeService` 写入 MG 月注单和月流水表。

## 7. 游戏同步

AceWin 当前作为单一品牌资源保存：

```text
platform_code = acewin
provider_brand_code = acewin
```

游戏使用 `GameId` 作为上游编码，名称与图片从多语言字段和 `Icon` 读取。当前只标记 SC 币种，不声明 RTP 能力。

## 8. 运行约束

- 一次性进游地址不缓存。
- 所有金额先转为十进制字符串，再进入统一交易服务。
- 上游回调成功只表示商户通知接口已给出确定成功结果。
- 网络超时进入结果未知，不能生成新 MG 交易号重试。
- 旧项目的 `PlatformService`、`PlayService`、`app_games` 和 Laravel 命令均不再适用。
