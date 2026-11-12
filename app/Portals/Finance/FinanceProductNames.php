<?php

namespace App\Portals\Finance;

class FinanceProductNames
{
    // Storefront titles often add a promotion before the name and a variant after it.
    private const NAMES = [
        '200PCS Disposable Stretch Food Covers for Bowls & Plates' => '一次性弹力碗盘保鲜罩（200只）',
        'NYRVELA® Biodegradable Stretch Food Covers, Compostable Bowl & Plate Covers, 200 PCS' => 'NYRVELA 可堆肥降解弹力碗盘保鲜罩（200只）',
        'ROLOOF Disposable Stretch Food Covers — 200 Pack' => 'ROLOOF 一次性弹力保鲜罩（200只）',
        'Novyado Disposable Stretch Food Covers' => 'Novyado 一次性弹力保鲜罩',
        'Disposable Elastic Food Covers - 100 Pack' => '一次性弹力保鲜罩（100只）',
        'Reusable Aluminum Foil Food Covers' => '可重复使用铝箔食品罩',
        '100pcs Disposable Stretch Film for Bowls & Plates' => '一次性碗盘弹力保鲜膜（100只）',
        '90ml Fluffy Hair Shaping Spray Long Lasting Volumizing Mist with Peach Fragrance' => '蜜桃香蓬松定型喷雾（90毫升）',
        'Fluffy Hair Shaping Spray Long Lasting Volumizing Mist with Peach Fragrance' => '蜜桃香蓬松定型喷雾',
        'Novyado Peach-Scented Volumizing & Setting Spray' => 'Novyado 蜜桃香蓬松定型喷雾',
        'Peach-Scented Volumizing & Setting Spray' => '蜜桃香蓬松定型喷雾',
        'Hair Volumizing & Fluffy Shaping Spray' => '头发蓬松定型喷雾',
        '150ML — Root Lift Volumizing Spray' => '发根蓬松喷雾（150毫升）',
        'Fluffy Hair Fix - Flat Hair Volume Spray' => '扁塌发根蓬松喷雾',
        'Novyado Custom-Fit Full Denture Set' => 'Novyado 贴合型全口假牙套装',
        'At-Home Mouldable Full Denture Set' => '居家可塑形全口假牙套装',
        'Portable Denture & Retainer Storage Box with Rinse Basket' => '带沥水篮的便携假牙及保持器收纳盒',
        '2-in-1 Lock Pick & Decoder' => '二合一开锁解码工具',
        'The Everyday Long Scarf' => '日常款长围巾',
        'The Everyday No-Show Brief' => '日常无痕三角内裤',
        'The Cotton Everyday Crew Sock' => '日常棉质中筒袜',
        'The Cotton Striped Sleep Sock' => '棉质条纹睡眠袜',
        'The Soft Knit Gloves' => '柔软针织手套',
        'The Everyday Knit Beanie' => '日常针织便帽',
        'The Everyday Narrow Knit Scarf' => '日常针织窄围巾',
        'The Cotton Striped Boxer' => '棉质条纹平角内裤',
        'The Soft Knit Beret' => '柔软针织贝雷帽',
        'The Cotton Slouch Crew Sock' => '棉质堆堆中筒袜',
        'The Cotton Gingham Boxer' => '棉质格纹平角内裤',
        'The Everyday Knit Gloves' => '日常针织手套',
        'The Striped Crew Sock' => '条纹中筒袜',
        'The Washable Cotton Face Cover' => '可水洗棉质面罩',
        'Peeler and Julienne Slicer Set' => '削皮器与切丝器套装',
        'Novyado Face Wash Gift Set' => 'Novyado 洁面礼盒',
        'Reusable Self-Adhesive False Eyelashes – 3-Pair Glue-Free Lash Set' => '可重复使用自粘免胶假睫毛（3对装）',
        'End the Achy, Twisting Struggle - Open Jars and Bottles with Ease' => '省力开罐开瓶器',
        '3D Relief Art Pen – Turn Flat Drawings Into Touchable 3D Art' => '3D 立体浮雕绘画笔',
        '4-Pack Everyday Charging Cables' => '日常充电线（4条装）',
        'Clear Magnetic Protection Case Screen Protector' => '透明磁吸保护壳及屏幕保护膜',
        'Matte Magnetic Protective Case' => '磨砂磁吸保护壳',
        'Cavity-Repair Natural Enamel Filling Gel' => '牙釉质填补凝胶',
        '5-in-1 Multifunctional Bottle Brush for Kitchen Cleaning' => '厨房五合一多功能瓶刷',
        'Fast-Acting Limescale Remover Spray' => '快速除水垢喷雾',
        'Multi-Blade Herb Scissors | Quick Kitchen Prep' => '厨房多刃香草剪',
        'Flat & Adjustable • Comfortable Fit • Perfect for Jeans & Pants' => '牛仔裤及休闲裤适用的扁平可调款',
        'Instant Adjustable Eyebrow Stamp' => '可调式眉印套装',
        'Eyebrow Stamp Powder Kit with Applicator Brush' => '眉印粉套装（带刷）',
        'TELYSI 40% Urea Cream + 2% Salicylic Acid — 150g' => 'TELYSI 尿素40%和水杨酸2%乳霜（150克）',
        'Clothing emulsifier, Non-Ionic Laundry Pre-Treatment - Your Go-To Laundry Cleaner' => '非离子衣物预处理清洁剂',
        'Sticky Ear Cleaner – Powerful Cleaning, Gentle on Ears' => '粘附式清耳棒',
        '3D Knit Christmas Animal Slipper Socks | Cute Festive Soft Warm Indoor Socks' => '圣诞动物造型针织室内地板袜',
        'PET-EAR RINSE — 100mL + 10 Cotton Swabs' => '宠物洗耳液（100毫升）及10支棉签',
        'No-Rinse Baseball Cap Foam Cleaner' => '棒球帽免水洗泡沫清洁剂',
        'No-Brushing Oral Care Spray for Dogs & Cats' => '犬猫免刷口腔护理喷雾',
        'Ultimate Stain-Melting Foam Cleaner' => '泡沫去渍清洁剂',
        'Changing Foundation Stick – The stick that makes mature skin glow again' => '变色粉底棒',
        '4-in-1 Multi-Enzyme Foam Cleaner' => '四合一多酶泡沫清洁剂',
        'Dual-Sided Pet Grooming Mitt' => '双面宠物梳毛手套',
        'Waterless Carpet Cleaning Foam with Towel' => '免水洗地毯清洁泡沫（附毛巾）',
        'Waterless Carpet Foam Stain Remover with Towel' => '免水洗地毯泡沫去渍剂（附毛巾）',
        'I Like Whisky, Cigars & Maybe 3 People Vintage Washed Cap' => '威士忌与雪茄标语复古水洗帽',
        '250th Anniversary Special Edition Chessboard & Pieces' => '250周年特别版国际象棋套装',
        'GumRevive Periodontal Repair Gel' => 'GumRevive 牙周护理凝胶',
        '24 Days of Dinosaur Christmas Advent Calendar – 24 Collectible Dino Figurines & Holiday Surprises' => '恐龙圣诞倒数日历（含24个恐龙模型）',
        '360° Rotating V-Shaped Closet Organizer' => '360°旋转V形衣柜收纳挂架',
        'Magic Cleaning Cloth (5-Pack)' => '清洁抹布（5条装）',
        'Novyado Satin Pillowcases — Gift' => 'Novyado 缎面枕套（赠品）',
        'Multi-purpose enzyme foam cleaner, versatile – Kitchen & Home' => '厨房及家居多用途酶泡沫清洁剂',
        'Christmas landscape decorations' => '圣诞景观摆件',
        'Boundless Portable Wi-Fi' => 'Boundless 便携式 Wi-Fi',
        '2-Pack 4-Tip Microblading Brow Pens' => '四叉眉笔（2支装）',
        'Cordless Heated Bangs Styling Comb' => '无线加热刘海定型梳',
        'Gemstone-Inspired Sculptural Mug – Artistic Geode-Style Coffee Cup' => '晶洞造型咖啡马克杯',
        'Wireless Heated Root Lift Clip For Flat, Fine Hair' => '无线加热发根蓬松夹',
        'Moisturizing & Strengthening Silky Hair Oil' => '保湿柔顺护发精油',
        'Purple Teeth Whitening Strips' => '紫色牙齿美白贴',
        'Self-Adhesive Leather Refinisher Cuttable Sofa Repair' => '可裁剪自粘沙发皮革修补贴',
        '24-Day Highland Cow Advent Calendar – 24 Mini Figurine Surprises' => '高地牛倒数日历（含24个迷你摆件）',
        'Easy At-Home Nail Care for Smooth, Healthy-Looking Cuticles' => '居家指缘护理用品',
        'Periodontal Repair Gel' => '牙周护理凝胶',
        'Rotating V-Shaped Accessory Hanger' => '旋转V形配饰挂架',
        'No-Rinse Fabric Cleaner Foam' => '免水洗织物泡沫清洁剂',
        'Novyado Cooling Sports Towel — Gift' => 'Novyado 冰感运动毛巾（赠品）',
        'Unisex Grey Coverage Hair Dye with Hair Care' => '男女通用遮白护发染发剂',
        'UV400 Protection Fit Over Glasses Sunglasses' => '可套戴眼镜的 UV400 太阳镜',
        'Mini Nativity Scene in a Keepsake Tin' => '铁盒迷你耶稣诞生场景摆件',
        'Stretchy Wide-Edge Sleeping Cap Set' => '弹力宽边睡帽套装',
        'High-Strength AB Metal Repair Glue' => '高强度 AB 金属修补胶',
        'Bend, Snap & Build Magnetic Figures | 10-Piece Set' => '磁吸拼装人偶（10件套）',
        'Triple Seal Door Draft Stopper For Wind, Noise & Dust Protection' => '三重密封防风防尘门底条',
        'Adhesive Wall-Mounted Mesh Bag Organizer for Kitchen & Closet' => '厨房及衣柜用自粘壁挂网袋收纳架',
        'Tooth Stain Cleaning Brush — Gift 2' => '牙渍清洁刷（赠品2）',
        '3-Pack Compressed Cellulose Sponges, Fruit-Shaped Natural Pop-Up Dish Sponges' => '水果造型压缩纤维素洗碗海绵（3片装）',
        'Ocean Glow Sea Life Ornament Collection – 8 Underwater Designs' => '海洋生物装饰挂件（8款）',
        "Women's Elegant Minimalist Short Slim Wallet" => '女士简约轻薄短款钱包',
        'Embossed Butterfly Christmas Cash Gift Ornament – Holiday Money Holder Keepsake' => '浮雕蝴蝶圣诞现金礼物挂件',
        'Glitter Gel Pen Set – Smooth Writing' => '亮粉中性笔套装',
        'Crystal Clear Windshield Rain Repellent Spray' => '汽车挡风玻璃防雨喷雾',
        'Wood Care Beeswax Oil' => '木材养护蜂蜡油',
        'Universal Car Scratch Repair Spray' => '通用汽车划痕修补喷雾',
        'Waterproof Eyebrow Stamp & Shaping Kit – Natural Full Brow Powder' => '防水眉印及修眉粉套装',
        'Crystal Coating Polish' => '晶体镀膜抛光剂',
        'Shipping Protection' => '运输保障',
        'Shipping Insurance' => '运费险',
        'Freight Insurance' => '运费险',
    ];

    public static function item(object $item): string
    {
        return self::preferred($item->product_title ?? '', $item->tongtu_product_name ?? '');
    }

    public static function preferred(string $original, string $matched): string
    {
        if ($matched !== '' && preg_match('/\p{Han}/u', $matched)) {
            return self::display($matched);
        }
        $localized = self::display($original);
        return preg_match('/\p{Han}/u', $localized) || $matched === '' ? $localized : self::display($matched);
    }

    public static function display(string $value): string
    {
        return implode("\n", array_map(self::line(...), preg_split('/\R/u', $value) ?: [$value]));
    }

    public static function categories(string $value): string
    {
        return implode("\n", array_map(self::category(...), preg_split('/\R/u', $value) ?: [$value]));
    }

    public static function worldpayShortName(string $value): string
    {
        $patterns = [
            '冰丝内裤套装' => 'ice silk.*underwear set|ice silk.*boxer shorts',
            '保暖内衣套装' => 'thermal underwear|long johns',
            '保暖套装' => 'thermal.*sets?',
            '华夫格亨利领保暖衫' => 'waffle.knit.*henley',
            '华夫格保暖衫' => 'waffle.knit.*thermal',
            '防晒衫' => '\bsun shirts?\b',
            '撞色运动套装' => 'contrast web sports suit',
            '工装阔腿裤' => 'wide.leg cargo pants',
            '加绒工装裤' => 'fleece.lined.*cargo pants',
            '工装套装' => 'workwear suit',
            '工装短裤' => 'cargo shorts?',
            '工装裤' => 'work pants?|cargo pants|utility pants|tactical pants',
            '木珠手链' => 'wooden beaded bracelet',
            '手链' => 'bracelets?',
            '吊坠项链' => 'pendant|necklace',
            '帆布托特包' => 'canvas tote bag',
            '晚宴包' => 'evening bag',
            '阔腿运动裤' => 'wide.leg sweatpants',
            '背带工装裤' => 'bib overalls',
            '直筒牛仔裤' => 'straight leg denim|straight leg jeans',
            '牛仔裤' => '\bjeans?\b',
            '针织开衫' => 'cardigan|knit.*sweater jacket',
            '连帽卫衣套装' => 'hoodie.*(?:set|leggings)|hooded.*(?:suit|track suit)',
            '连帽休闲外套' => 'hooded.*(?:jacket|coat)',
            '卫衣套装' => 'sweatshirt.*(?:set|leggings)',
            '休闲三件套' => 'three[ -]piece',
            '家居套装' => 'lounge set|pajama set',
            '休闲两件套' => '(?:two|2)[ -]piece|casual suit',
            '运动套装' => 'sports suit|track suit',
            '加绒中长外套' => 'fleece.*coat|fur length coat',
            '无钢圈文胸' => 'wireless bra',
            '中筒袜' => 'mid.calf socks',
            '加绒打底裤' => 'fleece lined leggings',
            '打底裤' => 'leggings',
            '连帽针织衫' => 'hooded sweater',
            '针织衫' => 'sweater|knit(?: cotton)? pullover',
            '抓绒马甲' => 'fleece vest',
            '连帽斗篷' => 'hooded cape',
            '连帽卫衣' => 'hoodie',
            '卫衣' => 'sweatshirt',
            '运动裤' => 'sweatpants|joggers',
            '保暖棉外套' => 'puffer coat|down jacket|parka',
            '休闲外套' => 'jacket|\bcoat\b',
            '连衣裙' => 'casual dress|\bdress\b',
            '连体裤' => 'jumpsuit',
            '长袖T恤' => 'long.sleeve.*t.shirt',
            '长款圆领上衣' => 'long.length casual top.*crew neck',
            'T恤' => '\bt.shirts?\b',
            '法兰绒衬衫' => 'flannel.*shirts?',
            '衬衫' => '\bshirts?\b',
            '阔腿裤' => 'wide.leg.*trousers',
            '针织裤' => 'knit pants',
            '休闲裤' => 'casual pants',
            'Polo衫' => '\bpolo\b',
            '休闲鞋' => 'sneakers|casual shoes',
            '帽子' => '\bhat\b',
        ];
        foreach (preg_split('/\R/u', $value) ?: [] as $title) {
            $name = self::line($title);
            if (preg_match('/\p{Han}/u', $name)
                && ! preg_match('/\b[A-Za-z]{2,}(?:\s+[A-Za-z]{2,}){2}\b/', $name)) {
                return $name;
            }
            foreach ($patterns as $shortName => $pattern) {
                if (preg_match('/'.$pattern.'/i', $title)) {
                    return $shortName;
                }
            }
        }
        return '';
    }

    private static function category(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }
        $name = self::line($value);
        $groups = [
            '运输保障' => '运输保障|运费险|shipping protection|shipping insurance|freight insurance',
            '小费' => '^小费$|^tips?$|^tipping$',
            '宠物用品' => '宠物|犬猫|狗狗|猫咪|\bpet[ -]|\bdogs?\b|\bcats?\b',
            '口腔护理' => '假牙|保持器|牙周|牙齿|牙渍|牙釉质|牙膏|牙刷|denture|periodontal|teeth|tooth',
            '清洁用品' => '清洁剂|清洗剂|去渍|水垢|抹布|洗碗海绵|cleaning foam|cleaner|stain remover|cleaning cloth',
            '美妆个护' => '蓬松|定型喷雾|假睫毛|眉印|眉笔|粉底|护发|染发|发根|洁面|清耳|指缘|乳霜|lashes|eyebrow|foundation|hair (?:oil|spray|dye)|\bcuticles\b',
            '服饰配件' => '围巾|内裤|中筒袜|睡眠袜|地板袜|手套|便帽|贝雷帽|棉质面罩|水洗帽|太阳镜|睡帽|钱包|socks?|scarf|beanie|gloves?|sunglasses|wallet',
            '数码配件' => '充电线|磁吸保护壳|屏幕保护膜|便携式 Wi-Fi|charging cables?|protective case|screen protector|portable Wi-Fi',
            '五金工具' => '开锁|金属修补胶|金属防锈|沙发皮革修补贴|门底条|lock pick|metal repair|sofa repair|draft stopper',
            '汽车用品' => '挡风玻璃|汽车划痕|windshield|car scratch',
            '文具用品' => '中性笔|笔记本|gel pen|stationery',
            '节庆玩具' => '圣诞景观|倒数日历|国际象棋|人偶|场景摆件|浮雕绘画笔|装饰挂件|advent calendar|chessboard|magnetic figures',
            '家居厨具' => '保鲜|食品罩|削皮器|瓶刷|香草剪|开罐|收纳|马克杯|枕套|毛巾|food covers?|slicer|bottle brush|pillowcases?',
        ];
        foreach ($groups as $category => $pattern) {
            if (preg_match('/'.$pattern.'/iu', $name)) {
                return $category;
            }
        }
        return '未分类';
    }

    private static function line(string $value): string
    {
        $clean = trim(preg_replace('/\x{FFFD}+/u', '', $value) ?? $value);
        if ($clean === '') {
            return trim($value) === '' ? '' : '内容编码异常';
        }
        foreach (self::NAMES as $source => $name) {
            $position = stripos($clean, $source);
            if ($position === false) {
                continue;
            }
            $suffix = preg_replace('/^[\s\p{S}\p{M}]+/u', '', substr($clean, $position + strlen($source)));
            if ($suffix === '') {
                return $name;
            }
            if (preg_match('/^[\s\-–—]+(.+)$/u', $suffix, $parts)) {
                $variant = self::variant($parts[1]);
                return $variant === '' ? $name : $name.'｜'.$variant;
            }
        }
        $variant = self::variant($clean);
        return FinanceCatalog::simplified($variant === '' ? $clean : $variant);
    }

    private static function variant(string $value): string
    {
        $value = trim($value);
        $labels = [
            'default title' => '', 'tip' => '小费', 'tipping' => '小费', 'natural' => '自然款', 'natural color' => '自然色', 'mixed' => '混合款',
            'thick' => '浓密款', 'black long' => '黑色加长款', 'fairy' => '仙女款', 'ivory' => '象牙色',
            'black' => '黑色', 'natural black' => '自然黑', 'white' => '白色', 'blue' => '蓝色',
            'navy blue' => '藏青色', 'green' => '绿色', 'pink' => '粉色', 'korean pink' => '韩系粉',
            'red' => '红色', 'khaki' => '卡其色', 'dark gray' => '深灰色', 'beige' => '米色',
            'light brown' => '浅棕色', 'medium brown' => '中棕色', 'highland cow' => '高地牛',
            'polar bear' => '北极熊', 'raccoon' => '浣熊', 'reindeer' => '驯鹿', 'little fox' => '小狐狸',
            'santa\'s house' => '圣诞老人小屋', 'gingerbread house' => '姜饼屋', 'forest cabin' => '森林木屋',
            'vintage train' => '复古火车', 'flying santa' => '飞翔的圣诞老人', 'cardinal' => '红雀',
        ];
        $key = strtolower($value);
        if (array_key_exists($key, $labels)) {
            return $labels[$key];
        }
        $value = preg_replace('/\bBuy\s+(\d+)\s+Get\s+(\d+)\s+Free\b/i', '买$1送$2', $value);
        $value = preg_replace('/\bBuy\s+(\d+)\b/i', '买$1件', $value);
        $value = preg_replace('/\bAges\s+([\d+–-]+)\s*\/\s*(Male|Female)\b/i', '$1岁 / $2', $value);
        $value = preg_replace_callback('/(\d+)岁\s*\/\s*(Male|Female)\b/i', fn ($match) => $match[1].'岁 / '.(strtolower($match[2]) === 'male' ? '男款' : '女款'), $value);
        return preg_replace('/(\d+)\s*pcs?\b/i', '$1件', $value);
    }
}
