# DMS-Project
Database Management System Group Project

健身中心会员与课程预约管理系统。仓库收录阶段一的问题描述和 ER 草稿，以及 11 月 27 日阶段的静态网页。

## 项目文档

- **[下载 Group 10 两页 Word](docs/submissions/first_submission/COMP3013_26F_Group10_ER_diagram_draft.docx)**：[Markdown 源稿](docs/submissions/first_submission/COMP3013_26F_Group10_ER_diagram_draft.md) · [Chen 图源 SVG](docs/submissions/first_submission/assets/Group10_Chen_ER_diagram_draft.svg) · [中文任务与检查](docs/tasks/T01_Chen_ER_Diagram.md)。第一页为问题描述，第二页为 Chen ER 图，箭头表示基数、双线表示完全参与；不附全组名单。

- **[11 月 21 日第一次提交指南](docs/tasks/T01_First_Submission.md)**：先读任务解析，再评审候选 ER；包含分工、写作、验收与问答。

- [Requirement.md](Requirement.md)：优化后的项目总框架、课程要求、业务规则、模型、技术深度及 Codex 协作要求。
- [Workflow_and_Team_Plan.md](Workflow_and_Team_Plan.md)：六人分工、阶段安排、数据库与后端开始时间。
- [文档入库与分支说明](docs/tasks/T00_Documentation_Baseline.md)：本次任务记录，以及分支、main 和 PR 的区别。

## 六人分工

- A 组：需求、ER、逻辑设计，后续负责数据生成、验证、报告与 PPT 汇总。
- B 组：静态与动态前端。
- C 组：数据库实现与后端业务。
- 全员：规则评审、理解关键设计、联调、展示与验收。

## 协作方式

`main` 作为全组共同使用的正式基线。各项任务在独立分支开发，通过 PR 审阅和合并进入 `main`；文件可以在 `main` 的不同目录中保存，不必全放根目录。

每次 Codex 产出均附 Markdown，解释任务、原理、验证与交接。具体业务和技术要求以总框架及相应任务文档为准。

## FORM Club 静态网页（11 月 27 日）

这份网页用于 COMP3013 项目 11 月 27 日的静态阶段。主题是健身中心会员与团课预约系统。界面采用英文，使用说明和交接文档采用中文。

当前交付保留 **16 个主要页面**，另有辅助示例。ZIP 根目录提供 70 个 HTML 和 2 个 CSS 文件，无须运行脚本或连接数据库。

### 怎样打开

1. 解压 `FORM_Club_Static_HTML_CSS.zip`，保留全部目录结构。
2. 双击解压目录根部的 `index.html`，用 Edge、Chrome 等浏览器打开。
3. 点击页面最上方的 **Demo walkthrough**，可以按业务流程逐步浏览。
4. 页脚的 **Admin demo** 进入管理员页面；登录页也有 Member preview 和 Admin preview 两个入口。

不要直接在压缩包内预览单个 HTML，也不要只把首页发给别人。CSS 和其他页面需要一起保留。

### 应该提交什么

- 本阶段通过 HTML 和 CSS 展示页面结构，使用固定页面代替动态内容。正式包是 [FORM_Club_Static_HTML_CSS.zip](FORM_Club_Static_HTML_CSS.zip)。
- 组号为 Group 10。组长根据 iSpace 的要求检查上传格式与文件名。
- 开发交接与验证记录见 [前端交接](docs/frontend/FRONTEND_HANDOFF.md) 和 [浏览器结果](docs/frontend/static-validation.json)。

### 16 个主要页面

| 编号 | 文件 | 页面内容 |
|---|---|---|
| V01 | index.html | 首页、导航、课程预览、会员入口 |
| V02 | register.html | 注册表单布局及成功结果入口 |
| V03 | login.html | 登录布局、会员与管理员预览入口 |
| V04 | plans.html | 30、90、365 天套餐与权益 |
| V05 | checkout.html | 购买与续费确认、模拟付款与线下待确认示例 |
| V06 | schedule.html | 课表、搜索字段、固定筛选结果 |
| V07 | class-detail.html | 场次信息、名额、预约规则与结果入口 |
| V08 | bookings.html | 我的预约、取消与历史记录 |
| V09 | membership.html | 当前会籍、订单与购买周期 |
| V10 | profile.html | 资料、密码修改布局、出勤历史 |
| V11 | admin-dashboard.html | 管理概览、当日课程与容量 |
| V12 | admin-members.html | 会员搜索、详情与状态 |
| V13 | admin-plans.html | 套餐版本、订单与收款确认示例 |
| V14 | admin-resources.html | 教练、场地、课程模板管理 |
| V15 | admin-schedule.html | 排课布局、名单、签到与结课示例 |
| V16 | admin-reports.html | 收入、销量、出勤与缺席统计示例 |

辅助页面是同一功能的不同状态，不把它们算成额外核心业务。`walkthrough.html` 是演示导航，`states.html` 是失败与空状态展示。

### 怎么演示

1. **购买或续费（W01、W02）**：Membership → Choose 90 days → View simulated payment result → View renewed membership example。
2. **预约（W03）**：Classes → Power Flow → View booking confirmation → View confirmed bookings。
3. **取消（W04）**：在上一流程的 Power Flow 记录点击 Cancellation example → View cancelled booking。
4. **排课与停课（W05）**：Admin demo → Schedule & check-in → 查看发布结果示例，或查看 S106 的已取消名单。
5. **签到、结课与统计（W06）**：排课页 → S101 名单 → Jamie 或 Sam 的签到结果 → 返回名单查看结课示例。Reports 展示固定报表与统计口径。

各场景使用独立的固定数据。注册登录、资源管理、线下收款、固定筛选和异常状态也可从演示导航或相应页面进入。

### 你需要能讲清楚的内容

**HTML 是内容和结构**：导航、表单标签、课程卡片和表格都直接写在 HTML 中。浏览器打开文件就能显示。

**CSS 是外观和布局**：`assets/css/style.css` 统一颜色、字体、按钮、卡片、表格与手机排版；`assets/css/static.css` 处理纯静态版的导航和结果页面。

**页面跳转靠相对链接**：例如 `href="plans.html"` 表示打开同一文件夹的套餐页。整个文件夹移动到另一台电脑后仍然可用。

**静态结果不等于真实业务成功**：结果页相当于先画好“预约成功之后长什么样”。以后后端确认资格和容量后，才可以显示真实成功结果。

**响应式布局**：电脑上多列排列，手机上改为单列；较宽的表格在自己的区域内左右滚动，不撑宽整张网页。

### 后续怎么接后端

字段、状态和接口路径在联调阶段确认，页面及业务规则对应关系见 [前端交接](docs/frontend/FRONTEND_HANDOFF.md)。
