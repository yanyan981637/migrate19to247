<?php
// 開啟錯誤訊息顯示（除錯用）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// -------------------------------------------------
// 讀取 config.json 設定檔（包含 API 與 DB 設定）
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

// 資料庫連線設定（Magento 1.9 DB）
$dbHost     = trim($configData['db_host'] ?? '');
$dbName     = trim($configData['db_name'] ?? '');
$dbUser     = trim($configData['db_user'] ?? '');
$dbPassword = trim($configData['db_password'] ?? '');
if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
    die("資料庫連線設定不完整");
}

// -----------------------------
// 建立 Magento SOAP API 連線
// -----------------------------
$soapUrl = rtrim($magentoDomain, '/') . '/api/soap/?wsdl';
try {
    $client  = new SoapClient($soapUrl, array('trace' => 1));
    $session = $client->login($apiUser, $apiKey);
} catch (Exception $e) {
    die("SOAP API Error: " . $e->getMessage());
}

// --------------------------------------------------
// 建立 PDO 資料庫連線 (Magento 1.9 資料庫)
// --------------------------------------------------
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}

// --------------------------------------------------
// 參數設定：使用 attribute set ID = 22 來取得屬性清單
// --------------------------------------------------
$attributeSetId = 22;

// 透過 SOAP API 取得屬性清單 (list)
try {
    $listResult = $client->call($session, 'product_attribute.list', array($attributeSetId));
    if (!is_array($listResult) || empty($listResult)) {
        die("未取得 attribute set ID {$attributeSetId} 的屬性清單。");
    }
} catch (Exception $e) {
    die("取得屬性清單錯誤：" . $e->getMessage());
}

// --------------------------------------------------
// 對於每筆屬性，分別呼叫 info API 及查詢資料庫額外欄位
// --------------------------------------------------
$mergedAttributes = array();
foreach ($listResult as $attribute) {
    if (!isset($attribute['attribute_id'])) {
        continue;
    }
    $attrId = $attribute['attribute_id'];
    
    // 呼叫 SOAP API 取得屬性詳細資訊 (info)
    try {
        $info = $client->call($session, 'product_attribute.info', $attrId);
    } catch (Exception $e) {
        $info = array("error" => "取得 info 錯誤：" . $e->getMessage());
    }
    
    // 從 Magento 1.9 資料庫取得額外欄位資料
    // 結合 eav_attribute、catalog_eav_attribute 與 eav_entity_attribute (確認屬性屬於指定 attribute set)
    $sql = "
    SELECT 
        ea.attribute_id,
        ea.attribute_code,
        ea.backend_model,
        ea.backend_type,
        '' AS custom_attributes,  -- Magento 1.9 無此欄位，預設空值
        ea.frontend_label AS default_frontend_label,
        '' AS extension_attributes,  -- Magento 1.9 無此欄位
        ea.frontend_class,
        cea.is_filterable,
        '' AS is_filterable_in_grid,  -- Magento 1.9 無此欄位
        cea.is_filterable_in_search,
        ea.is_unique,
        0 AS is_used_for_promo_rules,  -- Magento 1.9 無此欄位，預設 0
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
        '' AS validation_rules       -- Magento 1.9 無此欄位
    FROM eav_attribute AS ea
    LEFT JOIN catalog_eav_attribute AS cea ON ea.attribute_id = cea.attribute_id
    INNER JOIN eav_entity_attribute AS eea ON eea.attribute_id = ea.attribute_id
    WHERE ea.attribute_id = :attrId AND eea.attribute_set_id = :setId
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':attrId', $attrId, PDO::PARAM_INT);
    $stmt->bindParam(':setId', $attributeSetId, PDO::PARAM_INT);
    $stmt->execute();
    $dbData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dbData) {
        $dbData = array(); // 若查不到，保持空陣列
    }
    
    // 合併 API info 與資料庫欄位，若有相同 key，以 API 資料為主（可依需求調整）
    $merged = array_merge($dbData, $info);
    // 記錄屬於哪個 attribute set
    $merged['attribute_set_id'] = $attributeSetId;
    $mergedAttributes[] = $merged;
}

// --------------------------------------------------
// 動態取得所有欄位 key
// --------------------------------------------------
$allKeys = array();
foreach ($mergedAttributes as $attr) {
    $allKeys = array_merge($allKeys, array_keys($attr));
}
$allKeys = array_unique($allKeys);
sort($allKeys);  // 排序後呈現

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Magento 1.9 Attribute Merged Info</title>
    <style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 40px; }
        th, td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
        th { background-color: #f2f2f2; text-align: left; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
        h2 { margin-top: 40px; }
    </style>
</head>
<body>
    <h1>Magento 1.9 Merged Attribute Info</h1>
    <h2>Attribute Set ID: <?php echo htmlspecialchars($attributeSetId); ?></h2>
    <table>
        <thead>
            <tr>
                <?php foreach ($allKeys as $key): ?>
                    <th><?php echo htmlspecialchars($key); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($mergedAttributes as $attr): ?>
                <tr>
                    <?php foreach ($allKeys as $key): ?>
                        <td>
                            <?php 
                            if (isset($attr[$key])) {
                                if (is_array($attr[$key])) {
                                    echo '<pre>' . htmlspecialchars(json_encode($attr[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                                } else {
                                    echo htmlspecialchars($attr[$key]);
                                }
                            }
                            ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
