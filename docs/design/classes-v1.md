# FORM Club 团课接口 v1

面向 B 组 V06–V08、V10–V11、V14–V16。入口是 `public/api.php?action=<action>`，在网站根目录为 `public/` 时使用相对路径 `api.php`。静态样例中的 S101、固定日期和固定余量都应替换为 API 返回值。浏览器访问 `index.php` 获取 HTML，再用同源请求获取 JSON。

## 公共约定

- 列表参数 `page` 从 1 开始，默认 1。也可传非负整数 `offset` 指定起始记录，优先于 page。`limit` 为 1–100，默认 25。下文的“列表”均表示 `{"items":[...],"limit":25,"offset":0}`；空结果返回空数组。统计接口返回整个时间范围的聚合值。
- ID、容量和分钟数使用正整数。日期为 `YYYY-MM-DD`，数据库返回上海本地时间 `YYYY-MM-DD HH:mm:ss`。排课也接受 HTML `datetime-local` 的 `YYYY-MM-DDTHH:mm`。入参不附 UTC 偏移。金额是人民币元的定点十进制字符串，保留两位小数。百分比单位为 %，分母为零时为 JSON `null`。
- GET 筛选写在查询串。POST 使用 JSON 对象或表单，带当前会话 cookie 和从 `auth.session` 取得的 CSRF 值。JSON 请求用 `X-CSRF-Token` 头，表单也可用 `csrf_token` 字段。服务端从会话确定本人 ID。
- 示例成功响应为 `{"ok":true,"data":{"session_id":1,"spots_left":11},"db_ms":1.24}`（仅列部分字段，耗时为示例值）。失败响应为 `{"ok":false,"error":{"code":"CLASS_FULL","message":"No places remain."},"db_ms":1.15}`。响应状态 401 表示未登录，403 表示权限或 CSRF 未通过，404 表示记录不存在，409 表示状态冲突或有限重试后仍繁忙，422 表示字段或业务输入错误。
- `db_ms` 单位为毫秒，累加本次请求中已计时的 PDO prepare + execute 以及事务 begin、commit、rollback。连接建立、PHP 处理、JSON 编码和浏览器显示开销不计入。它包含数据库往返与锁等待时间。

## 课表与资源

| 页面 / 流程 | 方法、action、角色 | 参数 | 成功数据及示例 | 失败示例 |
|---|---|---|---|---|
| V01/V06/V15 · W03/W05 | GET `sessions.list`，公开 | 可选 `date`、`coach_id`、`course_id`、`venue_id`、`type`（课程分类）、`status`、`scope=upcoming/past/all`，默认 upcoming，支持分页 | 按开始时间、ID 升序的场次列表。例 `date=2032-05-10&type=Yoga&scope=all` | 无效日期 `INVALID_DATE`，无效状态 `INVALID_INPUT` |
| V07 · W03 | GET `sessions.detail`，公开 | `session_id` | 单场数据，加 `can_book:boolean`、`booking_reason:string或null`、本人的 `booking_id:int或null`。已预约返回 `ALREADY_BOOKED` | 未知 ID 为 `NOT_FOUND` |
| V06/V14 · W05 | GET `resources.list`，公开 | `kind=course/coach/venue`，支持分页 | 对应资源列表，按名称、ID 升序。管理员看所有状态，其他人看 ACTIVE。例如 `kind=course` 返回课程名称和分类 | 不支持的 kind 为 `INVALID_INPUT` |
| V14 · W05 | POST `resources.save`，Admin | `kind,name,status`，编辑时加 `id`。教练填 `specialty`，场地填 `location,capacity`，课程填 `description,duration_minutes,category` | 完整资源对象，例如 `{"venue_id":4,"name":"Room D","location":"Level 2","capacity":14,"status":"ACTIVE"}` | 仍有未结束课时停用、场地缩容低于已排容量，均返回 `RESOURCE_IN_USE` |
| V15 · W05 | POST `sessions.save`，Admin | `course_id,coach_id,venue_id,starts_at,ends_at,capacity`；编辑时加 `session_id` | 已发布或编辑的场次对象，例如 `status=SCHEDULED`。已有确认预约仍可扩大容量 | `VENUE_CAPACITY`、`SCHEDULE_CONFLICT`、`RESOURCE_UNAVAILABLE`、`SESSION_HAS_BOOKINGS` |
| V15 · W05 | POST `sessions.cancel`，Admin | `session_id` | `status=CANCELLED`、`booked=0`。已停课重复请求返回已有状态；会员之前自行取消的记录保持原状态 | 已结课为 `SESSION_CLOSED` |

场次对象含 `session_id/course_id/coach_id/venue_id`、`course_name/category/description/coach_name/venue_name/location`、`starts_at/ends_at`、`capacity/booked/spots_left`、`status`。状态为 SCHEDULED、CANCELLED、COMPLETED。余量按已确认预约即时计算；关闭场次的可订余量为 0。详情读取的资格和余量反映当时状态，写操作内会重新校验。

资源状态为 ACTIVE、INACTIVE。保存资源使用全量编辑表单，名称最多 120 字，专长和位置最多 255 字，描述最多 16,000 字，分类最多 80 字。省略状态默认为 ACTIVE，省略文字详情默认为空字符串，未提供分类时采用 General。编辑时提交当前完整字段可保留原值。新增场次与新增资源由数据库分配 ID；超时后在对应列表核对。发布同一教练或同一场地的重叠时段会被拒绝。

## 预约、签到及结课

| 页面 / 流程 | 方法、action、角色 | 参数 | 成功数据及示例 | 失败示例 |
|---|---|---|---|---|
| V07 · W03 | POST `bookings.create`，Member | `session_id` | 预约对象和 `spots_left`。重复确认返回同一个 booking_id，会员取消后符合条件可恢复同一记录 | `CLASS_FULL`、`MEMBERSHIP_COVERAGE`、`BOOKING_CONFLICT`、`BOOKING_WINDOW`、`SESSION_CLOSED` |
| V08 · W04 | POST `bookings.cancel`，Member | `booking_id`，限本人 | 预约对象，首次成功为 `CANCELLED_BY_MEMBER`。重复取消返回已有状态 | 其他会员 ID 返回 `NOT_FOUND`；超时为 `CANCEL_WINDOW` |
| V08 · W03/W04 | GET `bookings.list`，Member | 可选 `scope=all/upcoming/past/cancelled`（默认 all）、`status`，支持分页 | 本人预约列表，按开课时间、booking_id 倒序，包含课程、教练、场地、场次状态和出勤结果 | 无效状态为 `INVALID_INPUT` |
| V15 · W06 | GET `sessions.roster`，Admin | `session_id`，支持分页 | `{session,items,limit,offset}`；每行含 `booking_id,user_id,full_name,booking_status,attendance_status,checked_at`。按姓名、booking_id 排序，保留取消的行，可依状态隐藏 | 普通会员为 `FORBIDDEN` |
| V15 · W06 | POST `attendance.checkin`，Admin | `booking_id` | `{attendance_id,booking_id,status:"PRESENT",checked_at}`；重试同一有效签到返回原时间 | 已取消预约为 `INVALID_BOOKING`，窗口外为 `CHECKIN_WINDOW` |
| V15 · W06 | POST `sessions.complete`，Admin | `session_id` | 场次状态 COMPLETED，事务内给尚未签到的有效预约生成 ABSENT。重复结课读取已有结果 | 未结束为 `SESSION_IN_PROGRESS`，已停课为 `SESSION_CLOSED` |
| V10 · W06 | GET `attendance.list`，Member | 分页 | 本人有效且已结课的出勤列表，按开课时间、booking_id 倒序，含场次 ID、课程名称和起止时间 | 未登录为 `LOGIN_REQUIRED` |

预约行含 `booking_id,user_id,session_id,status,booked_at,cancelled_at`。预约状态为 CONFIRMED、CANCELLED_BY_MEMBER 或 CANCELLED_BY_CENTER。取消时间在确认状态为空。未记录的出勤和 ABSENT 签到时间均为空，前端依 `attendance_status` 区分。停课后之前的签到在原始名单保留，运营报表和个人已结课出勤不纳入该场次。

课表使用左闭右开区间。开课前 30 天整开放，开课时截止。两段已付会籍首尾相接可以共同覆盖一节课。会员最迟可在开课前 2 小时整取消。签到窗口包含开课前 30 分钟与开课后 15 分钟两个端点。课程结束时可显式结课。

## 仪表盘与统计

| 页面 / 流程 | 方法、action、角色 | 参数 | 成功数据及示例 | 失败示例 |
|---|---|---|---|---|
| V11 · W05/W06 | GET `dashboard.summary`，Admin | 无 | `date,active_members,todays_classes,confirmed,capacity,pending_offline,sessions`；当日汇总剔除已停课，明细保留状态 | 未登录为 `LOGIN_REQUIRED` |
| V16 · W01/W02/W06 | GET `reports.summary`，Admin | `from,through` 为含首尾日期，默认本月 1 日至今天 | `revenue,paid_orders,eligible,present,absent,attendance_rate,absence_rate,capacity,confirmed,booking_rate,plans,popularity`。例如两人应到、一人签到，则 `attendance_rate=50` | 结束日早于开始日为 `INVALID_INTERVAL` |

收入按成功付款的确认时间、已付款订单所引用的套餐版本价格计算，每单只计一次。套餐销量分组包含 `plan_id,name,version,orders_sold,revenue`。预约率按选定范围内开课的非停课场次汇总，分子为有效预约数、分母为容量总和。出勤率按同一范围内开课且已结课的有效预约计算，分类统计 `category,eligible,present,absent,attendance_rate`。无分母时界面显示 “No data”。

## 事务与复验

会员写入先锁 users 行，再锁 class_sessions 行。会籍计算与同会员的其他预约、付款共用会员锁，最后一席由场次锁串行分配。发布依次锁课程、教练、场地，再锁待编辑的场次；资源停用取得相同资源锁。停课、签到和结课在场次锁内重新读取预约状态。READ COMMITTED 下，等待者取得锁后重新查询能看到前一个提交。

例：甲、乙同时确认最后一席。甲先取得场次锁并插入预约，乙等甲提交后重新数确认预约，得到 CLASS_FULL。若甲的 SQL 失败，事务回滚，乙随后仍能取得最后一席。另一种竞争是管理员停课与会员预约；先停课则预约返回 SESSION_CLOSED，先预约则管理员将新记录与其他有效预约一起改为 CANCELLED_BY_CENTER。两条路径提交后有效名额都为零。

在专用、名称以 `_test` 结尾的数据库导入全量 SQL，给测试账号 SELECT/INSERT/UPDATE/DELETE，再将 `FORM_CONFIG` 指向该库配置，运行 `php tests/classes.php`。脚本通过子进程和独立 PDO 连接同步发起写入，用连接级测试时钟验证边界；它追加带随机标记的测试记录，保留记录便于复核。

2026-11-18 在 Windows、XAMPP PHP 8.2.12 / MariaDB 10.4.32 上实测。独立 InnoDB 测试实例启用缓冲 I/O，在空库导入 11 张表，使用受限账号运行脚本。最终运行通过 29 项断言，进程退出码 0。

| 验证组 | 实测结果 |
|---|---|
| 发布、容量、会员冲突 | 同一资源同时发布仅一个成功。两人抢一席仅一个成功。同一会员并发预约重叠课程仅一个成功，首尾相接的课均可预订。 |
| 取消及回滚 | 开课前恰好 2 小时可取消，晚一秒拒绝。并发重复取消仅释放一席。跨连接锁住第二条预约后，停课三次尝试均超时，返回 BUSY；场次仍为 SCHEDULED，两条预约仍为 CONFIRMED。解除锁后重试成功。 |
| 会籍与预约窗口 | 首尾相接的会籍能共同覆盖一节课；中间差一秒则拒绝。提前 30 天整允许，提前 30 天零一秒和开课时均拒绝。 |
| 权限与停课 | 会员读管理员名单被拒绝，未登录写入被拒绝，另一会员取消预约返回 404。停课与预约竞争后无有效预约，已停课的预约拒绝签到。 |
| 出勤 | 开课前 30 分钟、后 15 分钟两个端点可签到；端点外一秒拒绝。并发签到只写一次。结课前一秒拒绝，结束时两个并发结课都取得同一结果。 |
| 运营报表 | 同一分类内一人出席、一人缺席、另有停课和未结课场次时，结果为 1/2 即 50%。无合格记录时出勤率返回 null。 |
