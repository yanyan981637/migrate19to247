<?php
// 設定不限制執行時間與顯示所有錯誤
set_time_limit(0);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 是否顯示 Magento 2.4.7 Payload 詳細資料表格（可自行控制）
$showPayloadDetails = true;

// 全域用來記錄圖片讀取錯誤的陣列
$imageErrors = [];

/**
 * 此程式整合 Magento 1.9 SOAP API 與資料庫查詢，
 * 取得所有產品資料（僅保留 attribute_set_id 為特定值的產品），
 * 並組合出符合 Magento 2.4.7 /V1/products Payload 格式的資料。
 *
 * 主要功能：
 * 1. 將 Magento 1.9 的產品資料轉換為 Magento 2.4.7 所需的 payload 格式（不含 id 欄位）。
 * 2. attribute_set_id 依據 mapping 表轉換。
 * 3. 依據 migrationMap 轉換 category_links。
 * 4. 將 media_gallery_entries 由 Magento 1.9 的圖片資料，透過 magentoDomain 組合完整圖片 URL，
 *    使用 HTTP 讀取圖片，轉成 base64 格式；若讀取失敗則不納入 payload，並記錄錯誤圖片路徑。
 * 5. custom_attributes 全部帶入，對於特定屬性若值為選單型 option_id，
 *    則依據 Magento 1.9 與 2.4.7 對應關係轉換 option_id，同時只抓取 store_id 為 0 的唯一值。
 * 6. 執行 API POST 時，依序送出並即時在瀏覽器輸出 response 資訊。
 *
 * 注意：giftcard_amounts 欄位已移除，因 Magento 2.4.7 不支援該欄位。
 */

// migration mapping
$migrationMap = [
    // key: Magento 1 category_id, value: Magento 2 category_id
    80  => 8,  // MioWORK™
    34  => 9,  // Handhelds
    146 => 10,  // A500s Series
    35  => 11,  // Fleet Tablets
    88  => 12,  // F740s
    59  => 13,  // Industrial Tablets
    86  => 14,  // L1000 Series
    60  => 15,  // Legacy
    70  => 16,  // F700 Series
    69  => 17,  // A500 Series
    72  => 18,  // L130 Series
    71  => 19,  // L100 Series
    73  => 20,  // A200/A300 Series
    74  => 21,  // L70 Series
    76  => 22,  // Latest Products
    77  => 23,  // MiDM™
    103 => 24,  // Cradle
    81  => 25,  // MioCARE™
    61  => 26,  // Handhelds
    62  => 27,  // Tablets
    63  => 28,  // Legacy
    68  => 29,  // A300 Series
    66  => 30,  // L130 Series
    64  => 31,  // A200 Series
    65  => 32,  // MiCor
    67  => 33,  // A500 Series
    78  => 34,  // Latest Products
    141 => 35,  // MioEYE™
    143 => 36,  // Fleet Cameras
    149 => 37,  // MioServ™
    150 => 38,  // Smart Kiosks
    153 => 39,  // Mobile POS
    154 => 40,  // POS Box PCs
    152 => 41,  // Latest Products
];

$attributeSetMap = [
    22 => 10,
    23 => 9,
    // 如有需要，可擴充
];

// 讀取設定檔
$configPath = '../config.json';
if (!file_exists($configPath)) {
    die("無法讀取設定檔 " . $configPath);
}
$config = json_decode(file_get_contents($configPath), true);

// Magento 1.9 SOAP API 參數
$magentoDomain = rtrim($config['magento_domain'], '/') . '/';
$apiUser       = $config['api_user'];
$apiKey        = $config['api_key'];
$apiKey2       = $config['api_key2'];
$soapUrl       = $magentoDomain . 'api/soap/?wsdl';

// 建立 Magento 1.9 SOAP 連線與登入
try {
    $client = new SoapClient($soapUrl);
    $session = $client->login($apiUser, $apiKey);
    $products = $client->call($session, 'catalog_product.list');
} catch (Exception $e) {
    die("SOAP 連線或登入失敗: " . $e->getMessage());
}

// 建立連線到 Magento 1.9 資料庫 (M1)
$dbHost     = $config['db_host'];
$dbName     = $config['db_name'];
$dbUser     = $config['db_user'];
$dbPassword = $config['db_password'];
$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8";
try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}

// 建立連線到 Magento 2.4.7 資料庫 (M2)
// 請確保 config.json 中有對應的 db_host_m2、db_name_m2、db_user_m2、db_password_m2 設定
$dbHostM2     = $config['db_host_m2'];
$dbNameM2     = $config['db_name_m2'];
$dbUserM2     = $config['db_user_m2'];
$dbPasswordM2 = $config['db_password_m2'];
$dsnM2 = "mysql:host={$dbHostM2};dbname={$dbNameM2};charset=utf8";
try {
    $pdo2 = new PDO($dsnM2, $dbUserM2, $dbPasswordM2);
    $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("M2資料庫連線失敗: " . $e->getMessage());
}

/**
 * 取得單一屬性值 (M1)
 */
function getAttributeValue(PDO $pdo, $entityId, $attributeId, $table) {
    $sql = "SELECT value FROM {$table} WHERE entity_id = ? AND attribute_id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$entityId, $attributeId]);
    return $stmt->fetchColumn();
}

/**
 * 處理 media_gallery entry
 * 若讀取圖片失敗，則不回傳該項目，並記錄圖片 URL
 */
function getMediaEntryPayload($entry, $magentoDomain) {
    global $imageErrors;
    $fileRelative = $entry['value'];
    $fileUrl = rtrim($magentoDomain, '/') . '/media/catalog/product/' . ltrim($fileRelative, '/');
    
    $fileData = @file_get_contents($fileUrl);
    if ($fileData === false) {
        $imageErrors[] = $fileUrl;
        return null;
    }
    $base64Data = base64_encode($fileData);
    $headers = get_headers($fileUrl, 1);
    $mimeType = isset($headers["Content-Type"]) ? $headers["Content-Type"] : '';
    $filename = basename($fileUrl);
    $content = [
        "base64_encoded_data" => $base64Data,
        "type"                => $mimeType,
        "name"                => $filename
    ];
    
    return [
        "media_type" => "image",
        "label"      => isset($entry['label']) ? $entry['label'] : "",
        "position"   => (int)$entry['position'],
        "disabled"   => ((int)$entry['disabled'] === 0) ? false : true,
        "types"      => ["image", "small_image", "thumbnail", "swatch_image"],
        "file"       => $fileRelative,
        "content"    => $content,
        "extension_attributes" => [
            "video_content" => new stdClass()
        ]
    ];
}

// 建立需要做選單型屬性轉換的屬性清單
$attributesToConvert = [
    'product_banner_title_color001',
    'product_banner_st_color001',
    'product_banner_desc_color001',
    'font_anim001',
    'product_banner_theme001',
    'product_banner_title_color002',
    'product_banner_st_color002',
    'product_banner_desc_color002',
    'font_anim002',
    'product_banner_theme002',
    'product_banner_title_color003',
    'product_banner_st_color003',
    'product_banner_desc_color003',
    'font_anim003',
    'product_banner_theme003',
    'product_banner_title_color004',
    'product_banner_st_color004',
    'product_banner_desc_color004',
    'font_anim004',
    'product_banner_theme004',
    'product_banner_title_color005',
    'product_banner_st_color005',
    'product_banner_desc_color005',
    'font_anim005',
    'product_banner_theme005',
];

// 取得 Magento 1.9 產品資料與轉換 payload
$rawProducts = [];
$finalProducts = [];

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
    
    // 僅處理 attribute_set_id 在 mapping 表中的產品
    $attrSet = isset($prodInfo['set']) ? (int)$prodInfo['set'] : 0;
    if (!isset($attributeSetMap[$attrSet])) {
        continue;
    }
    $newAttrSet = $attributeSetMap[$attrSet];
    
    // extension_attributes 設定
    $extensionAttributes = [];
    $extensionAttributes['website_ids'] = [1];
    
    // category_links
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
    
    $extensionAttributes['discounts']                   = [];
    $extensionAttributes['bundle_product_options']      = [];
    $extensionAttributes['stock_item']                  = new stdClass();
    $extensionAttributes['downloadable_product_links']  = [];
    $extensionAttributes['downloadable_product_samples'] = [];
    
    // configurable_product_options & links
    $configurableOptions = [];
    if (isset($prodInfo['type']) && $prodInfo['type'] == 'configurable') {
        $stmtOpt = $pdo->prepare("SELECT * FROM catalog_product_super_attribute WHERE product_id = ?");
        $stmtOpt->execute([$entityId]);
        $configurableOptions = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
    }
    $extensionAttributes['configurable_product_options'] = $configurableOptions;
    
    $configurableLinks = [];
    if (isset($prodInfo['type']) && $prodInfo['type'] == 'configurable') {
        $stmtLink = $pdo->prepare("SELECT * FROM catalog_product_super_link WHERE parent_id = ?");
        $stmtLink->execute([$entityId]);
        $configurableLinks = $stmtLink->fetchAll(PDO::FETCH_ASSOC);
    }
    $extensionAttributes['configurable_product_links'] = $configurableLinks;
    
    $productLinks = [];
    $options = [];
    
    // media_gallery_entries 只加入讀取成功的圖片
    $mediaStmt = $pdo->prepare("
        SELECT mg.*, mgv.label, mgv.position, mgv.disabled 
        FROM catalog_product_entity_media_gallery mg
        LEFT JOIN catalog_product_entity_media_gallery_value mgv 
               ON mg.value_id = mgv.value_id
        WHERE mg.entity_id = ?
    ");
    $mediaStmt->execute([$entityId]);
    $mediaRows = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);
    $mediaGalleryEntries = [];
    if ($mediaRows) {
        foreach ($mediaRows as $row) {
            $entryPayload = getMediaEntryPayload($row, $magentoDomain);
            // $entryPayload = null;
            if ($entryPayload !== null) {
                $mediaGalleryEntries[] = $entryPayload;
            }
        }
    }
    
    // tier_prices
    $tierStmt = $pdo->prepare("SELECT * FROM catalog_product_entity_tier_price WHERE entity_id = ?");
    $tierStmt->execute([$entityId]);
    $tierPrices = $tierStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$tierPrices) {
        $tierPrices = [];
    }
    
    // custom_attributes 全部帶入，使用關聯陣列以 attribute_code 為 key 排除重複，且只取 store_id = 0 的資料
    $customAttributesAssoc = [];
    $unionQuery = "
      SELECT ea.attribute_code, v.value
      FROM eav_attribute ea
      JOIN (
          SELECT attribute_id, value FROM catalog_product_entity_varchar WHERE entity_id = ? AND store_id = 0
          UNION ALL
          SELECT attribute_id, value FROM catalog_product_entity_int WHERE entity_id = ? AND store_id = 0
          UNION ALL
          SELECT attribute_id, value FROM catalog_product_entity_text WHERE entity_id = ? AND store_id = 0
          UNION ALL
          SELECT attribute_id, value FROM catalog_product_entity_decimal WHERE entity_id = ? AND store_id = 0
          UNION ALL
          SELECT attribute_id, value FROM catalog_product_entity_datetime WHERE entity_id = ? AND store_id = 0
      ) AS v ON ea.attribute_id = v.attribute_id
      WHERE ea.entity_type_id = (
          SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product'
      )
    ";
    $stmt = $pdo->prepare($unionQuery);
    $stmt->execute([$entityId, $entityId, $entityId, $entityId, $entityId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($results as $row) {
        $attrCode  = $row['attribute_code'];
        $attrValue = $row['value'];
        
        // 若該屬性需要做選單型轉換且值為數字 (代表 M1 的 option_id)
        if (in_array($attrCode, $attributesToConvert, true) && is_numeric($attrValue)) {
            $m1OptionId = (int)$attrValue;
            
            // 1. 從 M1 的 eav_attribute_option_value 取 label (store_id=0)
            $sqlM1Label = "
                SELECT value
                FROM eav_attribute_option_value
                WHERE option_id = :option_id
                  AND store_id = 0
                LIMIT 1
            ";
            $stmtM1Label = $pdo->prepare($sqlM1Label);
            $stmtM1Label->execute([':option_id' => $m1OptionId]);
            $m1Label = $stmtM1Label->fetchColumn();
            
            if ($m1Label !== false) {
                // 2. 在 M2 的 eav_attribute 取該 attribute_code 的 attribute_id (entity_type_id=4)
                $sqlM2Attr = "
                    SELECT attribute_id
                    FROM eav_attribute
                    WHERE attribute_code = :attr_code
                      AND entity_type_id = 4
                    LIMIT 1
                ";
                $stmtM2Attr = $pdo2->prepare($sqlM2Attr);
                $stmtM2Attr->execute([':attr_code' => $attrCode]);
                $m2AttributeId = $stmtM2Attr->fetchColumn();
                
                if ($m2AttributeId) {
                    // 3. 根據 attribute_id 與 label 從 M2 的 eav_attribute_option 找出對應的 option_id
                    $sqlM2Option = "
                        SELECT oa.option_id
                        FROM eav_attribute_option AS oa
                        INNER JOIN eav_attribute_option_value AS oav
                            ON oa.option_id = oav.option_id
                        WHERE oa.attribute_id = :attr_id
                          AND oav.value = :label
                          AND oav.store_id = 0
                        LIMIT 1
                    ";
                    $stmtM2Option = $pdo2->prepare($sqlM2Option);
                    $stmtM2Option->execute([
                        ':attr_id' => $m2AttributeId,
                        ':label'   => $m1Label
                    ]);
                    $m2OptionId = $stmtM2Option->fetchColumn();
                    
                    // 4. 如果有找到，就以 M2 option_id 覆蓋原 M1 的值
                    if ($m2OptionId) {
                        $attrValue = $m2OptionId;
                    }
                }
            }
        }
        
        // 只保留第一筆相同 attribute_code 的紀錄
        if (!isset($customAttributesAssoc[$attrCode])) {
            $customAttributesAssoc[$attrCode] = [
                "attribute_code" => $attrCode,
                "value" => $attrValue
            ];
        }
    }
    $customAttributes = array_values($customAttributesAssoc);
    
    // 組合最終產品陣列
    $finalProducts[$entityId] = [
        "sku"                 => isset($prodInfo['sku']) ? $prodInfo['sku'] : '',
        "name"                => $prodInfo['name'],
        "attribute_set_id"    => $newAttrSet,
        "price"               => (float)$prodInfo['price'],
        "status"              => (int)$prodInfo['status'],
        "visibility"          => (int)$prodInfo['visibility'],
        "type_id"             => isset($prodInfo['type']) ? $prodInfo['type'] : '',
        "created_at"          => isset($prodInfo['created_at']) ? $prodInfo['created_at'] : '',
        "updated_at"          => isset($prodInfo['updated_at']) ? $prodInfo['updated_at'] : '',
        "weight"              => (float)$prodInfo['weight'],
        "extension_attributes"=> $extensionAttributes,
        "custom_attributes"   => $customAttributes,
        "product_links"       => $productLinks,
        "options"             => $options,
        "media_gallery_entries"=> $mediaGalleryEntries,
        "tier_prices"         => $tierPrices
    ];
}

// 結束 SOAP session
$client->endSession($session);

// POST 至 Magento 2.4.7 REST API
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $tokenResponse = curl_exec($ch);
    if ($tokenResponse === false) {
        die("取得 Magento2 token 失敗: " . curl_error($ch));
    }
    curl_close($ch);
    $adminToken = json_decode($tokenResponse, true);
    if (!is_string($adminToken)) {
        die("取得 Magento2 token 失敗: " . $tokenResponse);
    }
    
    // 初始化存放每筆 POST 詳細資料的陣列
    $postDetails = [];
    
    // 依序送出 API 並即時輸出 response 資訊
    foreach ($finalProducts as $entityId => $product) {
        $attempt = 0;
        $maxAttempt = 2; // 最多重送 2 次
        do {
            $payload = json_encode([
                "product" => $product,
                "saveOptions" => true
            ]);
            $m2ProductUrl = $magento2Domain . $config['rest_endpoint'] . "/products";
            $ch = curl_init($m2ProductUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $adminToken
            ]);
            $response = curl_exec($ch);
            $sku = (string)$product['sku'];
            $resultText = ($response === false) ? curl_error($ch) : (string)$response;
            curl_close($ch);
            
            // 若出現 URL key 重複錯誤，則修改 url_key 值（僅首次嘗試）
            if ($attempt == 0 && strpos($resultText, "URL key for specified store already exists.") !== false) {
                foreach ($product['custom_attributes'] as &$attr) {
                    if (strtolower($attr['attribute_code']) == 'url_key') {
                        $attr['value'] .= "-1";
                    }
                }
                unset($attr);
                $finalProducts[$entityId]['custom_attributes'] = $product['custom_attributes'];
                // 清空 resultText 以便重送
                $resultText = "";
            }
            $attempt++;
        } while (empty($resultText) && $attempt < $maxAttempt);
        
        $postDetails[] = [
            "sku"     => $sku,
            "payload" => $payload,
            "response"=> $resultText
        ];
        
        $postResults[] = "產品 SKU " . $sku . " POST 結果: " . $resultText;
        
        // 即時輸出本次 API 呼叫結果
        echo "<p><strong>產品 SKU {$sku} POST 結果:</strong> " . htmlspecialchars($resultText) . "</p>";
        flush();
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
    
    <?php if (isset($_POST['post_to_m2'])): ?>
        <h2>REST API POST 狀態 (依序顯示)</h2>
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Payload</th>
                    <th>Response</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($postDetails as $detail): ?>
                <tr>
                    <td><?php echo htmlspecialchars($detail['sku']); ?></td>
                    <td><pre><?php echo htmlspecialchars($detail['payload']); ?></pre></td>
                    <td><pre><?php echo htmlspecialchars($detail['response']); ?></pre></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
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
        <?php if ($showPayloadDetails): ?>
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
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    
    <?php if (!empty($postResults)): ?>
    <div class="postResult">
        <h2>Magento2 POST 結果摘要</h2>
        <?php foreach ($postResults as $result): ?>
            <p><?php echo htmlspecialchars($result); ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($imageErrors)): ?>
    <div class="postResult">
        <h2>圖片讀取錯誤</h2>
        <ul>
            <?php foreach ($imageErrors as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <p>
        以上為 Magento 1.9 原始資料與更新後的 Magento 2.4.7 Payload 欄位資訊對照。<br>
        若 POST 時有錯誤，請根據錯誤碼檢查：<br>
        - 401 Unauthorized：檢查 admin token 與 API 權限。<br>
        - 400 Bad Request：檢查 payload 格式與必填欄位是否正確。<br>
        其他錯誤請參考 Magento 2 REST API 文件與伺服器日誌。
    </p>
</body>
</html>
