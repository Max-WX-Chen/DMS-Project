# FORM Club v1 · 账号、订单与会籍接口

供 B 组接入 V01–V05、V09–V10、V12–V13。字段与键见 [11 表字典](schema-v1.md)，C2 见 [场次与预约接口](classes-v1.md)。

## 请求与响应

HTML 入口为 `index.php?view=plans` 等视图。HTTP JSON 入口为同目录的 `api.php?action=动作名`。GET 参数放查询串。POST 接受 JSON 对象或 HTML 表单字段。第一次访问先 GET `auth.session`，保留会话 cookie，把返回的令牌传入所有 POST 的 `X-CSRF-Token` 请求头（表单也可传 `csrf_token`）。注册、登录、退出、改密后使用响应里的新令牌。注册后立即登录为会员。用户 ID 和角色由服务端解析。

成功响应示例为 `{"ok":true,"data":{"user":null,"csrf_token":"<token>"},"db_ms":0}`。失败示例为 `{"ok":false,"error":{"code":"ORDER_CANCELLED","message":"This order was cancelled."},"db_ms":0.84}`。计时值单位为毫秒，累加 PDO prepare/execute 和事务开始、提交、回滚的墙钟耗时，含往返与锁等待。连接、应用层遍历、JSON 编码与浏览器时间另计。

ID 均为正整数。金额是两位小数的人民币字符串。日期为 `YYYY-MM-DD`，DATETIME 为上海本地 `YYYY-MM-DD HH:mm:ss`。空手机号、未成功付款的 paid_at、未开通的 membership 用 JSON null。C1 列表直接返回数组。分页默认 page=1、limit=25，limit 取 1–100；可传非负 offset 代替 page。空页返回 []。C2 列表封装和额外业务错误码见其接口表。

## 接口表

|页面 / 流程|方法与 action|角色与输入|成功结果|失败例|
|---|---|---|---|---|
|V01–V03|GET auth.session|所有访客|user 或 null，加 csrf_token|数据库故障时 500|
|V02|POST auth.register|公开；full_name 字符串≤100、email≤190、password 至少 8 字符且≤72 字节，phone 可空|登录会话和会员公开资料|重复邮箱 409 DUPLICATE|
|V03|POST auth.login|公开；email、password|会话、角色、令牌|401 LOGIN_FAILED|
|V03|POST auth.logout|任意会话，无业务参数|新的匿名会话|403 CSRF|
|V10|POST profile.update|Member；full_name、可空 phone|更新后的本人资料|422 INVALID_PHONE|
|V10|POST profile.password|Member；current_password、password|新会话令牌|403 PASSWORD_MISMATCH|
|V01/V04/V13，W01–02|GET plans.list|公开；all=1 限 Admin，可分页|套餐按天数、名称、版本排序，含 plan_id/name/version/price/duration_days/status|403 FORBIDDEN|
|V13|POST plans.create|Admin；name、price、duration_days（1–3650 天）|同名套餐的下一版本，默认 ACTIVE|422 INVALID_PLAN，冲突 409 DUPLICATE|
|V13|POST plans.status|Admin；plan_id、status=ACTIVE/INACTIVE|更新后的版本；历史订单保留|404 NOT_FOUND|
|V05，W01–02|POST orders.create|Member；plan_id、method=SIMULATED/OFFLINE、request_key|含 order/payments/membership 的订单详情，初始 PENDING|409 PLAN_UNAVAILABLE 或 KEY_REUSED|
|V05，W01–02|POST orders.pay|Member；order_id；可选 result=SUCCEEDED/FAILED 和 attempt_key|同一订单详情，失败尝试保留，成功时返回周期|409 WRONG_METHOD / ORDER_CANCELLED|
|V05/V13，W01–02|POST orders.confirm|Admin；order_id，可选 attempt_key|确认线下收款后的订单详情|409 WRONG_METHOD|
|V05/V09|POST orders.cancel|Member；order_id|取消本人待付单并返回详情，重复取消可重试|409 ORDER_PAID|
|V05/V09/V13|GET orders.detail|Member 本人或 Admin；order_id|order（含成交版本价格）、payments（按 payment_id 升序）、membership（零或一个）|404 NOT_FOUND，同样用于他人订单|
|V09/V13|GET orders.list|Member 本人或 Admin；分页，可选 status、method、from（含）、until（不含）；Admin 可传 user_id|订单按 order_id 倒序|422 INVALID_DATE|
|V09，W02|GET memberships.list|Member 本人；分页|已付周期按起点倒序，含 plan_name、starts_at、ends_at，状态为 ACTIVE/FUTURE/EXPIRED|401 LOGIN_REQUIRED|
|V12|GET members.list|Admin；可选 q、分页|姓名与邮箱子串或会员编号精确匹配，按 user_id 排序；例 q=M2|403 FORBIDDEN|
|V12|GET members.detail|Admin；user_id，周期可分页|user 和 periods|404 NOT_FOUND|
|V12|POST members.status|Admin；user_id、status=ACTIVE/DISABLED|变更账户状态，下次请求重验现有会话|404 NOT_FOUND|

公开用户字段为 user_id、full_name、email、phone、role、status。密码及哈希始终留在服务端。无登录返回 401；角色或令牌不符返回 403；方法不符返回 405。测试机仅启用模拟支付和管理员确认线下收款。

## 前端可以直接复现的购买

以默认会员登录，在浏览器同源页面的开发者控制台运行以下代码。相同 request_key 会拿回同一笔订单，重新购买要换 key。

```js
const read = async action => (await fetch('api.php?action=' + action)).json();
const {data: {csrf_token}} = await read('auth.session');
const write = async (action, body) => (await fetch('api.php?action=' + action, {
  method: 'POST',
  headers: {'Content-Type':'application/json','X-CSRF-Token':csrf_token},
  body: JSON.stringify(body)
})).json();
const result = await write('orders.create', {plan_id:1,method:'SIMULATED',request_key:crypto.randomUUID()});
const receipt = await write('orders.pay', {order_id:result.data.order.order_id});
console.log(receipt.data.membership, receipt.db_ms);
```

## 重试、周期和回滚

订单请求键限 1–100 位 ASCII 字母、数字、下划线或连字符，在同一会员下唯一。相同键换套餐或换方式会报 KEY_REUSED。付款尝试键在同一订单内唯一；要记录可重试的付款失败，请显式传 attempt_key 并在同次重试时复用。已付订单的再次确认会读回原收据。

例如会员已有周期截至 1 月 1 日 00:00，12 月再付两张 30 天订单。两次事务都先锁同一 users 行，再锁各自 orders 行。后到的事务取得会员锁时，在 READ COMMITTED 下读到前一笔已提交的结束时间。两个周期分别为 1 月 1 日至 1 月 31 日、1 月 31 日至 3 月 2 日（平年），起点含、终点不含。付款时间晚于原到期日时改从付款时算。唯一索引兜底，每单最多一笔成功收款和一个周期。若最后写入周期失败，先前写入的付款及订单状态一起回滚。死锁或锁等待超时至多重试两次，仍失败则返回 409 BUSY。
