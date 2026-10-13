KKY PayPal Windows 单账号采集代理（BAT + PowerShell 免安装版）
==========================================================

一、运行要求
1. 不需要安装 Python。
2. 不需要安装 Visual C++ 运行库。
3. 使用 Windows 自带的 Windows PowerShell 3.0 或更高版本。
4. 一台 Windows VPS 只配置一个 PayPal 账号。

二、首次配置
1. 将发布包解压到：D:\paypal-agent
2. 用记事本打开 config.json，填写：
   paypal_client_id：当前 PayPal 账号的 Client ID
   paypal_client_secret：当前 PayPal 账号的 Client Secret
   paypal_merchant_id：当前 PayPal 账号的 Merchant ID
   paypal_environment：正式账号填写 live，沙盒账号填写 sandbox
   erp_import_secret：必须与 ERP 的 LOGISTICS_PAYPAL_IMPORT_SECRET 完全一致
3. config.json 必须保留英文双引号、逗号和 true，不能写注释。

三、测试流程
1. 双击 test_erp_import.bat。
2. 该测试只验证 VPS 到 ERP 的签名上传链路，不访问 PayPal，也不推进采集时间游标。
3. 显示 ERP connection test succeeded 即为成功。
4. 测试成功后，双击 run_paypal_agent.bat 执行第一次正式采集。

四、Windows 定时任务
程序或脚本：C:\Windows\System32\cmd.exe
添加参数：/c D:\paypal-agent\run_paypal_agent.bat
起始于：D:\paypal-agent
建议：每小时运行一次；勾选“不管用户是否登录都要运行”。

五、运行目录
pending：尚未被 ERP 接收的 JSON；下次运行优先重传。
archive：ERP 已接收的 JSON 历史文件。
failed：预留的人工异常目录。
logs：每日运行日志。
state.json：最近一次完整成功的采集时间游标。

六、注意事项
1. Windows 系统时间必须准确，否则 ERP 会拒绝超过允许时间偏差的签名。
2. ERP 返回未知 Merchant ID 时，先在“PayPal账号风控”页面创建完全相同的 Merchant ID。
3. 每台 VPS 的 config.json 仅填写本机 PayPal 账号，不要跨账号复制已填写的配置。
4. 更新程序时不要覆盖 config.json、state.json、pending 和 archive。
5. 发生错误时查看 logs 目录内当天的日志文件。
