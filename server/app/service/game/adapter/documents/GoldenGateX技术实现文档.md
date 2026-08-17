# GoldenGateX 技术实现文档

本文由旧项目 GoldenGateX 文档迁移，并已按当前 MG Webman 架构修订。

## 1. 当前实现

- Adapter：`app\service\game\adapter\platforms\GoldenGateXAdapter`
- 配置：`server/config/game_platforms.php` 的 `platforms.goldengatex`
- Bearer Token：已实现缓存
- 品牌同步：`GET /vendors/list`
- 游戏同步：`POST /games/list`、`GET /games/mini/list`
- 获取进游地址：`POST /game/launch-url`
- RTP：查询和设置单个玩家 RTP
- 回调：`balance`、`transaction`、`batch-transactions`

GoldenGateX 是多厂商聚合平台。每个 `vendorCode` 在 MG 中保存为独立品牌资源，可映射到统一品牌。

## 2. 配置

```dotenv
GAME_GOLDENGATEX_URL=
GAME_GOLDENGATEX_CLIENT_ID=
GAME_GOLDENGATEX_CLIENT_SECRET=
GAME_GOLDENGATEX_CURRENCY=USD
```

`client_id` 和 `client_secret` 同时用于获取 Bearer Token和验证回调 Basic Auth。密钥不得写入日志或文档。

## 3. Token

```text
POST {url}/auth/createtoken
```

请求体包含 `clientId` 和 `clientSecret`。Token 缓存到官方 `expiration` 前 60 秒，避免每次请求重复创建。

## 4. GoldenGateX 后台回调配置

```text
Balance           https://mgames.im/provider/goldengatex/balance
Transaction       https://mgames.im/provider/goldengatex/transaction
Batch Transaction https://mgames.im/provider/goldengatex/batch-transactions
```

回调使用：

```text
Authorization: Basic BASE64(client_id:client_secret)
Content-Type: application/json
```

## 5. 交易映射

| GoldenGateX 数据 | MG 标准动作 |
| --- | --- |
| `amount < 0` | `debit`，金额取绝对值 |
| `amount >= 0` | `credit` |
| `isCanceled=true` 且原金额小于 0 | `rollback_debit` |
| `isCanceled=true` 且原金额大于等于 0 | `rollback_credit` |
| `isFinished=true` | 结算当前注单 |

`transactionCode` 是资金动作来源号，`historyId` 是父局号，`roundId` 是局号。批量回调按原顺序逐条交给统一交易服务，任一动作失败即返回失败。

成功响应：

```json
{
  "success": true,
  "message": 100.25,
  "errorCode": 0
}
```

`message` 在商户通知成功时为最新余额。

## 6. 品牌与游戏同步

1. `/vendors/list` 获取品牌资源。
2. 逐个 `vendorCode` 调用 `/games/list`。
3. `/games/mini/list` 补充原生小游戏。
4. 游戏按 `vendorCode + gameCode` 去重。
5. 某个品牌同步失败时保留已有游戏，不批量误下架。

落库映射：

| 上游字段 | MG 字段 |
| --- | --- |
| `vendorCode` | `mg_game_brands.provider_brand_code` |
| `gameCode` | `mg_games.provider_game_code` |
| `gameName` | `mg_games.name` |
| `thumbnail` | `mg_games.icon_url` |
| `provider`、`underMaintenance` | `mg_games.extra` |

## 7. 进游

```text
POST {url}/game/launch-url
```

发送 `vendorCode`、`gameCode`、MG 玩家编号、两位语言码和可选 `lobbyUrl`。返回值直接作为一次性游戏地址。

## 8. RTP

- 查询：`POST /game/user/get-rtp`
- 设置：`POST /game/user/set-rtp`
- 当前允许整数 RTP 30-99。
- 商户只可操作已同步、已授权且支持 RTP 的游戏和本商户玩家。

## 9. 运行约束

- 金额始终以十进制字符串进入交易服务。
- Token、Authorization 和密钥不得写入日志。
- HTTP 429、网络超时和未知响应不得无限重试。
- 重复交易必须返回原 MG 处理结果。
- 旧项目的 `PlatformService`、`PlayService` 和 `/app/goldengatex/api/*` 路径不再适用。
