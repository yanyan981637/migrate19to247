<?php
// ----------------------------
// 開啟錯誤訊息顯示（除錯用）
// ----------------------------
ini_set('display_errors', 1);
error_reporting(E_ALL);

// -------------------------------------------------
// 讀取 ../config.json 設定檔（包含 API 與 DB 設定）
// -------------------------------------------------
$configFile = __DIR__ . '/../config.json';
if (!file_exists($configFile)) {
    die("找不到 config.json 檔案");
}
$configData = json_decode(file_get_contents($configFile), true);
if (!$configData) {
    die("config.json 格式錯誤");
}

// Magento SOAP API 設定
$magentoDomain = trim($configData['magento_domain'] ?? '');
$apiUser       = trim($configData['api_user'] ?? '');
$apiKey        = trim($configData['api_key'] ?? '');
if (empty($magentoDomain) || empty($apiUser) || empty($apiKey)) {
    die("Magento API 設定不完整");
}

// 資料庫連線設定（Magento 1.9 資料庫）
$dbHost     = trim($configData['db_host'] ?? '');
$dbName     = trim($configData['db_name'] ?? '');
$dbUser     = trim($configData['db_user'] ?? '');
$dbPassword = trim($configData['db_password'] ?? '');
if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
    die("資料庫連線設定不完整");
}

// -------------------------------------------------
// 固定參數：處理 attribute set id 為 22 與 23
// -------------------------------------------------
$attributeSetIds = [22, 23];

// -------------------------------------------------
// 建立 Magento SOAP API 連線 (使用 SOAP V1)
// -------------------------------------------------
$soapUrl = rtrim($magentoDomain, '/') . '/api/soap/?wsdl';
try {
    $client  = new SoapClient($soapUrl, array('trace' => 1));
    $session = $client->login($apiUser, $apiKey);
} catch (Exception $e) {
    die("SOAP API Error: " . $e->getMessage());
}

// -------------------------------------------------
// 建立 PDO 資料庫連線 (Magento 1.9 資料庫)
// -------------------------------------------------
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}

// -------------------------------------------------
// SQL：依 attribute set 與 attribute group 取得屬性基本資料
// -------------------------------------------------
$attrSql = "
SELECT 
    ea.attribute_id,
    ea.attribute_code,
    ea.backend_model,
    ea.backend_type,
    '' AS custom_attributes,  -- Magento 1.9 無此欄位
    ea.frontend_label AS default_frontend_label,
    '' AS extension_attributes,  -- Magento 1.9 無此欄位
    ea.frontend_class,
    cea.is_filterable,
    '' AS is_filterable_in_grid,  -- Magento 1.9 無此欄位
    cea.is_filterable_in_search,
    ea.is_unique,
    0 AS is_used_for_promo_rules,  -- 預設 0
    '' AS is_used_in_grid,         -- Magento 1.9 無此欄位
    ea.is_user_defined,
    cea.is_visible_on_front AS is_visible,
    '' AS is_visible_in_grid,      -- Magento 1.9 無此欄位
    '' AS is_wysiwyg_enabled,      -- Magento 1.9 無此欄位
    ea.note,
    cea.position,
    ea.source_model,
    cea.used_for_sort_by,
    cea.used_in_product_listing,
    '' AS validation_rules        -- Magento 1.9 無此欄位
FROM eav_attribute AS ea
LEFT JOIN catalog_eav_attribute AS cea ON ea.attribute_id = cea.attribute_id
INNER JOIN eav_entity_attribute AS eea ON eea.attribute_id = ea.attribute_id
WHERE eea.attribute_set_id = :setId AND eea.attribute_group_id = :groupId
";

// -------------------------------------------------
// 先查詢每個 attribute set 的 attribute group
// -------------------------------------------------
$groupSql = "SELECT * FROM eav_attribute_group WHERE attribute_set_id = :setId ORDER BY sort_order ASC";

// 最終結果存放於此陣列
$finalResult = [];

// 逐一處理 attribute set 22 與 23
foreach ($attributeSetIds as $setId) {
    // 查詢 attribute group
    $stmtGroup = $pdo->prepare($groupSql);
    $stmtGroup->bindParam(':setId', $setId, PDO::PARAM_INT);
    $stmtGroup->execute();
    $groups = $stmtGroup->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$groups) {
        continue;
    }
    
    // 建立一個屬性集結果陣列
    $finalResult[$setId] = [];
    
    // 處理每個 group
    foreach ($groups as $group) {
        $groupId   = $group['attribute_group_id'];
        $groupName = $group['attribute_group_name'];
        
        // 查詢該 group 下所有 attribute 的基本資料
        $stmtAttr = $pdo->prepare($attrSql);
        $stmtAttr->bindParam(':setId', $setId, PDO::PARAM_INT);
        $stmtAttr->bindParam(':groupId', $groupId, PDO::PARAM_INT);
        $stmtAttr->execute();
        $attributes = $stmtAttr->fetchAll(PDO::FETCH_ASSOC);
        
        // 若該 group 有 attribute，逐筆取得 SOAP API 的 info 後合併
        $groupResult = [];
        foreach ($attributes as $attr) {
            $attrId = $attr['attribute_id'];
            // 呼叫 SOAP API 取得詳細資訊
            try {
                $apiInfo = $client->call($session, 'product_attribute.info', $attrId);
                if (!is_array($apiInfo)) {
                    $apiInfo = [];
                }
            } catch (Exception $e) {
                $apiInfo = ["api_error" => $e->getMessage()];
            }
            // 合併：以 API 資料覆蓋資料庫中相同 key
            $merged = array_merge($attr, $apiInfo);
            // 檢查 additional_fields 中是否包含 is_html_allowed_on_front，若有則提取到外層
            if (isset($merged['additional_fields']) && is_array($merged['additional_fields']) && isset($merged['additional_fields']['is_html_allowed_on_front'])) {
                $merged['is_html_allowed_on_front'] = $merged['additional_fields']['is_html_allowed_on_front'];
                // 可選：將該項目從 additional_fields 移除
                unset($merged['additional_fields']['is_html_allowed_on_front']);
            }
            // 紀錄所屬 attribute set 與 group
            $merged['attribute_set_id'] = $setId;
            $merged['attribute_group_id'] = $groupId;
            $merged['attribute_group_name'] = $groupName;
            $groupResult[] = $merged;
        }
        // 將每個 group 的結果放入對應的 attribute set
        $finalResult[$setId][$groupId] = [
            'group_name' => $groupName,
            'attributes' => $groupResult
        ];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Magento 1.9 Attribute Groups & Attributes (Vertical)</title>
    <style>
        body { font-family: Arial, sans-serif; }
        h1, h2, h3 { text-align: center; }
        .attribute-table { border-collapse: collapse; width: 60%; margin: 20px auto; }
        .attribute-table th, .attribute-table td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
        .attribute-table th { background-color: #f2f2f2; text-align: left; width: 30%; }
        .attribute-table td { width: 70%; }
        .group-section { margin-bottom: 40px; }
        pre { white-space: pre-wrap; word-wrap: break-word; margin: 0; }
    </style>
</head>
<body>
    <h1>Magento 1.9 Attribute Groups & Attributes</h1>
    <?php foreach ($finalResult as $setId => $groups): ?>
        <h2>Attribute Set ID: <?php echo htmlspecialchars($setId); ?></h2>
        <?php if (empty($groups)): ?>
            <p style="text-align:center;">此屬性集無任何 Attribute Group</p>
        <?php else: ?>
            <?php foreach ($groups as $groupId => $groupData): ?>
                <div class="group-section">
                    <h3>Attribute Group: <?php echo htmlspecialchars($groupData['group_name']); ?> (Group ID: <?php echo htmlspecialchars($groupId); ?>)</h3>
                    <?php if (empty($groupData['attributes'])): ?>
                        <p style="text-align:center;">此群組無任何 Attribute</p>
                    <?php else: ?>
                        <?php foreach ($groupData['attributes'] as $attribute): 
                            // 動態取得所有欄位 key (每個 attribute 的欄位)
                            $allKeys = array_keys($attribute);
                            sort($allKeys);
                        ?>
                            <table class="attribute-table">
                                <thead>
                                    <tr>
                                        <th colspan="2">Attribute ID: <?php echo htmlspecialchars($attribute['attribute_id']); ?> / Code: <?php echo htmlspecialchars($attribute['attribute_code']); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allKeys as $key): ?>
                                        <tr>
                                            <th><?php echo htmlspecialchars($key); ?></th>
                                            <td>
                                                <?php 
                                                if (isset($attribute[$key])) {
                                                    if (is_array($attribute[$key])) {
                                                        echo '<pre>' . htmlspecialchars(json_encode($attribute[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                                                    } else {
                                                        echo htmlspecialchars($attribute[$key]);
                                                    }
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endforeach; ?>
</body>
</html>
