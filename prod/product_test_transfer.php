<?php
// 設定不限制執行時間與顯示所有錯誤
set_time_limit(0);
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * 此程式整合 Magento 1.9 SOAP API 與資料庫查詢，
 * 取得所有產品資料（僅保留 attribute_set_id 為 22 或 23 的產品），
 * 並組合出符合 Magento 2.4.7 /V1/products Payload 格式的資料。
 *
 * 更新重點：
 * 1. Payload 中不帶入 "id" 欄位。
 * 2. attribute_set_id 映射：1.9 為 22 轉為 10；1.9 為 23 轉為 9。
 * 3. 根據 $migrationMap 更新 category_links：若有對應則轉換，無對應則不帶該分類。
 * 4. 補足其它欄位：例如 stock_item 固定為空物件 {}，其餘若資料庫無對應則留空。
 * 5. 針對 media_gallery_entries：利用輔助函式讀取檔案內容轉為 base64，
 *    並組成符合 Magento 2.4.7 格式的資料，但移除 id 欄位。
 */

// 對應表：key 為 Magento 1 的 category_id，value 為 Magento 2 的 category_id
$migrationMap = [
    80  => 10,  // MioWORK™
    34  => 11,  // Handhelds
    146 => 12,  // A500s Series
    35  => 13,  // Fleet Tablets
    88  => 14,  // F740s
    59  => 15,  // Industrial Tablets
    86  => 16,  // L1000 Series
    60  => 17,  // Legacy
    70  => 18,  // F700 Series
    69  => 19,  // A500 Series
    72  => 20,  // L130 Series
    71  => 21,  // L100 Series
    73  => 22,  // A200/A300 Series
    74  => 23,  // L70 Series
    76  => 24,  // Latest Products
    77  => 25,  // MiDM™
    103 => 26,  // Cradle
    81  => 27,  // MioCARE™
    61  => 28,  // Handhelds
    62  => 29,  // Tablets
    63  => 30,  // Legacy
    68  => 31,  // A300 Series
    66  => 32,  // L130 Series
    64  => 33,  // A200 Series
    65  => 34,  // MiCor
    67  => 35,  // A500 Series
    78  => 36,  // Latest Products
    141 => 37,  // MioEYE™
    143 => 38,  // Fleet Cameras
    149 => 39,  // MioServ™
    150 => 40,  // Smart Kiosks
    153 => 41,  // Mobile POS
    154 => 42,  // POS Box PCs
    152 => 43,  // Latest Products
];

// 設定 Magento 1.9 媒體根目錄（請根據您的環境調整）
$mediaBasePath = '/data/magento1/media/catalog/product';

// 讀取設定檔
$configPath = '../config.json';
if (!file_exists($configPath)) {
    die("無法讀取設定檔 " . (string)$configPath);
}
$config = json_decode(file_get_contents($configPath), true);

// Magento 1.9 SOAP API 參數
$magentoDomain = rtrim($config['magento_domain'], '/') . '/';
$apiUser       = $config['api_user'];
$apiKey        = $config['api_key'];
$apiKey2        = $config['api_key2'];
$soapUrl       = $magentoDomain . 'api/soap/?wsdl';

// 建立 Magento 1.9 SOAP 連線與登入
try {
    $client = new SoapClient($soapUrl);
    $session = $client->login($apiUser, $apiKey);
    $products = $client->call($session, 'catalog_product.list');
} catch (Exception $e) {
    die("SOAP 連線或登入失敗，錯誤訊息: " . (string)$e->getMessage());
}

// 建立資料庫連線 (Magento 1.9 資料庫)
$dbHost     = $config['db_host'];
$dbName     = $config['db_name'];
$dbUser     = $config['db_user'];
$dbPassword = $config['db_password'];
$dsn = "mysql:host=" . (string)$dbHost . ";dbname=" . (string)$dbName . ";charset=utf8";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗：" . (string)$e->getMessage());
}

/**
 * 輔助函式：從指定表查詢單一值
 */
function getAttributeValue(PDO $pdo, $entityId, $attributeId, $table) {
    $sql = "SELECT value FROM {$table} WHERE entity_id = ? AND attribute_id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$entityId, $attributeId]);
    return $stmt->fetchColumn();
}

/**
 * 輔助函式：將 1.9 的 media_gallery entry 轉換為 Magento 2.4.7 所需格式
 * 移除了 "id" 欄位。
 */
function getMediaEntryPayload($entry, $mediaBasePath) {
    $fileRelative = $entry['value'];
    $filePath = rtrim($mediaBasePath, '/') . '/' . ltrim($fileRelative, '/');
    $content = [];
    if (file_exists($filePath)) {
         $fileData = file_get_contents($filePath);
         $base64Data = base64_encode($fileData);
         $finfo = finfo_open(FILEINFO_MIME_TYPE);
         $mimeType = finfo_file($finfo, $filePath);
         finfo_close($finfo);
         $filename = basename($filePath);
         $content = [
             "base64_encoded_data" => $base64Data,
             "type" => $mimeType,
             "name" => $filename
         ];
    } else {
         // 若檔案不存在，則設為空字串
         $content = [
             "base64_encoded_data" => "",
             "type" => "",
             "name" => ""
         ];
    }
    
    return [
         "media_type" => "image", // 假設為圖片
         "label" => isset($entry['label']) ? $entry['label'] : "",
         "position" => (int)$entry['position'],
         "disabled" => ((int)$entry['disabled'] === 0) ? false : true,
         "types" => [], // 如有指定 image types 可設定
         "file" => $fileRelative,
         "content" => $content,
         "extension_attributes" => [
             "video_content" => new stdClass() // 空物件
         ]
    ];
}

// 存放原始 Magento 1.9 產品資料（由 SOAP 取得）
$rawProducts = [];
// 存放最終組合後的 Magento 2.4.7 Payload 資料（不含 id 欄位）
$finalProducts = [];

// 處理每筆產品
foreach ($products as $prod) {
    try {
        $prodInfo = $client->call($session, 'catalog_product.info', $prod['product_id']);
    } catch (Exception $ex) {
        continue;
    }
    $entityId = isset($prodInfo['product_id']) ? $prodInfo['product_id'] : null;
    if (!$entityId) {
        continue;
    }
    $rawProducts[$entityId] = $prodInfo;
    
    // 僅處理 attribute_set_id 為 22 或 23 的產品（SOAP 回傳中的 "set" 欄位）
    $attrSet = isset($prodInfo['set']) ? (int)$prodInfo['set'] : 0;
    if ($attrSet !== 22 && $attrSet !== 23) {
        continue;
    }
    
    // attribute_set_id 轉換：22 -> 10；23 -> 9
    $newAttrSet = ($attrSet === 22) ? 10 : 9;
    
    // extension_attributes：固定 website_ids 為 [1]
    $extensionAttributes = [];
    $extensionAttributes['website_ids'] = [1];
    
    // category_links：從資料庫查詢該產品所屬分類，並根據 $migrationMap 轉換
    $catStmt = $pdo->prepare("SELECT category_id FROM catalog_category_product WHERE product_id = ?");
    $catStmt->execute([$entityId]);
    $categoryIds = $catStmt->fetchAll(PDO::FETCH_COLUMN);
    $categoryLinks = [];
    if ($categoryIds) {
        foreach ($categoryIds as $catId) {
            $catIdInt = (int)$catId;
            if (isset($migrationMap[$catIdInt])) {
                $categoryLinks[] = [
                    "position" => 0,
                    "category_id" => (string)$migrationMap[$catIdInt],
                    "extension_attributes" => new stdClass()
                ];
            }
        }
    }
    $extensionAttributes['category_links'] = $categoryLinks;
    
    // 其它 extension_attributes 補足：
    $extensionAttributes['discounts'] = []; // 無對應資料
    $extensionAttributes['bundle_product_options'] = []; // 未實作
    $extensionAttributes['stock_item'] = new stdClass(); // 固定為空物件
    $extensionAttributes['downloadable_product_links'] = []; // 未實作
    $extensionAttributes['downloadable_product_samples'] = []; // 未實作
    $extensionAttributes['giftcard_amounts'] = []; // 無對應資料
    
    // configurable_product_options: 若產品為 configurable，從 catalog_product_super_attribute 撈取（簡單範例）
    $configurableOptions = [];
    if (isset($prodInfo['type']) && $prodInfo['type'] == 'configurable') {
        $stmtOpt = $pdo->prepare("SELECT * FROM catalog_product_super_attribute WHERE product_id = ?");
        $stmtOpt->execute([$entityId]);
        $configurableOptions = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
    }
    $extensionAttributes['configurable_product_options'] = $configurableOptions;
    
    // configurable_product_links: 若產品為 configurable，從 catalog_product_super_link 撈取（簡單範例）
    $configurableLinks = [];
    if (isset($prodInfo['type']) && $prodInfo['type'] == 'configurable') {
        $stmtLink = $pdo->prepare("SELECT * FROM catalog_product_super_link WHERE parent_id = ?");
        $stmtLink->execute([$entityId]);
        $configurableLinks = $stmtLink->fetchAll(PDO::FETCH_ASSOC);
    }
    $extensionAttributes['configurable_product_links'] = $configurableLinks;
    
    // 其他頂層欄位：
    $productLinks = []; // Magento 1.9 無對應，留空
    $options = []; // 未實作，留空
    
    // media_gallery_entries: 從 catalog_product_entity_media_gallery 撈取資料，並轉換格式
    $mediaStmt = $pdo->prepare("SELECT mg.*, mgv.label, mgv.position, mgv.disabled 
                                FROM catalog_product_entity_media_gallery mg
                                LEFT JOIN catalog_product_entity_media_gallery_value mgv ON mg.value_id = mgv.value_id
                                WHERE mg.entity_id = ?");
    $mediaStmt->execute([$entityId]);
    $mediaRows = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);
    $mediaGalleryEntries = [];
    if ($mediaRows) {
        foreach ($mediaRows as $row) {
            $mediaGalleryEntries[] = getMediaEntryPayload($row, $mediaBasePath);
        }
    }
    
    // tier_prices: 從 catalog_product_entity_tier_price 撈取資料
    $tierStmt = $pdo->prepare("SELECT * FROM catalog_product_entity_tier_price WHERE entity_id = ?");
    $tierStmt->execute([$entityId]);
    $tierPrices = $tierStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$tierPrices) {
        $tierPrices = [];
    }
    
    // custom_attributes: 利用 UNION 從各 EAV 值表取得所有屬性，格式化為陣列
    $customAttributes = []; // 初始化
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
    
    // 組合符合 Magento 2.4.7 Payload 格式的產品資料，不帶 "id" 欄位
    $finalProducts[$entityId] = [
        "sku" => isset($prodInfo['sku']) ? $prodInfo['sku'] : '',
        "name" => $prodInfo['name'],
        "attribute_set_id" => $newAttrSet,
        "price" => (float)$prodInfo['price'],
        "status" => (int)$prodInfo['status'],
        "visibility" => (int)$prodInfo['visibility'],
        "type_id" => isset($prodInfo['type']) ? $prodInfo['type'] : '',
        "created_at" => isset($prodInfo['created_at']) ? $prodInfo['created_at'] : '',
        "updated_at" => isset($prodInfo['updated_at']) ? $prodInfo['updated_at'] : '',
        "weight" => (float)$prodInfo['weight'],
        "extension_attributes" => $extensionAttributes,
        "custom_attributes" => $customAttributes,
        "product_links" => $productLinks,
        "options" => $options,
        "media_gallery_entries" => $mediaGalleryEntries,
        "tier_prices" => $tierPrices
    ];
}

// 結束 SOAP session
$client->endSession($session);

// POST 至 Magento 2.4.7 REST API (與前述相同)
$postResults = [];
if (isset($_POST['post_to_m2'])) {
    $magento2Domain = rtrim($config['magento2_domain'], '/');
    $tokenUrl = $magento2Domain . '/rest/all/V1/integration/admin/token';
    $tokenPayload = json_encode([
       "username" => $apiUser,
       "password" => $apiKey2
    ]);
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $tokenPayload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    $tokenResponse = curl_exec($ch);
    if ($tokenResponse === false) {
        die("取得 Magento2 token 失敗: " . curl_error($ch));
    }
    curl_close($ch);
    $adminToken = json_decode($tokenResponse, true);
    if (!is_string($adminToken)) {
        die("取得 Magento2 token 失敗: " . (string)$tokenResponse);
    }
    
    foreach ($finalProducts as $product) {
        $payload = json_encode([
            "product" => $product,
            "saveOptions" => true
        ]);
        $m2ProductUrl = $magento2Domain . $config['rest_endpoint'] . "/products";
        $ch = curl_init($m2ProductUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $adminToken
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            $postResults[] = "產品 SKU " . (string)$product['sku'] . " POST 失敗: " . curl_error($ch);
        } else {
            $postResults[] = "產品 SKU " . (string)$product['sku'] . " POST 結果: " . (string)$response;
        }
        curl_close($ch);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>Magento 1.9 與 2.4.7 資料對照</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 13px; }
        .productBox { margin-bottom: 40px; border: 1px solid #ccc; padding: 10px; }
        .tableTitle { font-weight: bold; margin-top: 10px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 10px; }
        th, td { border: 1px solid #ddd; padding: 5px; vertical-align: top; }
        th { background-color: #f5f5f5; text-align: left; width: 30%; }
        pre { white-space: pre-wrap; word-wrap: break-word; margin: 0; }
        .postResult { margin-top: 20px; padding: 10px; background: #eef; border: 1px solid #99c; }
        .btnForm { margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>Magento 1.9 與 2.4.7 資料對照</h1>
    <form method="post" class="btnForm">
        <input type="submit" name="post_to_m2" value="POST 至 Magento2">
    </form>
    <?php foreach ($finalProducts as $entityId => $payloadData): ?>
    <div class="productBox">
        <h2>產品 ID: <?php echo htmlspecialchars((string)$entityId); ?> / SKU: <?php echo htmlspecialchars((string)$payloadData['sku']); ?></h2>
        
        <div class="tableTitle">Magento 1.9 原始欄位資訊</div>
        <table>
            <tbody>
                <?php
                if (isset($rawProducts[$entityId]) && is_array($rawProducts[$entityId])) {
                    foreach ($rawProducts[$entityId] as $key => $value) {
                        if (is_array($value)) {
                            $value = json_encode($value, JSON_PRETTY_PRINT);
                        }
                        echo "<tr><th>" . htmlspecialchars((string)$key) . "</th><td><pre>" . htmlspecialchars((string)$value) . "</pre></td></tr>";
                    }
                }
                ?>
            </tbody>
        </table>
        
        <div class="tableTitle">Magento 2.4.7 Payload 欄位資訊</div>
        <table>
            <tbody>
                <?php
                foreach ($payloadData as $key => $value) {
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_PRETTY_PRINT);
                    }
                    echo "<tr><th>" . htmlspecialchars((string)$key) . "</th><td><pre>" . htmlspecialchars((string)$value) . "</pre></td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    
    <?php if (!empty($postResults)): ?>
    <div class="postResult">
        <h2>Magento2 POST 結果</h2>
        <?php foreach ($postResults as $result): ?>
            <p><?php echo htmlspecialchars((string)$result); ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p>以上為 Magento 1.9 原始資料與更新後的 Magento 2.4.7 Payload 欄位資訊對照，請確認各欄位內容後，再進行後續處理。</p>
</body>
</html>
