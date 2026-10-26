# 支付中台设计校验记录

## 参考草图

- `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-432515d5-14ff-4bbf-bc0f-072d0306fc4b.png`（工作台，888 × 595）
- `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-f36845e8-d79d-44a2-b303-0ef565115e32.png`（收款账户风控，873 × 590）
- `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-f8988545-26a9-4836-8370-7c625d6df897.png`（产品风险与利润，881 × 570）
- `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-3b860836-0dc6-4475-a330-2f71c5aadd61.png`（产品预警，907 × 588）
- `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-04583109-a8d8-4fcc-aaba-6a86541fe20b.png`（支付订单检索，886 × 594）
- `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-c49ddac9-de88-4673-ac94-b02e2eb3906e.png`（订单详情弹窗，890 × 597）

## 已实现的页面对应关系

| 草图 | 实现文件 | 布局要点 |
| --- | --- | --- |
| 工作台 | `resources/views/portals/payment/workbench.blade.php` | 顶部状态筛选、4 个指标卡、2 个导航内容卡、待办与数据状态卡 |
| 收款账户风控 | `resources/views/portals/payment/account-risk.blade.php` | 筛选栏、4 个指标卡、双商户账户看板和风险标签 |
| 产品风险与利润 | `resources/views/portals/payment/product-risk-profit.blade.php` | 筛选栏、指标卡、风险/损失/退款页签和产品明细卡 |
| 产品预警 | `resources/views/portals/payment/product-alerts.blade.php` | 筛选栏、预警统计、预警表、跟进说明和配置说明 |
| 支付订单检索 | `resources/views/portals/payment/payment-orders.blade.php` | 筛选栏、指标卡、订单表、返回说明、订单详情弹窗 |

## 交互范围

- 菜单页面及工作台跳转均使用门户 `ppay` 的前端路由。
- 筛选、页签、按钮和订单详情弹窗均为本地模拟交互，不请求或写入真实业务数据。
- 可见文案由 `lang/zh-CN/payment.php` 提供；页面、控制器和前端脚本不含中文硬编码。

## 已完成的静态校验

- `payment-portal.js` 已通过 `node --check` 语法校验。
- 已确认 4 个支付中台路由及 `ppay` 首页工作台分发存在。
- 已确认所需实现文件全部存在，且页面/控制器引用的翻译分组可在 `lang/zh-CN/payment.php` 中找到。

## 未完成的运行时视觉验收

- 当前环境未发现 PHP、Composer，且 `npm run build` 因缺少已安装的 Vite 命令而无法执行；因此不能启动 Laravel 页面并捕获与草图同尺寸的浏览器截图。
- 在具备项目依赖的开发环境中，应按草图尺寸依次打开 `ppay` 首页及 4 个菜单页面，点击任意订单的“查看详情”验证弹窗，再对照本记录的布局要点做最终视觉核验。

## 2026-10-26 首页顶部与指标卡修订

**Comparison target**

- Source visual truth: `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-20ab4ac5-a989-4631-86a2-5b415e7782fc.png`、`C:\Users\EDY\AppData\Local\Temp\codex-clipboard-625d823a-d738-4ce2-8389-a48369844de5.png`、`C:\Users\EDY\AppData\Local\Temp\codex-clipboard-59cc50b2-65ed-47f3-9e3a-25a709077e0b.png`、`C:\Users\EDY\AppData\Local\Temp\codex-clipboard-3e490f79-56e4-4c8e-9d44-f7b08efa3812.png`。
- Intended state: `ppay` 门户首页，桌面端，显示标题/问候/日期、3 个数据范围标签和 4 张指标卡。
- Implementation screenshot path: unavailable. 当前环境没有可运行的 PHP、Composer 和 Vite 依赖，无法启动 Laravel 并以相同视口捕获页面；因此无实现像素尺寸、CSS 视口尺寸或密度归一化数据。

**Findings**

- [P1] 浏览器视觉对比尚未执行。
  Location: 支付中台首页。
  Evidence: 源视觉文件可用，但没有浏览器渲染截图。
  Impact: 无法从实际像素层面确认标题区、筛选标签和图标卡的间距、换行、字体及色彩。
  Fix: 在具备 Laravel 依赖的环境中打开 `ppay` 首页，以参考草图对应的桌面视口截图，再进行全视图及顶部区域的聚焦对比。

**Required fidelity surfaces**

- Fonts and typography: 已在代码中采用系统既有字体与物流 PP 风控页的字号层级；待浏览器截图确认字重、换行与光栅化效果。
- Spacing and layout rhythm: 顶部标题区和 4 列指标网格已按参考卡片的 12–13px 间距、8px 圆角和约 104px 卡片高度实现；待截图确认。
- Colors and visual tokens: 图标底色复用物流 PP 风控页的蓝、青、橙、红语义色；待截图确认。
- Image quality and asset fidelity: 参考区域没有定制位图资产；图标使用系统已加载的 Font Awesome 图标库。
- Copy and content: 新增可见文案均位于 `lang/zh-CN/payment.php`。

**Implementation checklist**

1. 在可运行 Laravel 的环境中清除缓存并打开 `ppay` 首页。
2. 捕获与参考草图相同状态的全视图和顶部区域截图。
3. 对照标题、数据标签、4 张图标卡完成视觉比对并修复 P1/P2 差异。

**Comparison History**

- 2026-10-26（修订前）：实现截图 `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-fb5b1fd1-febb-4bf9-8117-631da743ed36.png` 与参考图 `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-3e490f79-56e4-4c8e-9d44-f7b08efa3812.png` 对比，发现 [P1] 4 张首页指标卡纵向堆叠，未达到桌面端同排卡片网格。实现截图 `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-e29c28c5-1bab-4ca4-b75d-03f1bdba168f.png` 同时显示下方工作台面板未获得预期卡片布局。
- 已修复：新增 `public/assets/erp/payment-workbench.css`，并由工作台视图在共享样式后加载。新样式在桌面端以 `repeat(4, minmax(0, 1fr))` 强制 4 列网格，同时完整定义工作台状态标签、导航面板、待办和快速检索布局。
- 修订后视觉证据：尚未提供；需要以相同的桌面端页面状态捕获新的实现截图后，才能完成全视图和顶部区域的聚焦对比。
- 2026-10-26（第二次修订前）：实现截图 `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-e019626a-4eff-4dfe-a439-bb60ca87bdf6.png` 显示首页内容区的横向留白比物流 PP 风控页更大，属于 [P2] 布局宽度偏差。
- 已修复：工作台独立样式将 `.payment-workbench-page` 设为 `width: 100%; max-width: none;`，移除了 `1520px` 宽度上限，并将样式资源版本更新为 `payment-workbench.css?v=20261026-2` 以避开浏览器缓存。
- 2026-10-26（收款账户风控页）：筛选参考图为 `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-e78b48df-2bb8-4d44-9c59-a0c5496c080a.png`，页面布局参考 `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-f36845e8-d79d-44a2-b303-0ef565115e32.png` 与首页图标指标卡。已新增页面标题、全宽独立容器、系统筛选面板和 4 列图标指标卡；查询与重置仅操作本地模拟筛选结果。
- 收款账户风控页的修订后浏览器截图尚未提供，因此无法完成与筛选参考图的同视口、全视图及筛选区域聚焦对比。

final result: blocked

## 2026-10-26 产品风险与利润真实数据接入

**数据来源**

- 复用物流订单系统 `LogisticsProductStatisticsService`，不复制产品、订单、退款或纠纷数据表。
- 产品列表、销售额、PayPal 退款与纠纷、信用卡退款、退款原因及详情明细均使用同一真实统计口径。

**实现校验**

- 筛选、异常产品条件、排序与分页均由服务端处理。
- 前端列表采用物流产品统计式表格，并保持支付中台卡片、宽度和筛选风格。
- 产品详情包含经营概览、PayPal 订单、信用卡订单、退款记录、PayPal 纠纷及关联物流订单。
- 成本数据不完整时，净利润明确显示待成本数据接入，不输出模拟利润。
- 所有新增可见文字均由 `lang/zh-CN/payment.php` 提供，静态资源缓存版本号未修改。

**受限校验**

- JavaScript 语法检查与 `git diff --check` 已执行。
- 当前环境未安装 PHP/Composer，无法执行 Laravel 路由及数据库集成测试。
- 当前环境没有运行中的本地站点，无法执行浏览器截图视觉回归。

final result: blocked

## 2026-10-26 收款账户纠纷记录详情

**对照目标**

- 参考图：`C:\Users\EDY\AppData\Local\Temp\codex-clipboard-bc7cbacb-e85b-4ada-8fab-39019b422948.png`。
- 纠纷记录采用案件列表、选中案件详情和处理闭环布局。

**实现校验**

- 案件编号、原付款、订单、店铺、原因、阶段、金额、渠道状态、结果、回复期限及关联退款均使用真实纠纷数据。
- 点击或通过键盘选择案件行后，下方详情区域同步更新。
- 数据源尚无负责人和下一步动作字段，页面明确显示待分配与补充处理记录，不生成模拟人员。
- 搜索和服务端分页继续保留。

**受限校验**

- 当前环境未安装 PHP/Composer，无法执行 Laravel 集成测试。
- 当前环境没有运行中的站点，无法执行浏览器截图视觉回归。

final result: blocked

## 2026-10-26 收款账户风控真实查询与详情

**实现校验**

- 账户名称和导入状态均在数据库查询阶段过滤；支付中台看板仅统计当前筛选期间，不再额外扫描每个账户的全历史数据。
- “查看详情”已连接物流 PayPal 共用真实数据口径，支持账户概览、支付订单、商品统计、退款记录、争议案件和明细分页。
- 详情筛选沿用页面付款期间；明细搜索、重置、标签切换和分页均通过只读接口加载，不修改业务数据。
- 新增账户功能保持写入共用 PayPal 账户表，本次没有新增流水表、外部 PayPal API 或授权映射。

**静态校验**

- `payment-portal.js` 已通过 Node 语法检查。
- `git diff --check` 已通过。
- 当前环境未安装 PHP/Composer，无法执行 PHP 语法检查、Laravel 路由测试和数据库集成测试。
- 当前环境没有可运行的 Laravel 站点与浏览器会话，无法生成同视口截图进行视觉回归。

final result: blocked

## 2026-10-26 收款账户详情风险概览布局

**对照目标**

- 参考图：`C:\Users\EDY\AppData\Local\Temp\codex-clipboard-2da3f80f-c675-47c3-bcce-143acfa3ee00.png`。
- 详情弹窗采用顶部六标签、四张指标卡、资金与风险双栏、趋势与待办双栏的固定布局。

**实现校验**

- 风险概览的订单、退款、纠纷、异常事项和趋势均来自当前账户与当前日期筛选下的真实导入数据。
- 订单明细、产品表现、退款记录和纠纷记录继续使用真实明细、搜索与分页。
- 资金与官方对账中，内部收款、退款和风险金额展示真实汇总；官方余额、准备金和官方对账结论明确显示待接入。
- 所有新增可见文字均由 `lang/zh-CN/payment.php` 提供。

**受限校验**

- 当前环境未安装 PHP/Composer，无法执行 Laravel 集成测试。
- 当前环境没有可运行站点与浏览器会话，无法生成同视口截图进行视觉回归。

final result: blocked

## 2026-10-26 收款账户风控真实数据与新增账户

**校验范围**

- 账户看板现读取物流系统共用的 PayPal 账户和账务汇总；新增账户弹窗提交至同一张账户表。
- 当前环境没有 PHP、Composer 依赖和可运行的 Laravel 服务，无法进行浏览器截图。

**静态检查**

- 已确认账户风控页的筛选使用 GET 提交，新增弹窗使用受 CSRF 保护的 POST 提交。
- 已确认新增路由、控制器动作、共用风控数据服务和翻译文案键已连通。
- 已用 Node.js 检查 JavaScript 语法；当前没有 PHP 运行时，无法执行 PHP 语法检查和 Laravel 集成测试。

final result: blocked

## 2026-10-26 收款账户卡片精细化与候选层修复

**Comparison target**

- Source visual truth: `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-66405390-0c3d-4777-9090-fc48ae9ced3e.png`, `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-4f07a31b-1f9a-463e-8878-d5728b7dc020.png`.
- Intended state: 账户卡保留支付中台的两张本地模拟账户与最多四列布局，但使用 PP 风控页的身份区、统计分区、风险进度条和底栏层次；账户候选层不得被筛选面板裁切。
- Implementation screenshot path: unavailable. PHP、Composer 和前端构建依赖仍不可用，无法启动 Laravel 页面并捕获同视口的实现截图。

**Implemented checks**

- 候选层的外层筛选面板已改为可见溢出，并通过定位层级置于 KPI 卡片上方。
- 每个模拟风险指标都带有本地模拟进度宽度、风险颜色、数值和说明；账户卡同时加入 PayPal 身份图标、四项统计面板和状态底栏。

final result: blocked

## 2026-10-26 收款账户候选框与看板列数修订

**Comparison target**

- Source visual truth: `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-bcab7a76-a211-47e1-be12-9dc5c0be3b06.png`.
- Intended state: 收款账户候选项使用系统风格的白色页内下拉层，不使用浏览器原生黑色候选层；账户看板桌面端每行最多四张卡片，账户不足四张时保留自然的左对齐顺序。
- Implementation screenshot path: unavailable. PHP、Composer 和前端构建依赖仍不可用，无法启动 Laravel 页面并捕获同视口的实现截图。

**Implemented checks**

- 原生 `datalist` 已替换为页面内可输入候选框；输入、点击候选项和点击页面空白处关闭候选层均为本地前端行为。
- 看板网格在桌面端使用四列，窗口变窄时依次调整为三列、两列和单列；未添加任何额外模拟账户。

final result: blocked

## 2026-10-26 收款账户风控筛选控件修订

**Comparison target**

- Source visual truth: `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-a3484997-1d6b-46a4-b104-837a0eb8bc6f.png`.
- Intended state: 收款账户风控页的付款批次为起止日期筛选，数据截至为日期时间筛选；收款账户支持手动输入和本地模拟候选项选择。
- Implementation screenshot path: unavailable. PHP、Composer 和前端构建依赖仍不可用，无法启动 Laravel 页面并捕获同视口的实现截图。

**Implemented checks**

- 付款批次已由只读文本改为两个原生日期控件，数据截至已改为原生日期时间控件。
- 收款账户已由单选下拉改为 `input[list]` 组合输入框；本地模拟的商户 A / 商户 B 会作为候选项，手动输入商户名称或内部模拟编码均会筛选卡片。
- 新增界面文案均引用 `lang/zh-CN/payment.php`，候选值来自页面已有的本地模拟商户数据。

**Blocked visual QA**

- No runnable Laravel/browser capture is available in this environment, so same-viewport visual comparison with the reference image cannot be completed.

**Interaction check**

- The selected date range now filters against the local mock account period, and the cutoff datetime filters against the local mock snapshot. It does not request or modify real business data.

final result: blocked

## 2026-10-26 五页共用视觉基础与运营页校验

**Comparison target**

- Source visual truth: `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-432515d5-14ff-4bbf-bc0f-072d0306fc4b.png`, `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-f36845e8-d79d-44a2-b303-0ef565115e32.png`, `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-f8988545-26a9-4836-8370-7c625d6df897.png`, `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-3b860836-0dc6-4475-a330-2f71c5aadd61.png`, `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-04583109-a8d8-4fcc-aaba-6a86541fe20b.png`, `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-c49ddac9-de88-4673-ac94-b02e2eb3906e.png`.
- Intended state: 五个支付中台页面共用全宽内容区、筛选面板、四列 KPI 卡、内容卡片、表格和响应式断点；首页与账户风控保留各自业务专属布局。
- Implementation screenshot path: unavailable. 当前环境无法运行 Laravel 页面并生成同视口浏览器截图。

**Implemented checks**

- 五个页面均加载 `payment-shared.css`，使基础间距、图标 KPI、面板、状态标签和窄屏断点来自同一份样式。
- 产品风险与利润、产品预警、支付订单检索均使用与账户风控一致的系统筛选面板、查询和重置行为；筛选仅作用于本地模拟记录。
- 产品风险保留三栏经营数据和结论区；产品预警保留预警表格及双卡说明；支付订单保留订单表格、说明卡和详情弹窗。
- 支付订单的账户、渠道、扣款类型、成功付款期均已映射到本地模拟行数据；未接入真实数据读写。

**Blocked visual QA**

- No runnable Laravel/browser capture is available in this environment, so same-viewport visual comparison with the reference images cannot be completed.

final result: blocked

## 2026-10-26 收款账户风控新增账户入口

**Comparison target**

- Source visual truth: `C:\Users\EDY\AppData\Local\Temp\codex-clipboard-e15b8335-5e1b-4567-8966-2166f91672d0.png`.
- Intended state: 账户看板标题行右侧保留看板说明，并增加原 PP 风控页同类的“新增 PP 账户”主按钮；点击后展示账户名称、环境、Merchant ID、邮箱与启用状态的表单弹窗。
- Implementation screenshot path: unavailable. 当前环境无法启动 Laravel 页面并生成同视口浏览器截图。

**Implemented checks**

- 新增按钮位于“账户看板”标题行的右侧操作区，使用支付中台既有主按钮颜色、圆角与图标库。
- 弹窗沿用原 PP 风控页的表单字段层级与视觉风格；必填校验由浏览器原生校验执行。
- 提交仅触发本地模拟成功提示并关闭、重置表单，不请求接口，也不写入真实 PayPal 账户数据。

**Blocked visual QA**

- No runnable Laravel/browser capture is available in this environment, so same-viewport visual comparison with the reference image cannot be completed.

final result: blocked
