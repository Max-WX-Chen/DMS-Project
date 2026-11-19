# 在 XAMPP 运行 FORM Club

验证环境为 Windows、Apache 2.4.58、PHP 8.2.12（PDO MySQL、mbstring、session）、MariaDB 10.4.32 / InnoDB。以下命令在仓库根目录执行，以 XAMPP 安装在 `C:\xampp` 为例。应用和每个数据库连接均使用上海时间。独立 Oracle MySQL 环境的兼容性待测。

## 1. 导入空库

在 XAMPP Control Panel 启动 MySQL。用 phpMyAdmin 或 MariaDB 客户端新建 utf8mb4 数据库，例如 `form_club`，选中它，再导入 `database/form_club.sql`。文件建 11 张表、索引、外键和 3 个触发器，插入可立即登录及预约的数据。演示课表日期以导入日为基准。重复部署请选择新的空库。

命令行方式如下。第一行在 PowerShell 执行，后面三行在连接成功的 MariaDB 命令行执行。SOURCE 路径换成实际仓库的绝对路径，使用正斜杠。

```powershell
& C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root -p
```

```sql
CREATE DATABASE form_club CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE form_club;
SOURCE C:/path/to/DMS-Project/database/form_club.sql
```

**课程规模库**可在另一个新库内直接导入交付压缩包中唯一的 `FORM_Club_full.sql`，它保存了测量时的日期及全部历史数据。希望将“明天可约”的示例移至当前日期时，在新库先导入 `form_club.sql` 再导入 `scale_fixture.sql`，由生成器按导入日期重建。仓库提供生成脚本，适合队友复现全库。

## 2. 生成应用账号

在 PowerShell 执行以下命令。默认数据库管理账号为 root，密码为空。已设密码时，先设置当前进程的 `FORM_DB_ADMIN_PASSWORD`；自定义用户名可设 `FORM_DB_ADMIN`。应用账号与随机密码由脚本生成并写入 Git 忽略的 `config/local.php`。已存在的配置会保留。在已有受限账号的环境可直接复制 `config/example.php` 填入连接信息。

```powershell
& C:\xampp\php\php.exe database/configure.php form_club 3306
```

## 3. 启动 Apache

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/start-apache.ps1 -Port 8091
```

在浏览器打开 [套餐 API](http://127.0.0.1:8091/api.php?action=plans.list)。首次导入返回三个套餐和实际 `db_ms`。启动脚本复用 XAMPP Apache/PHP，临时配置仅监听回环地址，以仓库 `public` 目录为站点根。按 Ctrl+C 停止这次启动的 Apache。可用 `-Xampp` 指定安装路径，用 `-Port` 换端口。使用现有 Apache 虚拟主机时，将 DocumentRoot 指向同一 `public` 目录，允许该目录访问并启用 PHP。

|角色|账号|初始数据|
|---|---|---|
|管理员|admin@form.test|可确认线下订单、管理会员、排课和出勤|
|已有会籍的会员|member@form.test|已有一个已付周期、一条历史签到|
|新会员|new@form.test|待确认的 90 天线下订单|

三个教学账号的密码均为 `FormClub2026!`。正式部署时另建账号。接口与事务复现见 [C1 说明](design/billing-v1.md)。

## 4. 测试用库

业务测试会写入数据。另建 `form_http_test` 或 `form_c2_test` 并导入小型 SQL。使用独立的配置文件、数据库账号和 Apache 端口，例如 `tools/start-apache.ps1 -Port 8092 -ConfigPath C:\path\test.php`。配置结构与 `config/example.php` 一致。

- 通过实际 HTTP 测 C1：`python tests/http_workflows.py http://127.0.0.1:8092 --billing-only`。集成了 C2 后去掉最后的标志即可覆盖购买后预约及取消。
- 付款回滚和数据库约束：将 `FORM_CONFIG` 设为专用 `*_test` 库的所有者配置，运行 `C:\xampp\php\php.exe tests/billing_integrity.php`。脚本会临时建立一个故障触发器，检查回滚后自动移除。
- 性能：对新建的规模测试库设置 `FORM_CONFIG`，用有权清理测试行的基准账号运行 `C:\xampp\php\php.exe tests/scale-benchmark.php`。输出 JSON 含每表行数、检查、计时分布、业务查询与 EXPLAIN。

完整规模库的导出命令、空库恢复结果和逐表测量见 [规模与恢复验证](design/scale-validation-v1.md)。
