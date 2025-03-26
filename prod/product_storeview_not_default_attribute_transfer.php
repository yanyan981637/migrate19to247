<?php
// ========================================================================
// 基本設定：不限制執行時間、開啟錯誤顯示
// ========================================================================
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
 * 5. custom_attributes 全部帶入，透過 union 查詢取得所有 store 的資料，但僅保留
 *    屬於該產品在 Magento 1 原始 "websites" 欄位對應的有效 store_id（依據 core_store 資料）的記錄。
 * 6. 最後依據不同 store 的 REST endpoint 逐一 POST 產品資料，並即時輸出 API 回應結果。
 *
 * 注意：giftcard_amounts 欄位已移除，因 Magento 2.4.7 不支援該欄位。
 */

// ========================================================================
// Mapping 設定
// ========================================================================

// Magento 1 category_id => Magento 2 category_id 映射
$migrationMap = [
    80  => 8,
    34  => 9,
    146 => 10,
    35  => 11,
    88  => 12,
    59  => 13,
    86  => 14,
    60  => 15,
    70  => 16,
    69  => 17,
    72  => 18,
    71  => 19,
    73  => 20,
    74  => 21,
    76  => 22,
    77  => 23,
    103 => 24,
    81  => 25,
    61  => 26,
    62  => 27,
    63  => 28,
    68  => 29,
    66  => 30,
    64  => 31,
    65  => 32,
    67  => 33,
    78  => 34,
    141 => 35,
    143 => 36,
    149 => 37,
    150 => 38,
    153 => 39,
    154 => 40,
    152 => 41,
];

// Magento 1 attribute_set_id => Magento 2 attribute_set_id 映射
$attributeSetMap = [
    22 => 10,
    23 => 9,
    // 可根據需要擴充
];

// Magento 1 store_id 到 Magento 2 store_id 全域映射
$storeMapping = [
    1  => 1,
    3  => 4,
    4  => 9,
    5  => 2,
    6  => 7,
    13 => 8,
    14 => 10,
    16 => 2,
    17 => 2,
    18 => 5,
    19 => 5,
    20 => 10,
    21 => 10,
    22 => 7,
    23 => 7,
    24 => 6,
    25 => 6,
    26 => 8,
    27 => 8,
    28 => 6,
    29 => 5,
    30 => 1,
    31 => 1,
    32 => 9,
    33 => 4,
    34 => 9,
    35 => 4,
    66 => 2,
    67 => 5,
    68 => 10,
    69 => 7,
    70 => 6,
    71 => 8,
    72 => 1,
    73 => 9,
    74 => 4,
    75 => 1,
    76 => 5,
    77 => 9,
    78 => 4,
    79 => 10,
    80 => 8,
    81 => 2,
    82 => 7,
    83 => 6,
];

// ========================================================================
// 讀取 config.json 與 Magento 2 REST endpoint 設定
// ========================================================================
$configPath = '../config.json';
if (!file_exists($configPath)) {
    die("無法讀取設定檔 " . $configPath);
}
$config = json_decode(file_get_contents($configPath), true);

// Magento 2 各 store 對應的 REST endpoint (依 store_id)
$restEndpoints = [
    1  => $config['rest_endpoint_en_eu_1'], // store_id 1
    2  => $config['rest_endpoint_en_gb_2'], // store_id 2
    4  => $config['rest_endpoint_nl_be_4'], // store_id 4
    5  => $config['rest_endpoint_en_au_5'], // store_id 5
    6  => $config['rest_endpoint_en_us_6'], // store_id 6
    7  => $config['rest_endpoint_nl_nl_7'], // store_id 7
    8  => $config['rest_endpoint_fr_fr_8'], // store_id 8
    9  => $config['rest_endpoint_fr_be_9'], // store_id 9
    10 => $config['rest_endpoint_de_de_10'], // store_id 10
];

// ========================================================================
// Magento 1 SOAP API 與資料庫連線設定
// ========================================================================
$magentoDomain = rtrim($config['magento_domain'], '/') . '/';
$apiUser       = $config['api_user'];
$apiKey        = $config['api_key'];
$apiKey2       = $config['api_key2'];
$soapUrl       = $magentoDomain . 'api/soap/?wsdl';

try {
    $client = new SoapClient($soapUrl);
    $session = $client->login($apiUser, $apiKey);
    $products = $client->call($session, 'catalog_product.list');
} catch (Exception $e) {
    die("SOAP 連線或登入失敗: " . $e->getMessage());
}

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

// ========================================================================
// 查詢 Magento 1 core_store 表，建立網站對 store_id 的 mapping
// ========================================================================
$coreStoreMapping = [];
try {
    $stmtStore = $pdo->query("SELECT store_id, website_id FROM core_store WHERE website_id > 0");
    while ($row = $stmtStore->fetch(PDO::FETCH_ASSOC)) {
        $websiteId = $row['website_id'];
        $storeId   = $row['store_id'];
        if (!isset($coreStoreMapping[$websiteId])) {
            $coreStoreMapping[$websiteId] = [];
        }
        $coreStoreMapping[$websiteId][] = $storeId;
    }
} catch (PDOException $e) {
    die("查詢 core_store 失敗: " . $e->getMessage());
}

// ========================================================================
// 輔助函式
// ========================================================================
function getAttributeValue(PDO $pdo, $entityId, $attributeId, $table) {
    $sql = "SELECT value FROM {$table} WHERE entity_id = ? AND attribute_id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$entityId, $attributeId]);
    return $stmt->fetchColumn();
}

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

// ========================================================================
// 定義需要做選單型屬性轉換的屬性清單
// ========================================================================
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

// ========================================================================
// 取得 Magento 1 產品資料與轉換 Payload
// ========================================================================
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
    
    // 設定 extension_attributes，至少包含 website_ids (預設 [1])
    $extensionAttributes = [];
    $extensionAttributes['website_ids'] = [1];
    
    // 處理 category_links：查詢 Magento 1 資料庫中的分類，並依據 migrationMap 轉換
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
    
    // configurable_product_options 與 links (針對 configurable 產品)
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
    
    // 處理 media_gallery_entries：讀取圖片資料後轉成 base64 (失敗則不納入)
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
    
    // 處理 tier_prices
    $tierStmt = $pdo->prepare("SELECT * FROM catalog_product_entity_tier_price WHERE entity_id = ?");
    $tierStmt->execute([$entityId]);
    $tierPrices = $tierStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$tierPrices) {
        $tierPrices = [];
    }
    
    // 處理 custom_attributes：透過 union 查詢取得所有 store_id 的資料
    $customAttributesAssoc = [];
    $unionQuery = "
      SELECT ea.attribute_code, v.value, v.store_id
      FROM eav_attribute ea
      JOIN (
          SELECT attribute_id, value, store_id FROM catalog_product_entity_varchar WHERE entity_id = ?
          UNION ALL
          SELECT attribute_id, value, store_id FROM catalog_product_entity_int WHERE entity_id = ?
          UNION ALL
          SELECT attribute_id, value, store_id FROM catalog_product_entity_text WHERE entity_id = ?
          UNION ALL
          SELECT attribute_id, value, store_id FROM catalog_product_entity_decimal WHERE entity_id = ?
          UNION ALL
          SELECT attribute_id, value, store_id FROM catalog_product_entity_datetime WHERE entity_id = ?
      ) AS v ON ea.attribute_id = v.attribute_id
      WHERE ea.entity_type_id = (
          SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product'
      )
    ";
    $stmt = $pdo->prepare($unionQuery);
    $stmt->execute([$entityId, $entityId, $entityId, $entityId, $entityId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 取得 Magento 1 原始資料中 "websites" 欄位的資料（可能為 JSON 字串或陣列）
    $productWebsites = [];
    if (isset($rawProducts[$entityId]['websites'])) {
        if (is_string($rawProducts[$entityId]['websites'])) {
            $productWebsites = json_decode($rawProducts[$entityId]['websites'], true);
        } elseif (is_array($rawProducts[$entityId]['websites'])) {
            $productWebsites = $rawProducts[$entityId]['websites'];
        }
    }
    // 根據 core_store mapping 取得該產品有效的 store id 清單
    $productValidStoreIds = [];
    if (!empty($productWebsites)) {
        foreach ($productWebsites as $websiteId) {
            if (isset($coreStoreMapping[$websiteId])) {
                $productValidStoreIds = array_merge($productValidStoreIds, $coreStoreMapping[$websiteId]);
            }
        }
        $productValidStoreIds = array_unique($productValidStoreIds);
    }
    
    // 過濾 custom_attributes，只保留屬於有效 store id 的資料
    foreach ($results as $row) {
        // 防呆：若未定義 store_id，則跳過
        if (!isset($row['store_id'])) {
            continue;
        }
        $attrCode  = $row['attribute_code'];
        $attrValue = $row['value'];
        $storeId   = $row['store_id'];
        
        if (!in_array($storeId, $productValidStoreIds)) {
            continue;
        }
        
        // 若該屬性需要做選單型轉換且值為數字（代表 M1 的 option_id）
        if (in_array($attrCode, $attributesToConvert, true) && is_numeric($attrValue)) {
            $m1OptionId = (int)$attrValue;
            $sqlM1Label = "
                SELECT value
                FROM eav_attribute_option_value
                WHERE option_id = :option_id
                  AND store_id = :store_id
                LIMIT 1
            ";
            $stmtM1Label = $pdo->prepare($sqlM1Label);
            $stmtM1Label->execute([':option_id' => $m1OptionId, ':store_id' => $storeId]);
            $m1Label = $stmtM1Label->fetchColumn();
            
            if ($m1Label !== false) {
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
                    $sqlM2Option = "
                        SELECT oa.option_id
                        FROM eav_attribute_option AS oa
                        INNER JOIN eav_attribute_option_value AS oav
                            ON oa.option_id = oav.option_id
                        WHERE oa.attribute_id = :attr_id
                          AND oav.value = :label
                          AND oav.store_id = :store_id
                        LIMIT 1
                    ";
                    $stmtM2Option = $pdo2->prepare($sqlM2Option);
                    $stmtM2Option->execute([
                        ':attr_id' => $m2AttributeId,
                        ':label'   => $m1Label,
                        ':store_id'=> $storeId
                    ]);
                    $m2OptionId = $stmtM2Option->fetchColumn();
                    
                    if ($m2OptionId) {
                        $attrValue = $m2OptionId;
                    }
                }
            }
        }
        
        $customAttributesAssoc[$attrCode][] = [
            "attribute_code" => $attrCode,
            "store_id"       => $storeId,
            "value"          => $attrValue
        ];
    }
    
    // 將分組資料平展成一維陣列
    $customAttributes = [];
    foreach ($customAttributesAssoc as $attrRecords) {
        foreach ($attrRecords as $record) {
            $customAttributes[] = $record;
        }
    }
    
    // 組合最終產品資料，符合 Magento 2 Payload 格式
    $finalProducts[$entityId] = [
        "sku"                   => isset($prodInfo['sku']) ? $prodInfo['sku'] : '',
        "name"                  => $prodInfo['name'],
        "attribute_set_id"      => $newAttrSet,
        "price"                 => (float)$prodInfo['price'],
        "status"                => (int)$prodInfo['status'],
        "visibility"            => (int)$prodInfo['visibility'],
        "type_id"               => isset($prodInfo['type']) ? $prodInfo['type'] : '',
        "created_at"            => isset($prodInfo['created_at']) ? $prodInfo['created_at'] : '',
        "updated_at"            => isset($prodInfo['updated_at']) ? $prodInfo['updated_at'] : '',
        "weight"                => (float)$prodInfo['weight'],
        "extension_attributes"  => $extensionAttributes,
        "custom_attributes"     => $customAttributes,
        "product_links"         => $productLinks,
        "options"               => $options,
        "media_gallery_entries" => $mediaGalleryEntries,
        "tier_prices"           => $tierPrices
    ];
}

// 結束 Magento 1 SOAP session
$client->endSession($session);

// ========================================================================
// 將產品資料依據 custom_attributes 中 store_id (非 0) 分組，產生各 store 的 payload
// ========================================================================
$payloadsByStore = [];
foreach ($finalProducts as $entityId => $product) {
    if (!isset($product['custom_attributes']) || !is_array($product['custom_attributes'])) {
        continue;
    }
    $groupedAttributes = [];
    foreach ($product['custom_attributes'] as $attr) {
        // 透過 $storeMapping 將 Magento 1 store_id 轉換為 Magento 2 store_id
        $m1StoreId = $attr['store_id'];
        if (isset($storeMapping[$m1StoreId])) {
            $magento2StoreId = $storeMapping[$m1StoreId];
            $groupedAttributes[$magento2StoreId][] = $attr;
        }
    }
    foreach ($groupedAttributes as $storeId => $attrs) {
        $storeProduct = $product;
        $storeProduct['custom_attributes'] = $attrs;
        $payloadsByStore[$storeId][$storeProduct['sku']] = $storeProduct;
    }
}

// ========================================================================
// 輸出 HTML 結果：依據各 Magento 2 Store REST endpoint POST Payload
// ========================================================================
$postResults = [];
if (isset($_POST['post_to_m2'])) {
    // 先取得 Magento 2 admin token (使用 all endpoint)
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
    
    // 依據每個 store 的 payload POST 至對應 REST endpoint
    foreach ($restEndpoints as $storeId => $endpoint) {
        echo "<h2>開始 POST 至 Store ID {$storeId} (Endpoint: {$endpoint})</h2>";
        if (isset($payloadsByStore[$storeId]) && !empty($payloadsByStore[$storeId])) {
            foreach ($payloadsByStore[$storeId] as $sku => $product) {
                $attempt = 0;
                $maxAttempt = 2;
                do {
                    $finalPayload = [
                        "product" => $product,
                        "saveOptions" => true
                    ];
                    $payload = json_encode($finalPayload);
                    $m2ProductUrl = $magento2Domain . $endpoint . "/products";
                    $ch = curl_init($m2ProductUrl);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $adminToken
                    ]);
                    $response = curl_exec($ch);
                    $resultText = ($response === false) ? curl_error($ch) : (string)$response;
                    curl_close($ch);
                    
                    // 如果首次嘗試時收到 URL key 重複錯誤，則更新 url_key (只重試一次)
                    if ($attempt == 0 && strpos($resultText, "URL key for specified store already exists.") !== false) {
                        foreach ($product['custom_attributes'] as &$attr) {
                            if (strtolower($attr['attribute_code']) == 'url_key') {
                                $attr['value'] .= "-1";
                            }
                        }
                        unset($attr);
                        $resultText = "";
                    }
                    $attempt++;
                } while (empty($resultText) && $attempt < $maxAttempt);
                
                $postResults[] = "Store {$storeId} 產品 SKU {$sku} POST 結果: " . $resultText;
                echo "<p><strong>Store {$storeId} 產品 SKU {$sku} POST 結果:</strong> " . htmlspecialchars($resultText) . "</p>";
                flush();
            }
        } else {
            echo "<p>Store {$storeId} 無產品資料</p>";
        }
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
                    <th>訊息</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($postResults as $result): ?>
                <tr>
                    <td><pre><?php echo htmlspecialchars($result); ?></pre></td>
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
    
    <div class="postResult">
        <h2>各 Magento 2 Store Payload（依 Endpoint）</h2>
        <?php
        foreach ($restEndpoints as $storeId => $endpoint) {
            echo "<h3>Endpoint for Store ID {$storeId} ({$endpoint})</h3>";
            if (isset($payloadsByStore[$storeId]) && !empty($payloadsByStore[$storeId])) {
                foreach ($payloadsByStore[$storeId] as $sku => $payload) {
                    echo "<h4>產品 SKU: " . htmlspecialchars($sku) . "</h4>";
                    $finalPayload = [
                        "product" => $payload,
                        "saveOptions" => true
                    ];
                    echo "<pre>" . htmlspecialchars(json_encode($finalPayload, JSON_PRETTY_PRINT)) . "</pre>";
                }
            } else {
                echo "<p>無產品資料</p>";
            }
        }
        ?>
    </div>
    
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
