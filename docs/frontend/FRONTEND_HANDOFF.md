# Group 10 静态前端交接

本说明用于 11 月 27 日静态网页阶段，基于 [Requirement.md v1.4](../../Requirement.md) 和 [六人分工计划](../../Workflow_and_Team_Plan.md)。解压仓库中的 `FORM_Club_Static_HTML_CSS.zip` 后，从根目录的 `index.html` 或 `walkthrough.html` 进入。压缩包只含 HTML 和 CSS，交接和验证材料由仓库保存。

## 页面对应关系

16 个主要页面逐一对应总框架的 V01–V16。其他课例、名单和成功或失败结果是辅助页。

| 规划 | 文件 | 动态阶段需要的数据 |
|---|---|---|
| V01 首页 | `index.html` | 已发布套餐和即将开课的场次摘要 |
| V02 注册 | `register.html` | 账号、联系方式、密码输入和表单反馈 |
| V03 登录 | `login.html` | 账号验证、角色与会话 |
| V04 套餐 | `plans.html` | 已上架套餐版本、价格和固定天数 |
| V05 购买 | `checkout.html` | 套餐版本、订单状态、付款方式和生效日期 |
| V06 课表 | `schedule.html` | 日期、教练、课程及场次筛选结果 |
| V07 场次详情 | `class-detail.html` | 场次、起止时间、教练、场地、名额、预约资格 |
| V08 我的预约 | `bookings.html` | 本人预约、历史和可执行的下一步 |
| V09 我的会籍 | `membership.html` | 本人订单、已付款周期和有效期 |
| V10 个人资料 | `profile.html` | 本人账号资料和出勤历史 |
| V11 后台概览 | `admin-dashboard.html` | 当日课表、容量和汇总数据 |
| V12 会员管理 | `admin-members.html` | 会员搜索、会籍详情和账号状态 |
| V13 套餐及订单 | `admin-plans.html` | 套餐版本、上线状态、订单与线下收款确认 |
| V14 资源管理 | `admin-resources.html` | 教练、场地、课程模板 |
| V15 排课与名单 | `admin-schedule.html` | 场次、场次状态、预约名单、签到和结课 |
| V16 统计 | `admin-reports.html` | 日期筛选、成功收款、销量、热度和出勤聚合 |

## 演示与业务规则

| 需求 | 演示入口与结果 |
|---|---|
| W01 购买、W02 续费 | `plans.html` 选择 90 天 → `checkout.html` → `result-payment.html` → `membership-renewed.html`。30 天和 365 天有对应的独立样例 |
| W03 预约 | `schedule.html` → Power Flow (`class-detail.html`) → `result-booking.html` → `bookings-confirmed.html` |
| W04 取消 | 在 Power Flow 的预约记录点取消 → `result-cancellation-yoga.html` → `bookings-cancelled-yoga.html`，能看到保留的取消记录 |
| W05 排课、停课 | `admin-schedule.html` 的发布示例展示排课结果，S106 名单展示已取消状态 |
| W06 签到、结算、统计 | 排课页 S101 名单展示 Jamie 和 Sam 各自的签到结果，同一名单另有独立的结课示例，报表见 `admin-reports.html` |

注册登录、资源管理、线下收款、筛选和异常状态是补充演示。结果可以从链接进入。展示的是固定样例。

**Members 搜索**从 `admin-members.html` 的四条名单点 **View An Lifeng search example**，进入 `member-search.html` 的一条固定命中。查询字段只读，**Show all sample members** 返回四条名单。后端接入时再将查询字段接到真实的会员搜索，支持姓名、编号或邮箱。

**线下付款**三个结账页均有独立的 30 天线下付款示例，用来演示订单 #ORD1002 和管理员确认收款。这条入口和各套餐自己的模拟付款结果分别展示。

### 名单关联

同一课程可有多个场次。后端用场次 ID 查询该场次的预约及出勤，不能按课程名称查询名单。S101–S106 各有对应的静态名单入口。同为 Strength Foundations 的 S102 与 S104 也有各自的日期和名单。S106 已取消，不开放签到，也不记缺席。签到记录需要对应当前场次的有效预约。两位会员的签到结果分别展示各自的记录。

### 续费

允许在有效期内提前购买。确认付款后创建独立的会籍周期。新周期起点按 B04 取“付款确认时间”和“最后一个已付款周期结束时间”中较晚的时间。若没有已付款周期，直接用付款确认时间。未付款或取消订单不参与计算。

90 天样例中，当前会籍于 2027 年 1 月 1 日 00:00 到期，提前续费的周期接到 2027 年 4 月 1 日 00:00。若第二次续费在 4 月 1 日前确认，下一段从 4 月 1 日接续。三个续费结果页是选不同套餐的独立样例。

## 后端联调确认

前端与后端共同确认实际接口路径、字段、状态及错误反馈。先确定会话中会员与管理员的权限、订单收款与会籍开通规则、按场次获取名单及按预约签到，再替换静态结果。业务时间用 Asia/Shanghai。确认收款、追加会籍、预约容量与签到幂等由服务端处理。统计按已确认收款与已结课的有效出勤口径生成。

名单和续费规则的修改见 [T02 修复说明](../tasks/T02_Static_Frontend_Integration.md)。最新的筛选栏、搜索展示、状态提示与整站复核见 [T03 检查说明](../tasks/T03_Static_Frontend_Polish_Review.md) 和 [浏览器结果](static-validation.json)。
