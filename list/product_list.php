<?php
/**
 * 結合 Magento 1.9 SOAP API 與資料庫查詢，
 * 補足 Magento 2.4.7 建立產品所需欄位，
 * custom_attributes 部分會由資料庫查詢（EAV 各 value 表），
 * 並將其格式化為陣列格式，每個元素包含 attribute_code 與 value，
 * 只顯示 attribute_set_id 為 22 或 23 的資料。
 */

// 讀取設定檔
$configPath = '../config.json';
if (!file_exists($configPath)) {
    die("無法讀取設定檔 {$configPath}");
}
$config = json_decode(file_get_contents($configPath), true);

// Magento 1.9 SOAP API 參數
$magentoDomain = rtrim($config['magento_domain'], '/') . '/';
$apiUser       = $config['api_user'];
$apiKey        = $config['api_key'];
$soapUrl       = $magentoDomain . 'api/soap/?wsdl';

// 建立 Magento 1.9 SOAP 連線與登入
try {
    $client = new SoapClient($soapUrl);
    $session = $client->login($apiUser, $apiKey);
    // 取得產品列表（list API），僅回傳基本欄位（例如 product_id, sku, category_ids 等）
    $products = $client->call($session, 'catalog_product.list');
} catch (Exception $e) {
    die("SOAP 連線或登入失敗，錯誤訊息: " . $e->getMessage());
}

// 建立資料庫連線 (Magento 1.9 資料庫)
$dbHost     = $config['db_host'];
$dbName     = $config['db_name'];
$dbUser     = $config['db_user'];
$dbPassword = $config['db_password'];
$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}

/**
 * 輔助函式：根據 entity_id 與 attribute_id 從指定表查詢單一值
 * @param PDO $pdo
 * @param int $entityId
 * @param int $attributeId
 * @param string $table  例如 catalog_product_entity_decimal, catalog_product_entity_int, catalog_product_entity_varchar
 * @return mixed
 */
function getAttributeValue(PDO $pdo, $entityId, $attributeId, $table) {
    $sql = "SELECT value FROM {$table} WHERE entity_id = ? AND attribute_id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$entityId, $attributeId]);
    $value = $stmt->fetchColumn();
    return $value;
}

// 用來儲存最終整合後的產品資料（補足資料庫部分）
$finalProducts = [];

// 逐一處理每筆產品
foreach ($products as $prod) {
    // 先以 SOAP 取得詳細資訊
    try {
        $prodInfo = $client->call($session, 'catalog_product.info', $prod['product_id']);
    } catch (Exception $ex) {
        // 若取得失敗則略過此筆產品
        continue;
    }
    
    // 產品 ID (Magento 1.9 的 catalog_product_entity 的 entity_id)
    $entityId = isset($prodInfo['product_id']) ? $prodInfo['product_id'] : null;
    if (!$entityId) {
        continue;
    }
    
    // 補足部分欄位：若 SOAP 沒有提供，則從資料庫查詢
    // 像價格、重量、狀態、可見度、名稱等（attribute_id 僅為示範，請依實際環境調整）
    if (!isset($prodInfo['price']) || $prodInfo['price'] === '') {
        $prodInfo['price'] = getAttributeValue($pdo, $entityId, 75, 'catalog_product_entity_decimal');
    }
    if (!isset($prodInfo['weight']) || $prodInfo['weight'] === '') {
        $prodInfo['weight'] = getAttributeValue($pdo, $entityId, 70, 'catalog_product_entity_decimal');
    }
    if (!isset($prodInfo['status']) || $prodInfo['status'] === '') {
        $prodInfo['status'] = getAttributeValue($pdo, $entityId, 96, 'catalog_product_entity_int');
    }
    if (!isset($prodInfo['visibility']) || $prodInfo['visibility'] === '') {
        $prodInfo['visibility'] = getAttributeValue($pdo, $entityId, 102, 'catalog_product_entity_int');
    }
    if (!isset($prodInfo['name']) || $prodInfo['name'] === '') {
        $prodInfo['name'] = getAttributeValue($pdo, $entityId, 71, 'catalog_product_entity_varchar');
    }
    
    // 只處理 attribute_set_id 為 22 或 23 的資料
    $attrSet = isset($prodInfo['set']) ? (int)$prodInfo['set'] : 0;
    if ($attrSet !== 22 && $attrSet !== 23) {
        continue;
    }
    
    // extension_attributes 裡的 website_ids 可從 SOAP 的 websites 補足
    $extensionAttributes = [];
    $extensionAttributes['website_ids'] = isset($prodInfo['websites']) && is_array($prodInfo['websites']) ? $prodInfo['websites'] : [];
    
    // 從資料庫查詢該產品所屬分類 (建立 category_links)
    $catStmt = $pdo->prepare("SELECT category_id FROM catalog_category_product WHERE product_id = ?");
    $catStmt->execute([$entityId]);
    $categoryIds = $catStmt->fetchAll(PDO::FETCH_COLUMN);
    $categoryLinks = [];
    if ($categoryIds) {
        foreach ($categoryIds as $catId) {
            $categoryLinks[] = [
                "position" => 0,
                "category_id" => (string)$catId,
                "extension_attributes" => new stdClass()
            ];
        }
    }
    $extensionAttributes['category_links'] = $categoryLinks;
    
    // 其它 extension_attributes 預設空陣列（可依需求擴充）
    $extensionAttributes['discounts'] = [];
    $extensionAttributes['bundle_product_options'] = [];
    $extensionAttributes['stock_item'] = [];
    $extensionAttributes['downloadable_product_links'] = [];
    $extensionAttributes['downloadable_product_samples'] = [];
    $extensionAttributes['giftcard_amounts'] = [];
    $extensionAttributes['configurable_product_options'] = [];
    $extensionAttributes['configurable_product_links'] = [];
    
    // 取得 custom_attributes：從 EAV 各值表取出所有屬性對應的 attribute_code 與 value
    // 使用 UNION 方式整合各個 value 表 (varchar, int, text, decimal, datetime)
    // 輸出格式改為陣列，每個元素包含 attribute_code 與 value
    $customAttributes = [];
    $unionQuery = "
      SELECT ea.attribute_code, v.value
      FROM eav_attribute ea
      JOIN (
          SELECT attribute_id, value FROM catalog_product_entity_varchar WHERE entity_id = ?
          UNION ALL
          SELECT attribute_id, value FROM catalog_product_entity_int WHERE entity_id = ?
          UNION ALL
          SELECT attribute_id, value FROM catalog_product_entity_text WHERE entity_id = ?
          UNION ALL
          SELECT attribute_id, value FROM catalog_product_entity_decimal WHERE entity_id = ?
          UNION ALL
          SELECT attribute_id, value FROM catalog_product_entity_datetime WHERE entity_id = ?
      ) AS v ON ea.attribute_id = v.attribute_id
      WHERE ea.entity_type_id = (
          SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product'
      )
    ";
    $stmt = $pdo->prepare($unionQuery);
    $stmt->execute([$entityId, $entityId, $entityId, $entityId, $entityId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as $row) {
        $customAttributes[] = [
            "attribute_code" => $row['attribute_code'],
            "value" => $row['value']
        ];
    }
    
    // 組合最終產品資料（符合 Magento 2.4.7 /V1/products payload 格式）
    $finalProducts[] = [
        "id" => (int)$entityId,
        "sku" => isset($prodInfo['sku']) ? $prodInfo['sku'] : '',
        "name" => $prodInfo['name'],
        "attribute_set_id" => $attrSet,
        "price" => (float)$prodInfo['price'],
        "status" => (int)$prodInfo['status'],
        "visibility" => (int)$prodInfo['visibility'],
        "type_id" => isset($prodInfo['type']) ? $prodInfo['type'] : '',
        "created_at" => isset($prodInfo['created_at']) ? $prodInfo['created_at'] : '',
        "updated_at" => isset($prodInfo['updated_at']) ? $prodInfo['updated_at'] : '',
        "weight" => (float)$prodInfo['weight'],
        "extension_attributes" => $extensionAttributes,
        "custom_attributes" => $customAttributes,
        "product_links" => [],
        "options" => [],
        "media_gallery_entries" => [],
        "tier_prices" => []
    ];
}

// 結束 SOAP session
$client->endSession($session);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>Magento 1.9 產品資料補足後 (含 custom_attributes，僅 attribute_set 22/23)</title>
    <style>
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        th, td { padding: 5px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
        th { background-color: #f5f5f5; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <h1>Magento 1.9 產品資料（補足後，含 custom_attributes，僅 attribute_set_id 為 22 或 23）</h1>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>SKU</th>
                <th>Name</th>
                <th>Attribute Set</th>
                <th>Price</th>
                <th>Status</th>
                <th>Visibility</th>
                <th>Type</th>
                <th>Created At</th>
                <th>Updated At</th>
                <th>Weight</th>
                <th>Website IDs</th>
                <th>Category Links</th>
                <th>Custom Attributes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($finalProducts as $prod): ?>
            <tr>
                <td><?php echo htmlspecialchars($prod['id']); ?></td>
                <td><?php echo htmlspecialchars($prod['sku']); ?></td>
                <td><?php echo htmlspecialchars($prod['name']); ?></td>
                <td><?php echo htmlspecialchars($prod['attribute_set_id']); ?></td>
                <td><?php echo htmlspecialchars($prod['price']); ?></td>
                <td><?php echo htmlspecialchars($prod['status']); ?></td>
                <td><?php echo htmlspecialchars($prod['visibility']); ?></td>
                <td><?php echo htmlspecialchars($prod['type_id']); ?></td>
                <td><?php echo htmlspecialchars($prod['created_at']); ?></td>
                <td><?php echo htmlspecialchars($prod['updated_at']); ?></td>
                <td><?php echo htmlspecialchars($prod['weight']); ?></td>
                <td>
                    <?php 
                    if(isset($prod['extension_attributes']['website_ids'])){
                        echo htmlspecialchars(implode(',', $prod['extension_attributes']['website_ids']));
                    }
                    ?>
                </td>
                <td>
                    <?php 
                    if(isset($prod['extension_attributes']['category_links'])){
                        $links = [];
                        foreach($prod['extension_attributes']['category_links'] as $link){
                            $links[] = "Cat: " . $link['category_id'];
                        }
                        echo htmlspecialchars(implode('; ', $links));
                    }
                    ?>
                </td>
                <td>
                    <pre><?php echo htmlspecialchars(json_encode($prod['custom_attributes'], JSON_PRETTY_PRINT)); ?></pre>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p>以上資料結合了 Magento 1.9 SOAP API 與資料庫查詢補足 custom_attributes，僅顯示 attribute_set_id 為 22 或 23 的產品，請確認後再進行後續處理（如 POST 到 Magento 2.4.7 REST API）。</p>
</body>
</html>
