# 币种图标

`currencies.svg` 是本项目自制的圆形币种标记，不包含从第三方站点提取的路径。

- 使用 `CurrencyIcon`，不要为每次显示重复注入整份 SVG。
- 每个 symbol 使用 `24 24` viewBox；专用图案为 USD、INR、EUR、GBP、JPY、CNY、USDT、TRX。
- 其他币种复用 `coin`，由组件显示币种代码；不以缺少图标为由限制充值币种。
- 文件作为带 hash 的独立构建资源，同一 URL 可缓存复用。单文件增大时再分组，不批量增加几百个重复圆形图案。
- 文件中的 USDT/TRX 图形是界面识别标记，不代表官方认证或合作关系。
