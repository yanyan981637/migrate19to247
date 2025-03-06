<?php
// 開啟錯誤訊息顯示（除錯用）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ----------------------------------------------------------------
// 讀取 ../config.json 設定檔（包含 API 與 DB 設定）
// ----------------------------------------------------------------
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

// ----------------------------------------------------------------
// 固定參數：attribute set id 為 22，attribute id 為 100
// ----------------------------------------------------------------
$attributeSetId = 22;
$attributeId    = 100;

// ----------------------------------------------------------------
// 建立 Magento SOAP API 連線 (使用 SOAP V1)
// ----------------------------------------------------------------
$soapUrl = rtrim($magentoDomain, '/') . '/api/soap/?wsdl';
try {
    $client  = new SoapClient($soapUrl, array('trace' => 1));
    $session = $client->login($apiUser, $apiKey);
} catch (Exception $e) {
    die("SOAP API Error: " . $e->getMessage());
}

// ----------------------------------------------------------------
// 使用 SOAP API 取得 attribute id 為 100 的詳細資訊
// ----------------------------------------------------------------
try {
    $apiInfo = $client->call($session, 'product_attribute.info', $attributeId);
    if (empty($apiInfo) || !is_array($apiInfo)) {
        die("未取得 attribute id {$attributeId} 的 API 詳細資訊。");
    }
} catch (Exception $e) {
    die("取得 API 詳細資訊錯誤：" . $e->getMessage());
}

// ----------------------------------------------------------------
// 建立 PDO 資料庫連線 (Magento 1.9 資料庫)
// ----------------------------------------------------------------
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}

// ----------------------------------------------------------------
// 從 Magento 1.9 資料庫取得 attribute set id 為 22，且 attribute id 為 100 的額外欄位資料
// 利用 eav_attribute、catalog_eav_attribute 與 eav_entity_attribute 取得資料
// 有些欄位 Magento 1.9 不存在，故以空字串或預設值呈現
// ----------------------------------------------------------------
$sql = "
SELECT 
    ea.attribute_id,
    ea.attribute_code,
    ea.backend_model,
    ea.backend_type,
    '' AS custom_attributes,
    ea.frontend_label AS default_frontend_label,
    '' AS extension_attributes,
    ea.frontend_class,
    cea.is_filterable,
    '' AS is_filterable_in_grid,
    cea.is_filterable_in_search,
    ea.is_unique,
    0 AS is_used_for_promo_rules,
    '' AS is_used_in_grid,
    ea.is_user_defined,
    cea.is_visible_on_front AS is_visible,
    '' AS is_visible_in_grid,
    '' AS is_wysiwyg_enabled,
    ea.note,
    cea.position,
    ea.source_model,
    cea.used_for_sort_by,
    cea.used_in_product_listing,
    '' AS validation_rules
FROM eav_attribute AS ea
LEFT JOIN catalog_eav_attribute AS cea ON ea.attribute_id = cea.attribute_id
INNER JOIN eav_entity_attribute AS eea ON eea.attribute_id = ea.attribute_id
WHERE ea.attribute_id = :attrId AND eea.attribute_set_id = :setId
";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':attrId', $attributeId, PDO::PARAM_INT);
$stmt->bindParam(':setId', $attributeSetId, PDO::PARAM_INT);
$stmt->execute();
$dbInfo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$dbInfo) {
    die("在 attribute set id {$attributeSetId} 中找不到 attribute id {$attributeId} 的資料。");
}

// ----------------------------------------------------------------
// 合併 API 資訊與資料庫資訊（以 API 資訊為主，覆蓋資料庫相同 key）
// ----------------------------------------------------------------
$mergedInfo = array_merge($dbInfo, $apiInfo);
$mergedInfo['attribute_set_id'] = $attributeSetId;

// ----------------------------------------------------------------
// 動態取得所有欄位 key (以縱向顯示，每筆一行)
// ----------------------------------------------------------------
$allKeys = array_keys($mergedInfo);
sort($allKeys);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Magento 1.9 Merged Attribute Info (Vertical)</title>
    <style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; width: 60%; margin: 20px auto; }
        th, td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
        th { background-color: #f2f2f2; text-align: left; width: 30%; }
        td { width: 70%; }
        pre { white-space: pre-wrap; word-wrap: break-word; margin: 0; }
    </style>
</head>
<body>
    <h1 style="text-align:center;">Magento 1.9 Merged Attribute Info</h1>
    <h2 style="text-align:center;">Attribute Set ID: <?php echo htmlspecialchars($attributeSetId); ?>, Attribute ID: <?php echo htmlspecialchars($attributeId); ?></h2>
    <table>
        <tbody>
            <?php foreach ($allKeys as $key): ?>
                <tr>
                    <th><?php echo htmlspecialchars($key); ?></th>
                    <td>
                        <?php 
                        if (isset($mergedInfo[$key])) {
                            if (is_array($mergedInfo[$key])) {
                                echo '<pre>' . htmlspecialchars(json_encode($mergedInfo[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                            } else {
                                echo htmlspecialchars($mergedInfo[$key]);
                            }
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
