# TADA 接入方案

本文由旧项目 TADA 文档迁移，并已按当前 MG Webman 架构修订。TADA 与 JILI 使用同类 SSS 协议，但在 MG 中作为独立游戏平台处理。

## 1. 当前实现

- Adapter：`app\service\game\adapter\platforms\TadaAdapter`
- 通用协议基类：`app\service\game\adapter\AgentAdapter`
- 游戏同步：`POST /sss/GetGameList`
- 获取进游地址：`POST /singleWallet/LoginWithoutRedirect`
- 回调：`auth`、`bet`、`cancelBet`
- 市场模式：原生支持 SC/GC 双币

TADA 的 `bet` 一次传入下注与派彩，Adapter 拆成 MG `debit` 和 `credit`；`cancelBet` 分别回滚派奖和下注。

## 2. 官方资料

- API Manual：<https://wbgame.rsne4d5q.com/sss-doc/SSS_Seamless_API_Manual_TW.html>
- FAQ：<https://wbgame.rsne4d5q.com/sss-doc/SSS_Seamless_FAQ_TW.html>
- Onboarding Guide：<https://wbgame.rsne4d5q.com/sss-doc/SSS_Seamless_Onboarding_Guide_TW.html>

## 3. 配置

```dotenv
GAME_TADA_URL=
GAME_TADA_AGENT_ID=
GAME_TADA_AGENT_KEY=
GAME_TADA_CURRENCY=SC
GAME_TADA_BASIC_AUTH_USERNAME=
GAME_TADA_BASIC_AUTH_PASSWORD=
```

`GAME_TADA_URL` 必须保留 API 根路径；代码会统一补一个末尾 `/`。生产环境建议启用 Basic Auth 和来源 IP 限制。

## 4. 主动请求签名

```text
KeyG = MD5(yyMMd + AgentId + AgentKey)
Key = 000000 + MD5(queryString + KeyG) + 000000
```

- 日期固定使用 UTC-4。
- 请求使用 `application/x-www-form-urlencoded`。
- `queryString` 按接口字段顺序拼接。
- `ErrorCode=0` 表示成功，数据位于 `Data`。

当前签名字段：

| 接口 | 字段顺序 |
| --- | --- |
| `LoginWithoutRedirect` | `Token, GameId, Lang, AgentId` |
| `GetGameList` | `Currency, AgentId` |

## 5. TADA 后台回调配置

```text
Auth       https://mgames.im/provider/tada/auth
Bet        https://mgames.im/provider/tada/bet
CancelBet  https://mgames.im/provider/tada/cancelBet
```

全部使用 `POST`。回调可配置 HTTP Basic Authentication。

## 6. 双币规则

- 美国双币模式可同时启用 SC 和 GC。
- SC 对应真金结算币种。
- GC 是金币币种，可配置 `1 USD = N GC` 的展示比例。
- GC 记录完整下注、派奖、回滚和 GGR，但不产生商户结算费用。
- SC 与 GC 共用游戏平台和游戏资源，不复制业务表。

## 7. 回调映射

| TADA 动作 | MG 标准动作 | 说明 |
| --- | --- | --- |
| `auth` | 查询余额 | token 定位玩家和币种 |
| `bet` | `debit` + `credit` | `transactionId` 优先作为资金来源号 |
| `cancelBet` | `rollback_credit` + `rollback_debit` | 回滚原局 |

`round` 作为父局号，`transactionId` 作为资金动作的来源号。`isGameEnd` 对应 MG 终局结算语义。

## 8. 游戏同步

TADA 当前作为单一品牌资源保存，游戏编码使用 `GameId`。同步后游戏币种标记为 `SC, GC`，图片使用 TADA 公开资源地址。

## 9. 运行约束

- AgentId 必须原样使用官方分配值。
- 进游地址只能使用一次，不缓存。
- Token 使用 MG 内部 HMAC，有效期 24 小时。
- 重复请求使用原上游来源号返回原结果。
- 旧项目的 Laravel Service、Artisan 命令和旧回调路径不再适用。
