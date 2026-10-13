KKY PayPal Windows 单账号采集代理
===================================

一、约束
1. 一台 Windows VPS 只配置一个 PayPal 账号。
2. PayPal Client ID / Client Secret 只保存在该 VPS，不上传到 ERP。
3. ERP 仅依据 JSON 中的 Merchant ID 归属数据。

二、VPS 首次使用（VPS 不需要安装 Python）
1. 把 release 目录中的 paypal_agent.exe、config.json、README.txt 一起复制到：
   D:\paypal-agent
2. 用记事本打开 config.json，填写该 VPS 对应账号的：
   paypal_client_id
   paypal_client_secret
   paypal_merchant_id
   erp_import_secret
3. paypal_environment 正式账号填写 live，沙盒账号填写 sandbox。
4. 请保留 JSON 的英文双引号、逗号和 true，不要写注释。
5. 打开 CMD 或 PowerShell，先测试 ERP 接收链路（不会访问 PayPal）：
   cd /d D:\paypal-agent
   paypal_agent.exe --test-import
6. 测试成功后执行一次正式采集：
   paypal_agent.exe

可选：配置文件不与 EXE 放在一起时，可指定完整地址：
   paypal_agent.exe --config "D:\paypal-agent\account-a.json"

三、Windows 定时任务
程序/脚本：D:\paypal-agent\paypal_agent.exe
参数：留空
起始于：D:\paypal-agent

执行频率建议先设置为每小时一次。程序带有单实例锁，上一轮尚未结束时不会并发运行。

四、目录
pending：尚未被 ERP 接收的 JSON，下次运行优先重传。
archive：ERP 已接收的 JSON 历史文件。
failed：预留的人工异常文件目录。
logs：每日运行日志。
state.json：最近一次完整成功的时间游标。

五、注意
1. Windows 系统时间必须准确，否则 ERP 会拒绝超过允许时间偏差的签名。
2. ERP 返回未知 Merchant ID 时，先在“PayPal账号风控”页面创建完全相同的 Merchant ID。
3. 返回 413 时，需要检查宝塔/Nginx client_max_body_size 和 ERP 的 LOGISTICS_PAYPAL_IMPORT_MAX_BYTES。
4. config.json 包含明文密钥，只保存在对应 PayPal 账号的 VPS，不要在不同账号 VPS 之间混用。
5. 更新 EXE 时只替换 paypal_agent.exe，不要覆盖已填写的 config.json、state.json、pending 和 archive。

六、开发机重新打包
开发机需要 Python，VPS 不需要。右键使用 PowerShell 运行 build_exe.ps1，成品会生成到 release 目录。
