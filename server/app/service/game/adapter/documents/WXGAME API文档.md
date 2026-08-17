# WXGAME API 文档

本文根据 WXGAME 官方 `WX_GAME_Solution_v060508` 文档整理为中文 Markdown。接口地址、字段名和错误码保持与上游协议一致，示例统一改为标准 JSON，便于开发和联调。

## 1. 接入约定

### 1.1 请求地址

| 环境 | 地址 |
| --- | --- |
| 测试环境 | `https://test-openapi.cpigame.com` |
| 生产环境 | 联系 WXGAME 商务获取 |

- 请求方式：`POST`
- 请求和响应格式：`application/json`
- 字符编码：`UTF-8`
- `${OperationApiDomain}` 表示运营商提供给 WXGAME 的回调域名。

### 1.2 公共请求头

运营商调用 WXGAME 接口时需要携带以下请求头：

| 参数 | 说明 | 必填 | 类型 |
| --- | --- | --- | --- |
| `AccessKeyId` | 商户 Key | 是 | string |
| `Sign` | 请求签名 | 是 | string |
| `Nonce` | 随机字符串 | 是 | string |
| `Timestamp` | Unix 秒级时间戳，60 秒内有效 | 是 | int64 |

### 1.3 签名规则

1. 按顺序拼接 `AccessKeySecret + Nonce + Timestamp`。
2. 对拼接结果计算 SHA-256。
3. 将摘要编码为十六进制字符串，得到 `Sign`。

```text
parameter = AccessKeySecret + Nonce + Timestamp
Sign = Hex(SHA256(parameter))
```

签名不包含请求体。

### 1.4 通用响应

| 参数 | 说明 | 类型 | 示例 |
| --- | --- | --- | --- |
| `code` | 响应码，`0` 表示成功 | integer | `0` |
| `data` | 响应数据 | object/string/null | `{}` |
| `msg` | 响应描述 | string | `success` |
| `requestId` | 本次请求 ID | string | `8ae1d7bb473c75a3caf4d0be5faadfea` |

```json
{
  "code": 0,
  "data": {},
  "msg": "success",
  "requestId": "8ae1d7bb473c75a3caf4d0be5faadfea"
}
```

## 2. 接口列表

| 接口 | 请求地址 | 提供方 |
| --- | --- | --- |
| 获取游戏链接 | `POST /v1/api/get_game_url` | WXGAME |
| 验证玩家信息 | `POST ${OperationApiDomain}/verify` | 运营商 |
| 获取玩家余额 | `POST ${OperationApiDomain}/balance` | 运营商 |
| 下注 | `POST ${OperationApiDomain}/bet` | 运营商 |
| 派奖 | `POST ${OperationApiDomain}/win` | 运营商 |
| 撤销 | `POST ${OperationApiDomain}/refund` | 运营商 |
| 设置玩家 RTP | `POST /v1/api/set_player_rtp` | WXGAME |
| 获取玩家 RTP | `POST /v1/api/get_player_rtp` | WXGAME |
| 获取游戏列表 | `POST /v1/api/get_game_list` | WXGAME |

## 3. 获取游戏链接

```text
POST /v1/api/get_game_url
```

### 请求参数

| 参数 | 说明 | 必填 | 类型 | 示例 |
| --- | --- | --- | --- | --- |
| `token` | 运营商生成的临时玩家令牌 | 是 | string | `33445566` |
| `gameId` | 游戏 ID | 是 | string | `171` |
| `gameBrand` | 游戏品牌 | 是 | string | `jili` |
| `language` | 语言代码 | 是 | string | `en-US` |

`token` 由运营商自行生成。WXGAME 收到进游请求后，会携带此令牌回调运营商的 `/verify` 接口换取玩家信息。

```json
{
  "token": "33445566",
  "gameId": "171",
  "gameBrand": "jili",
  "language": "en-US"
}
```

### 响应数据

成功时 `data` 直接返回游戏链接字符串。

| 参数 | 说明 | 类型 |
| --- | --- | --- |
| `data` | 游戏链接 | string |

```json
{
  "code": 0,
  "data": "https://example-game.test/launch?ssoKey=2e50fa6e8a2c4953aa8f28d5669896e5&gameId=171&lang=en-US",
  "msg": "success",
  "requestId": "8ae1d7bb473c75a3caf4d0be5faadfea"
}
```

可能返回 `1006` 无效的玩家令牌、`1007` 玩家令牌已过期等错误码。

## 4. 验证玩家信息

此接口由运营商提供给 WXGAME。

```text
POST ${OperationApiDomain}/verify
```

### 请求参数

| 参数 | 说明 | 必填 | 类型 | 示例 |
| --- | --- | --- | --- | --- |
| `token` | 获取游戏链接时传入的玩家令牌 | 是 | string | `33445566` |
| `gameId` | 游戏 ID | 是 | string | `171` |

```json
{
  "token": "33445566",
  "gameId": "171"
}
```

### 响应数据

| 参数 | 说明 | 类型 |
| --- | --- | --- |
| `playerId` | 运营商侧玩家 ID | string |
| `balance` | 玩家余额，最多 `9999999999`，保留 2 位小数 | number |
| `currency` | 币种代码 | string |
| `rtp` | 可选的玩家 RTP 档位；返回后 WXGAME 直接设置 | integer |

```json
{
  "code": 0,
  "data": {
    "playerId": "M9527",
    "balance": 8888.00,
    "currency": "USD"
  },
  "msg": "success",
  "requestId": "8ae1d7bb473c75a3caf4d0be5faadfea"
}
```

可能返回 `1006` 无效的玩家令牌、`1007` 玩家令牌已过期等错误码。

## 5. 获取玩家余额

此接口由运营商提供给 WXGAME。

```text
POST ${OperationApiDomain}/balance
```

### 请求参数

| 参数 | 说明 | 必填 | 类型 | 示例 |
| --- | --- | --- | --- | --- |
| `playerId` | 玩家 ID | 是 | string | `player123456` |

```json
{
  "playerId": "player123456"
}
```

### 响应数据

| 参数 | 说明 | 类型 |
| --- | --- | --- |
| `balance` | 玩家余额，最多 `9999999999`，保留 2 位小数 | number |
| `currency` | 币种代码 | string |

```json
{
  "code": 0,
  "data": {
    "balance": 8888.00,
    "currency": "USD"
  },
  "msg": "success",
  "requestId": "8ae1d7bb473c75a3caf4d0be5faadfea"
}
```

## 6. 下注

此接口由运营商提供给 WXGAME。

```text
POST ${OperationApiDomain}/bet
```

### 请求参数

| 参数 | 说明 | 必填 | 类型 | 示例 |
| --- | --- | --- | --- | --- |
| `transactionId` | 本次交易唯一编号 | 是 | string | `1959260960849920000` |
| `playerId` | 玩家 ID | 是 | string | `player123456` |
| `roundId` | 本局 ID | 是 | string | `bet_round_789` |
| `preRoundId` | 上一局 ID | 否 | string | `bet_round_654` |
| `currency` | 开户币种 | 否 | string | `USD` |
| `bet` | 下注金额 | 是 | decimal | `100.50` |
| `gameBrand` | 游戏品牌 | 是 | string | `jili` |
| `gameId` | 游戏 ID | 是 | string | `171` |

```json
{
  "transactionId": "1959260960849920000",
  "playerId": "player123456",
  "roundId": "bet_round_789",
  "preRoundId": "bet_round_654",
  "currency": "USD",
  "bet": 100.50,
  "gameBrand": "jili",
  "gameId": "171"
}
```

### 响应数据

| 参数 | 说明 | 类型 |
| --- | --- | --- |
| `balance` | 扣款后的玩家余额，最多 `9999999999`，保留 2 位小数 | number |
| `currency` | 币种代码 | string |

```json
{
  "code": 0,
  "data": {
    "balance": 8787.50,
    "currency": "USD"
  },
  "msg": "success",
  "requestId": "8ae1d7bb473c75a3caf4d0be5faadfea"
}
```

常见错误码：`1011` 玩家余额不足、`1018` 交易已存在。

## 7. 派奖

此接口由运营商提供给 WXGAME。玩家未中奖，即 `win=0` 时，WXGAME 默认不发起回调；如需接收零派奖回调，需要联系 WXGAME 客服开启。

```text
POST ${OperationApiDomain}/win
```

### 请求参数

| 参数 | 说明 | 必填 | 类型 | 示例 |
| --- | --- | --- | --- | --- |
| `transactionId` | 本次交易唯一编号 | 是 | string | `1959260960849920001` |
| `playerId` | 玩家 ID | 是 | string | `player123456` |
| `roundId` | 本局 ID | 是 | string | `bet_round_789` |
| `preRoundId` | 上一局 ID | 否 | string | `bet_round_654` |
| `currency` | 开户币种 | 否 | string | `USD` |
| `win` | 派奖金额 | 是 | decimal | `100.50` |
| `gameBrand` | 游戏品牌 | 是 | string | `jili` |
| `gameId` | 游戏 ID | 是 | string | `171` |
| `betTransactionId` | 对应的下注交易 ID | 是 | string | `19592609608499202332` |

```json
{
  "transactionId": "1959260960849920001",
  "playerId": "player123456",
  "roundId": "bet_round_789",
  "preRoundId": "bet_round_654",
  "currency": "USD",
  "win": 100.50,
  "gameBrand": "jili",
  "gameId": "171",
  "betTransactionId": "19592609608499202332"
}
```

### 响应数据

| 参数 | 说明 | 类型 |
| --- | --- | --- |
| `balance` | 加款后的玩家余额，最多 `9999999999`，保留 2 位小数 | number |
| `currency` | 币种代码 | string |

```json
{
  "code": 0,
  "data": {
    "balance": 8888.00,
    "currency": "USD"
  },
  "msg": "success",
  "requestId": "8ae1d7bb473c75a3caf4d0be5faadfea"
}
```

常见错误码：`1018` 交易已存在。

## 8. 撤销

此接口由运营商提供给 WXGAME，用于撤销原下注交易。

```text
POST ${OperationApiDomain}/refund
```

### 请求参数

| 参数 | 说明 | 必填 | 类型 | 示例 |
| --- | --- | --- | --- | --- |
| `transactionId` | 本次撤销交易唯一编号 | 是 | string | `1959260960849920003` |
| `playerId` | 玩家 ID | 是 | string | `player123456` |
| `roundId` | 本局 ID | 是 | string | `bet_round_789` |
| `preRoundId` | 上一局 ID | 否 | string | `bet_round_654` |
| `currency` | 开户币种 | 否 | string | `USD` |
| `bet` | 撤销的下注金额 | 是 | decimal | `100.50` |
| `gameBrand` | 游戏品牌 | 是 | string | `jili` |
| `gameId` | 游戏 ID | 是 | string | `171` |
| `betTransactionId` | 被撤销的下注交易 ID | 是 | string | `19592609608499202332` |

```json
{
  "transactionId": "1959260960849920003",
  "playerId": "player123456",
  "roundId": "bet_round_789",
  "preRoundId": "bet_round_654",
  "currency": "USD",
  "bet": 100.50,
  "gameBrand": "jili",
  "gameId": "171",
  "betTransactionId": "19592609608499202332"
}
```

### 响应数据

| 参数 | 说明 | 类型 |
| --- | --- | --- |
| `balance` | 退款后的玩家余额，最多 `9999999999`，保留 2 位小数 | number |
| `currency` | 币种代码 | string |

```json
{
  "code": 0,
  "data": {
    "balance": 8888.00,
    "currency": "USD"
  },
  "msg": "success",
  "requestId": "8ae1d7bb473c75a3caf4d0be5faadfea"
}
```

常见错误码：`1014` 交易不存在、`1018` 交易已存在。

## 9. 设置玩家 RTP

```text
POST /v1/api/set_player_rtp
```

### 请求参数

| 参数 | 说明 | 必填 | 类型 | 示例 |
| --- | --- | --- | --- | --- |
| `playerIds` | 玩家 ID 集合 | 是 | array[string] | `["M1", "M2"]` |
| `rtp` | RTP 档位 | 是 | string | `95` |

支持的 RTP 档位：`50`、`65`、`75`、`85`、`90`、`95`、`97`、`100`、`150`。

```json
{
  "playerIds": ["player123456"],
  "rtp": "95"
}
```

### 响应数据

`playerIds` 只包含设置成功的玩家。

| 参数 | 说明 | 类型 |
| --- | --- | --- |
| `playerIds` | 设置成功的玩家 ID 集合 | array[string] |

```json
{
  "code": 0,
  "data": {
    "playerIds": ["player123456"]
  },
  "msg": "success",
  "requestId": "8ae1d7bb473c75a3caf4d0be5faadfea"
}
```

## 10. 获取玩家 RTP

```text
POST /v1/api/get_player_rtp
```

### 请求参数

| 参数 | 说明 | 必填 | 类型 | 示例 |
| --- | --- | --- | --- | --- |
| `playerIds` | 玩家 ID 集合 | 是 | array[string] | `["M1", "M2"]` |

```json
{
  "playerIds": ["player123456"]
}
```

### 响应数据

| 参数 | 说明 | 类型 |
| --- | --- | --- |
| `playerRtps` | 玩家 RTP 对象集合 | array[object] |
| `playerRtps[].playerId` | 玩家 ID | string |
| `playerRtps[].rtp` | RTP 档位 | string |

```json
{
  "code": 0,
  "data": {
    "playerRtps": [
      {
        "playerId": "player123456",
        "rtp": "95"
      }
    ]
  },
  "msg": "success",
  "requestId": "8ae1d7bb473c75a3caf4d0be5faadfea"
}
```

## 11. 获取游戏列表

```text
POST /v1/api/get_game_list
```

### 请求参数

| 参数 | 说明 | 必填 | 类型 | 示例 |
| --- | --- | --- | --- | --- |
| `gameBrand` | 游戏品牌，不传表示全部品牌 | 否 | string | `jili` |
| `gameType` | 游戏类型 | 否 | string | `slot`、`table` |

```json
{
  "gameBrand": "jili"
}
```

### 响应数据

| 参数 | 说明 | 类型 |
| --- | --- | --- |
| `gameList` | 游戏对象集合 | array[object] |
| `gameList[].gameId` | 游戏 ID | string |
| `gameList[].gameName` | 游戏简称 | string |
| `gameList[].gameFullName` | 游戏完整名称 | string |
| `gameList[].gameType` | 游戏类型，如 `slot` | string |
| `gameList[].gameBrand` | 游戏品牌 | string |

```json
{
  "code": 0,
  "data": {
    "gameList": [
      {
        "gameId": "171",
        "gameName": "sc",
        "gameFullName": "Sin City",
        "gameType": "slot",
        "gameBrand": "jili"
      },
      {
        "gameId": "103",
        "gameName": "mw2",
        "gameFullName": "Golden Empire",
        "gameType": "slot",
        "gameBrand": "jili"
      }
    ]
  },
  "msg": "success",
  "requestId": "d0c07e902eb6fe1b0e36d31e81bce6ab"
}
```

## 12. 错误码

| 错误码 | 中文说明 | 英文说明 |
| --- | --- | --- |
| `0` | 成功 | Success |
| `1001` | 内部服务器错误 | Internal server error |
| `1002` | 无效的运营商 | Invalid operator |
| `1003` | 运营商权限不足 | Limited permissions |
| `1004` | 无效的哈希码 | Invalid hash code |
| `1005` | 请求参数错误 | Invalid parameters |
| `1006` | 无效的玩家令牌 | Invalid player token |
| `1007` | 玩家令牌已过期 | Player token expired |
| `1008` | 游戏维护中 | Game is under maintenance |
| `1009` | 游戏不可用 | Game is unavailable |
| `1010` | 游戏不存在 | Game not found |
| `1011` | 玩家余额不足 | Insufficient balance |
| `1012` | 玩家不存在 | Player not found |
| `1013` | 投注异常，可能超时或参数异常 | Bet is pending |
| `1014` | 交易不存在 | Transaction not found |
| `1015` | 币种代码错误或不支持 | Invalid currency code |
| `1016` | 无效请求 | Invalid request |
| `1017` | 玩家已存在 | Player already existed |
| `1018` | 交易已存在 | Transaction Already Exists |
| `1019` | 无效 IP | Invalid IP |
| `1020` | 已达到最大请求限制 | Max Request Limit |
| `1021` | 无效 RTP | Invalid RTP |

## 13. 对接流程

1. 运营商向 WXGAME 请求游戏链接，并传入临时 `token`。
2. WXGAME 回调运营商 `/verify`，使用 `token` 换取玩家编号、余额、币种和可选 RTP。
3. 玩家进入游戏后，WXGAME 按需回调 `/balance` 查询余额。
4. 玩家下注时，WXGAME 回调 `/bet` 扣款。
5. 游戏产生派奖时，WXGAME 回调 `/win` 加款。
6. 下注需要撤销时，WXGAME 回调 `/refund`，并通过 `betTransactionId` 关联原下注交易。
7. 运营商必须按 `transactionId` 幂等处理资金回调，重复请求应返回原交易结果。
