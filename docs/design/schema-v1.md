# FORM Club 物理模型与数据字典 v1

适用脚本为 `database/form_club.sql`。实施环境为 XAMPP 的 MariaDB 10.4.32、PHP 8.2.12、InnoDB 及 utf8mb4。11 张业务表对应提交版 ER 图的 11 个实体。教练是资源，只有 MEMBER 和 ADMIN 可以登录。数据库比较邮箱时忽略大小写，比较幂等键时区分大小写。业务日期以 Asia/Shanghai 解释，DATETIME 的精度为秒，连接设置 `+08:00`。区间一律左闭右开。

## 属性、主键、候选键和函数依赖

表内首列均为自增 `INT UNSIGNED` 主键，所有关联 ID 与其引用主键同型。表中 NULL 仅用于明确注明可空的字段。每行“候选键”中的每一个键都函数决定本行其余全部逻辑属性，这些依赖及其逻辑推论构成本版本的业务函数依赖。枚举值与默认值可在 SQL 中核对。

| 实体／表 | 候选键 | 字段含义和约束 |
|---|---|---|
| 用户 users | user_id；email | email VARCHAR(254) 唯一。password_hash VARCHAR(255) 保存 PHP 密码哈希。full_name VARCHAR(120) 可重名，phone VARCHAR(32) 可空。role 为 MEMBER、ADMIN，status 为 ACTIVE、DISABLED。会员编号使用 user_id。 |
| 套餐版本 membership_plans | plan_id；(name, version) | name VARCHAR(120)，version 为正整数。price DECIMAL(10,2)，单位为人民币元且大于零。duration_days 为正整数，代表固定天数。status 为 ACTIVE、INACTIVE。引用后保留名称、版本、价格与时长。 |
| 订单 orders | order_id；(user_id, request_key) | user_id 与 plan_id 分别标识购买者和套餐版本。method 为 SIMULATED、OFFLINE。request_key VARCHAR(100) 标识会员一次下单意图，created_at DATETIME 记录建立时间。状态 PENDING 可进入 PAID 或 CANCELLED，后两者为终态。 |
| 付款尝试 payments | payment_id；(order_id, attempt_key) | order_id 指向来源订单。attempt_key VARCHAR(100) 可用于同一订单内重试去重。attempted_at 为尝试时间。状态为 PENDING、SUCCEEDED、FAILED。仅 SUCCEEDED 的 paid_at 有付款确认时间，其他状态必须为空。付款方式和应付金额从订单及版本取得。 |
| 会籍周期 memberships | membership_id；order_id | 每个来源订单最多一条。starts_at、ends_at 为 DATETIME 且结束晚于开始。用户、套餐和权益通过订单取得，有效、未来和过期由查询时刻推算。 |
| 教练 coaches | coach_id | name VARCHAR(120) 允许重名，specialty VARCHAR(255) 是简介文本。status 为 ACTIVE、INACTIVE。 |
| 场地 venues | venue_id；name | 单中心内场地名称 name VARCHAR(120) 唯一。location VARCHAR(255) 表示位置，capacity 为正整数。status 为 ACTIVE、INACTIVE。 |
| 课程模板 courses | course_id | name VARCHAR(120)，category VARCHAR(80)，description TEXT，duration_minutes 为正整数，表示参考时长。status 为 ACTIVE、INACTIVE。 |
| 课程场次 class_sessions | session_id | course_id、coach_id、venue_id 各指向一个资源。starts_at、ends_at 为 DATETIME，结束晚于开始。capacity 为正整数。status 为 SCHEDULED、CANCELLED、COMPLETED。 |
| 预约 bookings | booking_id；(user_id, session_id) | 一个会员与一个场次最多一条预约。booked_at 记录最近一次确认时间。CONFIRMED 的 cancelled_at 为空。CANCELLED_BY_MEMBER 和 CANCELLED_BY_CENTER 均保留取消时间。 |
| 出勤 attendance | attendance_id；booking_id | booking_id 唯一。PRESENT 必须有 checked_at。ABSENT 的 checked_at 必须为空。无记录表示尚无出勤结果。 |

在这些依赖下，每个非平凡函数依赖的决定因素均为超键，11 个逻辑关系均满足 BCNF，也满足 3NF。举例而言，若把价格、有效天数放入 orders，会产生 `order_id → plan_id → price, duration_days`。将它们留在套餐版本表后，一个被引用的版本只维护一次价格，交易历史仍可按订单准确还原。付款表的虚拟列 success_marker 是数据库为条件唯一索引计算的表达式；它不属于上述逻辑属性，也无需由调用者写入。

## ER 联系、索引和删除

子表的下列外键均必填。每个子记录必须有且仅有一个对应父记录。父方可在创建后暂时没有相关子记录。

| 父表 → 子表／外键 | 每个父记录的子记录数 | 删除父记录 |
|---|---|---|
| users → orders.user_id；membership_plans → orders.plan_id | 0..N | RESTRICT，保留购买者与版本。 |
| orders → payments.order_id | 0..N | RESTRICT，保留每次付款尝试。 |
| orders → memberships.order_id | 0..1 | RESTRICT，保留会籍来源。 |
| courses、coaches、venues → class_sessions 的对应资源 ID | 各 0..N | RESTRICT，停用资源保留历史课表。 |
| users → bookings.user_id；class_sessions → bookings.session_id | 0..N | RESTRICT，保留会员历史与场次名单。 |
| bookings → attendance.booking_id | 0..1 | CASCADE，清理专门的隔离测试预约时同步清理从属出勤。业务端取消预约时只更新状态。 |

名单使用 `(session_id,status,booking_id)`，课表使用 `(status,starts_at,session_id)`。教练、场地的时段检查分别以资源 ID 和状态为索引前缀。个人订单按 `(user_id,status,order_id)` 检索，收款汇总使用 `(status,paid_at)`。真实数据上的运行时间和 EXPLAIN 见规模验证报告。

## 哪一层负责哪些完整性规则

SQL 使用外键、唯一键、检查和触发器限制非法行。两个请求都试图成功结算同一订单时，唯一索引 `(order_id,success_marker)` 最多接纳一个成功标志 1。多个失败尝试对应 NULL，能够合法共存。三个更新触发器保护被引用的套餐版本、订单条款及终态、已出结果的付款尝试。业务服务对用户与角色、输入、跨表条件和并发写入另行校验。

一次成功收款在同一事务写入付款、订单状态和会籍，任何一步失败即回滚。续费按同一会员的最近已付款结束时间接续。场次、会员时间冲突、相邻周期的连续覆盖、容量、签到和结课规则依靠服务事务和锁顺序，直接手写 SQL 的导入方也须保持这些跨行条件。规模生成器与独立验证脚本据此核对数据。

## 演示数据

小库有管理员 1、已持有会籍的会员 2、尚无会籍的会员 3。三人分别使用 admin@form.test、member@form.test、new@form.test，演示密码均为 `FormClub2026!`。场次 1 安排在小库导入日次日 18:00，场次 2 在后天中午；昨天的已结课场次 3 有一条签到。另有一张待确认的线下订单。完整 SQL 快照保留导出当天的绝对日期。以后进行现场演示可重新导入小库生成当期日期，或通过后台排课。
