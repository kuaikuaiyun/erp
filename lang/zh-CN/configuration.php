<?php

return [
    'menu' => [
        'configuration_center' => '配置中心',
        'login_captcha' => '人机验证配置',
        'watermark' => '文字水印',
        'basic' => '基本配置',
    ],

    'status' => [
        'enabled' => '已启用',
        'disabled' => '未启用',
        'configured' => '已配置',
        'not_configured' => '未配置',
    ],

    'login' => [
        'subtitle' => '请输入管理员账户信息以继续访问系统',
        'captcha_heading' => '安全验证',
        'tencent_hint' => '点击“登录”后完成腾讯安全验证。',
        'footer' => '安全登录 · :name',
        'captcha_failed' => '人机验证失败、已过期或验证服务暂不可用，请重新验证后再试。',
        'username_required' => '请输入用户名。',
        'password_required' => '请输入密码。',
        'rate_limited' => '登录尝试过于频繁，请在 :seconds 秒后重试。',
        'tencent' => [
            'load_failed' => '腾讯安全验证加载失败，请检查网络后重试。',
            'cancelled' => '已取消腾讯安全验证，请重新点击登录。',
            'failed' => '腾讯安全验证未通过，请重新验证。',
        ],
    ],

    'captcha' => [
        'title' => '人机验证配置',
        'intro' => [
            'title' => '登录安全保护',
            'description' => '启用后，管理员必须通过当前选择的人机验证才能完成登录。服务端会再次验证票据；密钥加密保存，默认只显示圆点掩码。',
        ],
        'enabled' => [
            'title' => '启用状态',
            'checkbox' => '启用后台登录人机验证',
            'help' => '关闭时不会加载第三方验证脚本；登录失败限流仍然生效。',
        ],
        'provider' => [
            'title' => '验证服务',
            'help' => '选择一个服务并填写相应配置。Cloudflare Turnstile 更适合作为默认选项；腾讯云天御验证码适合中国大陆网络环境。',
        ],
        'providers' => [
            'turnstile' => [
                'name' => 'Cloudflare Turnstile',
                'description' => '无需 Google 客户端脚本，适合后台登录保护。',
            ],
            'recaptcha_v2' => [
                'name' => 'Google reCAPTCHA v2',
                'description' => '仅在 Google reCAPTCHA 服务可稳定访问时使用。',
            ],
            'tencent_captcha' => [
                'name' => '腾讯云天御验证码 2.0',
                'description' => '登录时弹出腾讯安全验证窗口，并由服务端二次核验票据。',
            ],
        ],
        'fields' => [
            'turnstile_site_key' => 'Turnstile Site Key',
            'turnstile_secret_key' => 'Turnstile Secret Key',
            'recaptcha_v2_site_key' => 'reCAPTCHA Site Key',
            'recaptcha_v2_secret_key' => 'reCAPTCHA Secret Key',
            'tencent_captcha_app_id' => '腾讯云 CaptchaAppId',
            'tencent_captcha_app_secret_key' => '腾讯云 AppSecretKey',
            'tencent_captcha_secret_id' => '腾讯云 API SecretId',
            'tencent_captcha_secret_key' => '腾讯云 API SecretKey',
            'max_attempts' => '最大失败次数',
            'decay_seconds' => '冷却时间（秒）',
        ],
        'tencent' => [
            'app_id_help' => '在腾讯云验证码控制台“验证管理”的基础配置中获取，只填写数字形式的 CaptchaAppId。',
        ],
        'rate_limit' => [
            'title' => '登录失败限流',
            'max_attempts_help' => '同一用户名与 IP 的连续失败次数上限。',
            'decay_seconds_help' => '达到失败次数后必须等待的时长。',
        ],
        'secret' => [
            'reveal' => '显示明文',
            'configured_help' => '已配置；点击小眼睛可临时查看明文，刷新页面后将恢复掩码。',
            'not_configured_help' => '尚未配置；留空不会覆盖现有密钥。',
            'clear' => '清除当前密钥',
            'reveal_failed' => '无法读取密钥，请刷新页面后重试。',
        ],
        'audit' => [
            'title' => '配置操作记录',
            'time' => '时间',
            'operator' => '操作人',
            'item' => '项目',
            'action' => '操作',
            'result' => '结果',
            'empty' => '暂无配置操作记录',
            'revealed' => '查看密钥',
            'updated' => '更新',
            'labels' => [
                'enabled' => '启用状态',
                'provider' => '验证服务',
                'turnstile_site_key' => 'Turnstile Site Key',
                'turnstile_secret_key' => 'Turnstile Secret Key',
                'recaptcha_v2_site_key' => 'reCAPTCHA Site Key',
                'recaptcha_v2_secret_key' => 'reCAPTCHA Secret Key',
                'tencent_captcha_app_id' => '腾讯云 CaptchaAppId',
                'tencent_captcha_app_secret_key' => '腾讯云 AppSecretKey',
                'tencent_captcha_secret_id' => '腾讯云 SecretId',
                'tencent_captcha_secret_key' => '腾讯云 SecretKey',
                'max_attempts' => '最大失败次数',
                'decay_seconds' => '冷却时间',
            ],
        ],
        'actions' => [
            'save' => '保存配置',
        ],
        'notifications' => [
            'saved_title' => '保存成功',
            'saved_message' => '人机验证配置已更新；新的配置将在下一次登录时生效。',
        ],
        'validation' => [
            'provider_required' => '请选择验证服务。',
            'provider_invalid' => '所选验证服务无效。',
            'tencent_app_id_numeric' => '腾讯云 CaptchaAppId 必须为数字。',
            'max_attempts_between' => '失败次数必须介于 1 到 20 之间。',
            'decay_seconds_between' => '冷却时间必须介于 30 到 3600 秒之间。',
            'selected_site_key_required' => '启用时必须填写当前验证服务的 Site Key。',
            'selected_secret_required' => '启用时必须填写或保留当前验证服务的 Secret Key。',
            'tencent_app_id_required' => '启用腾讯云天御验证码时必须填写 CaptchaAppId。',
            'tencent_secret_required' => '启用腾讯云天御验证码时必须填写或保留全部密钥。',
        ],
    ],

    'permission' => [
        'watermark' => '配置中心 - 文字水印',
        'basic' => '配置中心 - 基本配置',
    ],

    'watermark' => [
        'separator' => ' / ',
        'form' => [
            'title' => '文字水印配置',
            'intro' => '水印仅显示在已登录的后台页面，用于截图追溯，不影响鼠标点击与正常操作。',
            'save' => '保存配置',
        ],
        'fields' => [
            'enabled' => '启用状态',
            'content_mode' => '内容模式',
            'custom_content' => '自定义内容',
            'opacity' => '透明度',
            'rotation' => '倾斜角度',
            'spacing_x' => '横向间距',
            'spacing_y' => '纵向间距',
            'font_size' => '字体大小',
            'color' => '文字颜色',
        ],
        'enabled' => [
            'checkbox' => '启用后台文字水印',
            'help' => '关闭后，所有已登录后台页面均不再显示水印。',
        ],
        'modes' => [
            'template_1' => '模板 1：姓名 · 用户名',
            'template_2' => '模板 2：姓名 · 用户名 · 当前时间',
            'custom' => '自定义内容',
        ],
        'custom_content' => [
            'placeholder' => '例如：内部资料 · 请勿外传',
            'help' => '最多 120 个字符，仅按文字绘制，不会执行 HTML 或脚本。',
        ],
        'help' => [
            'opacity' => '5% 至 70%，建议 12% 至 25%。',
            'rotation' => '-90° 至 90°，建议 -35° 至 -20°。',
            'spacing_x' => '80 至 640 像素，越小越密集。',
            'spacing_y' => '80 至 640 像素，建议略小于横向间距。',
            'font_size' => '10 至 36 像素。',
            'color' => '请输入 6 位十六进制颜色，例如 #64748B。',
        ],
        'preview' => [
            'title' => '效果预览',
            'realtime' => '实时预览',
            'custom_fallback' => '自定义水印内容',
        ],
        'changes' => [
            'title' => '最近变更',
            'time' => '时间',
            'operator' => '操作人',
            'item' => '项目',
            'new_value' => '变更后',
            'empty' => '暂无配置变更记录',
            'empty_value' => '-',
        ],
        'notifications' => [
            'saved_title' => '保存成功',
            'saved_message' => '文字水印配置已更新，并将在下一次页面加载时生效。',
        ],
        'validation' => [
            'enabled.boolean' => '水印开关格式无效。',
            'content_mode.required' => '请选择水印内容模式。',
            'content_mode.in' => '所选水印内容模式无效。',
            'custom_content.string' => '自定义水印文字格式无效。',
            'custom_content.max' => '自定义水印文字不能超过 :max 个字符。',
            'custom_content.required_for_custom' => '选择自定义内容时，请填写水印文字。',
            'opacity.required' => '请填写透明度。',
            'opacity.integer' => '透明度必须为整数。',
            'opacity.between' => '透明度必须介于 :min% 至 :max% 之间。',
            'rotation.required' => '请填写倾斜角度。',
            'rotation.integer' => '倾斜角度必须为整数。',
            'rotation.between' => '倾斜角度必须介于 :min° 至 :max° 之间。',
            'spacing_x.required' => '请填写横向间距。',
            'spacing_x.integer' => '横向间距必须为整数。',
            'spacing_x.between' => '横向间距必须介于 :min 至 :max 像素之间。',
            'spacing_y.required' => '请填写纵向间距。',
            'spacing_y.integer' => '横向间距必须为整数。',
            'spacing_y.between' => '纵向间距必须介于 :min 至 :max 像素之间。',
            'font_size.required' => '请填写字体大小。',
            'font_size.integer' => '字体大小必须为整数。',
            'font_size.between' => '字体大小必须介于 :min 至 :max 像素之间。',
            'color.required' => '请填写文字颜色。',
            'color.regex' => '文字颜色必须为 6 位十六进制格式，例如 #64748B。',
        ],
    ],

    'basic' => [
        'sections' => [
            'maintenance' => '系统维护：',
            'footer' => '底部版权：',
            'login_expiration' => '登录有效期：',
        ],
        'form' => [
            'title' => '基本配置',
            'intro' => '用于统一管理后台登录安全有效期与维护模式。',
            'save' => '保存配置',
        ],
        'fields' => [
            'login_expiration_seconds' => '登录有效期',
            'maintenance_enabled' => '系统维护',
            'maintenance_message' => '维护提示文字',
            'footer_copyright_text' => '版权文字',
            'footer_contact_information' => '联系方式',
        ],
        'maintenance' => [
            'enabled' => [
                'checkbox' => '开启后台维护模式',
                'help' => '开启后，超级管理员可正常使用后台；其他已登录账号访问后台时将看到维护页面。',
            ],
            'message' => [
                'placeholder' => '例如：系统正在维护中，请稍后再试。',
                'help' => '最多 500 个字符，支持换行显示；留空时将使用默认维护提示。',
            ],
        ],
        'footer' => [
            'title' => '底部版权',
            'copyright' => [
                'placeholder' => '例如：XXX 版权所有',
                'help' => '最多 200 个字符；留空时使用系统名称与默认版权文字。',
            ],
            'contact' => [
                'placeholder' => '例如：客服电话：400-000-0000（支持换行）',
                'help' => '最多 500 个字符；填写后页脚将显示“联系我们”，点击后展示联系信息。',
            ],
        ],
        'expiration_options' => [
            'test_15_seconds' => '15 秒（仅用于本地测试）',
            'days_1' => '1 天',
            'days_3' => '3 天',
            'days_7' => '7 天',
        ],
        'expiration' => [
            'help' => '从登录成功时开始计算。达到期限后将强制重新登录；持续操作和勾选“记住我”均不会延长有效期。15 秒选项仅用于本地测试，正式环境请使用天数选项。',
        ],
        'changes' => [
            'title' => '最近变更',
            'time' => '时间',
            'operator' => '操作人',
            'item' => '项目',
            'new_value' => '变更后',
            'empty' => '暂无配置变更记录',
            'empty_value' => '-',
        ],
        'notifications' => [
            'saved_title' => '保存成功',
            'saved_message' => '基本配置已更新，新的设置将在后续访问中生效。',
        ],
        'validation' => [
            'login_expiration_seconds.required' => '请选择登录有效期。',
            'login_expiration_seconds.integer' => '登录有效期格式无效。',
            'login_expiration_seconds.in' => '登录有效期仅支持 15 秒、1 天、3 天或 7 天。',
            'maintenance_enabled.boolean' => '维护开关格式无效。',
            'maintenance_message.string' => '维护提示文字格式无效。',
            'maintenance_message.max' => '维护提示文字不能超过 :max 个字符。',
            'footer_copyright_text.string' => '版权文字格式无效。',
            'footer_copyright_text.max' => '版权文字不能超过 :max 个字符。',
            'footer_contact_information.string' => '联系方式格式无效。',
            'footer_contact_information.max' => '联系方式不能超过 :max 个字符。',
        ],
        'login' => [
            'expired' => '本次登录已到期，请重新登录。',
        ],
    ],

    'footer' => [
        'default_copyright' => ':name 版权所有',
        'contact_link' => '联系我们',
        'contact_toast_title' => '联系方式',
    ],

    'maintenance' => [
        'page_title' => '系统维护',
        'title' => '系统维护中',
        'default_message' => '系统正在维护中，请稍后再试。',
    ],
];


