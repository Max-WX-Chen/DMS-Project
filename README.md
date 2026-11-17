# FORM Club 前端静态网页

这份网页用于 COMP3013 项目 11 月 27 日的静态阶段。主题是健身中心会员与团课预约系统。界面采用英文，使用说明和交接文档采用中文。

当前交付：**16 个主要页面，以及用于展示不同课程、套餐、成功、失败和筛选结果的辅助页面**。正式提交目录 `submission/` 总计 63 个 HTML 文件和 2 个 CSS 文件，使用固定示例数据，没有 PHP、JavaScript 或数据库依赖。

## 怎样打开

1. 解压 `FORM_Club_Static_HTML_CSS.zip`，保留全部目录结构。
2. 双击 `index.html`，用 Edge、Chrome 等浏览器打开。
3. 点击页面最上方的 **Demo walkthrough**，可以按业务流程逐步浏览。
4. 页脚的 **Admin demo** 进入管理员页面；登录页也有 Member preview 和 Admin preview 两个入口。

不要直接在压缩包内预览单个 HTML，也不要只把首页发给别人。CSS 和其他页面需要一起保留。

## 应该提交什么

- `submission/` 或对应的 `FORM_Club_Static_HTML_CSS.zip` 是本次静态提交文件。
- 组长在提交前按老师的命名要求补上实际组号。组号尚未提供，因此没有编造组号。
- `docs/` 是给组员理解与后续接后端使用的资料，不是老师要求的 HTML/CSS 页面。
- 工作目录中的 `static/` 是开发期间保留的可选浏览器交互原型，不属于本次提交包。该原型不是已完成的动态网站，也没有连接后端。
- `build_pages.py` 和 `build_static_submission.py` 是维护用生成脚本。打开最终网页不需要 Python。网页已经生成，直接修改 `submission/` 中的 HTML/CSS 也可以；若以后重跑生成脚本，手工修改会被覆盖，请先保留改动。

## 16 个主要页面

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

## 怎么演示

1. **购买或续费**：首页 → Membership → Choose 90 days → View simulated payment result → View renewed membership example。
2. **预约**：Classes → Power Flow → View booking confirmation → View confirmed bookings。
3. **取消**：上一流程的 Cancellation example → View cancelled booking，取消后保留记录。
4. **排课**：Admin demo → Schedule & check-in → 排课表单下面的结果链接。
5. **签到**：Demo walkthrough → Check in and complete → View check-in result → View updated roster example。
6. **收款与统计**：Plans & orders → receipt confirmation；Reports 查看固定报表和统计口径。

各场景相互独立。表单用于展示计划中的输入项目，链接展示预先写好的结果。输入任意邮箱不会真正注册，点付款不会扣钱，点预约不会占用真实名额。静态阶段这是预期行为。

## 你需要能讲清楚的内容

**HTML 是内容和结构**：导航、表单标签、课程卡片和表格都直接写在 HTML 中。浏览器打开文件就能显示。

**CSS 是外观和布局**：`assets/css/style.css` 统一颜色、字体、按钮、卡片、表格与手机排版；`assets/css/static.css` 处理纯静态版的导航和结果页面。

**页面跳转靠相对链接**：例如 `href="plans.html"` 表示打开同一文件夹的套餐页。整个文件夹移动到另一台电脑后仍然可用。

**静态结果不等于真实业务成功**：结果页相当于先画好“预约成功之后长什么样”。以后后端确认资格和容量后，才可以显示真实成功结果。

**响应式布局**：电脑上多列排列，手机上改为单列；较宽的表格在自己的区域内左右滚动，不撑宽整张网页。

## 后续怎么接后端

页面和字段映射见 `docs/FRONTEND_HANDOFF.md`。前后端还没有确认接口契约，本次没有把建议 URL 当作已实现 API。接入时需要替换固定内容，并让 PHP 实际执行身份检查、输入校验、事务和数据库操作。

## 本次验证范围

正式提交版在 Edge 无头浏览器中、**关闭 JavaScript** 后检查全部页面的链接、标题、重复 ID 和 1440 / 390 像素宽度的页面溢出。实际点击验证购买续费、预约取消、签到、筛选与空列表路径，并查看了代表页面的截图。

最终还核对了 63 页的 1,248 处链接与资源引用，未发现失效。11 月 16 日的浏览器复测因本机启动/调试连接错误未完成；保留此前通过的浏览器检查及截图，具体范围和最后的小幅样式调整见 `docs/tasks/T01_Static_Frontend.md`。

没有完成数据库接入、真正登录、服务器权限、并发、查询性能测试或部署；这些属于后续动态阶段。
