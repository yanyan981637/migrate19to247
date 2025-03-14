<?php
/**
 * 本程式做以下幾件事：
 * 1. 從 ../config.json 讀取 Magento 1 與 Magento 2 的設定。
 * 2. 連線到 Magento 1.9 資料庫，找出「自訂分類屬性」以及每個分類對應的屬性數值。
 * 3. 使用先前遷移時產生的 (m1_category_id => m2_category_id) 對照表，找到在 Magento 2 上的對應分類。
 * 4. 透過 Magento 2 REST API (PUT /V1/categories/:id)，將這些屬性寫回到對應的分類。
 * 5. 程式會印出每個請求的 Payload 與 Request URL，方便除錯。
 */

// -------------------------------------------------------------------------
// 1. 載入設定檔
// -------------------------------------------------------------------------
$configPath = '../config.json';
if (!file_exists($configPath)) {
    die("找不到配置檔案: {$configPath}");
}
$config = json_decode(file_get_contents($configPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("解析 JSON 配置檔錯誤: " . json_last_error_msg());
}

// Magento 1.9 資料庫連線資訊
$m1DbHost     = $config['db_host'];
$m1DbName     = $config['db_name'];
$m1DbUser     = $config['db_user'];
$m1DbPassword = $config['db_password'];

// Magento 2 API
$m2Domain     = rtrim($config['magento2_domain'], '/') . '/';
$restEndpoint = $config['rest_endpoint']; // 例如 "/rest/all/V1"

// Magento 1 & 2 API 帳密 (假設 2.4.7 的 admin 帳密也在同樣欄位)
$apiUser = $config['api_user'];
$apiKey  = $config['api_key'];

// -------------------------------------------------------------------------
// 2. 建立 Magento 1 資料庫連線（PDO）
// -------------------------------------------------------------------------
try {
    $dsn = "mysql:host={$m1DbHost};dbname={$m1DbName};charset=utf8";
    $pdoM1 = new PDO($dsn, $m1DbUser, $m1DbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("無法連線到 Magento 1 資料庫: " . $e->getMessage());
}

// -------------------------------------------------------------------------
// 3. 取得 Magento 2 Admin Token，用於後續 REST API 認證
// -------------------------------------------------------------------------
function getMagento2AdminToken($m2Domain, $restEndpoint, $username, $password) {
    $tokenUrl = $m2Domain . $restEndpoint . '/integration/admin/token';
    $ch = curl_init($tokenUrl);
    $payload = json_encode(["username" => $username, "password" => $password]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Content-Length: " . strlen($payload)
    ]);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        die("CURL Error (Admin Token): " . curl_error($ch));
    }
    curl_close($ch);
    $token = json_decode($result, true);
    if (!$token) {
        die("取得 Magento 2 Admin Token 失敗: " . $result);
    }
    return $token;
}
$m2AdminToken = getMagento2AdminToken($m2Domain, $restEndpoint, $apiUser, $apiKey);
echo "<h3>Magento 2 Admin Token:</h3><pre>" . htmlspecialchars($m2AdminToken) . "</pre>";

// -------------------------------------------------------------------------
// 4. 取得 Magento 1.9 的「自訂分類屬性」清單
//    - 在 eav_attribute / eav_entity_type 找出屬於 catalog_category 的屬性
//    - 過濾系統內建屬性 (is_user_defined = 1)，可依需求調整
// -------------------------------------------------------------------------
$sqlAttributes = "
    SELECT
        ea.attribute_id,
        ea.attribute_code,
        ea.backend_type,
        ea.is_user_defined
    FROM eav_attribute AS ea
    INNER JOIN eav_entity_type AS eet ON ea.entity_type_id = eet.entity_type_id
    WHERE
        eet.entity_type_code = 'catalog_category'
        AND ea.is_user_defined = 1
";
$stmtAttr = $pdoM1->query($sqlAttributes);
$customAttrList = $stmtAttr->fetchAll(PDO::FETCH_ASSOC);

if (empty($customAttrList)) {
    die("在 Magento 1 找不到任何自訂分類屬性 (is_user_defined=1)");
}

echo "<h3>Magento 1 自訂分類屬性清單</h3>";
echo "<pre>";
print_r($customAttrList);
echo "</pre>";

// -------------------------------------------------------------------------
// 5. 查詢這些屬性的實際數值
//    - 依 backend_type 決定要從 catalog_category_entity_<backend_type> 表中取值
//    - 其他類型：int、text、decimal、datetime
// -------------------------------------------------------------------------
$backendTableMap = [
    'varchar'  => 'catalog_category_entity_varchar',
    'int'      => 'catalog_category_entity_int',
    'text'     => 'catalog_category_entity_text',
    'decimal'  => 'catalog_category_entity_decimal',
    'datetime' => 'catalog_category_entity_datetime'
];

// 以 category_id 為索引，暫存每個分類的「attribute_code => value」
$categoryCustomValues = [];

// 根據查到的每個自訂屬性，逐一去對應的 backend table 撈資料
foreach ($customAttrList as $attr) {
    $attrId   = $attr['attribute_id'];
    $attrCode = $attr['attribute_code'];
    $backend  = $attr['backend_type'];

    // 若不在後端表對應清單中，略過
    if (!isset($backendTableMap[$backend])) {
        continue;
    }
    $tableName = $backendTableMap[$backend];

    // 只取 store_id = 0 (預設層級) 的數值，若需多商店可自行擴充
    $sqlValue = "
        SELECT entity_id AS category_id, value
        FROM {$tableName}
        WHERE attribute_id = :attrId
          AND store_id = 0
    ";
    $stmtVal = $pdoM1->prepare($sqlValue);
    $stmtVal->execute([':attrId' => $attrId]);
    while ($rowVal = $stmtVal->fetch(PDO::FETCH_ASSOC)) {
        $cId = (int)$rowVal['category_id'];
        $val = $rowVal['value'];

        if (!isset($categoryCustomValues[$cId])) {
            $categoryCustomValues[$cId] = [];
        }
        $categoryCustomValues[$cId][$attrCode] = $val;
    }
}

// -------------------------------------------------------------------------
// 6. 取得 (m1_category_id => m2_category_id) 對照表
//    依照你前一次的完整遷移結果，這裡已全部補齊
// -------------------------------------------------------------------------
$migrationMap = [
    // key: Magento 1 category_id, value: Magento 2 category_id
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

// -------------------------------------------------------------------------
// 7. 定義更新 Magento 2 分類自訂屬性的函式 (PUT)
// -------------------------------------------------------------------------
function updateMagento2CategoryCustomAttributes($m2CategoryId, $customAttrAssoc, $m2Domain, $restEndpoint, $adminToken) {
    if (empty($customAttrAssoc)) {
        return;
    }
    // 組合要更新的 custom_attributes
    $customAttrsArray = [];
    foreach ($customAttrAssoc as $attrCode => $val) {
        $customAttrsArray[] = [
            "attribute_code" => $attrCode,
            "value"          => $val
        ];
    }

    // 組成 PUT Body
    $payload = [
        "category" => [
            "id"                => $m2CategoryId,
            "custom_attributes" => $customAttrsArray
        ]
    ];

    // 送出前印出 Payload
    $requestUrl = $m2Domain . $restEndpoint . "/categories/" . $m2CategoryId;
    echo "<hr>";
    echo "<h4>即將發送 PUT 請求至: <code>{$requestUrl}</code></h4>";
    echo "<h5>PUT Payload (JSON):</h5>";
    echo "<pre>" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

    // 執行 CURL
    $ch = curl_init($requestUrl);
    $jsonPayload = json_encode($payload);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Content-Length: " . strlen($jsonPayload),
        "Authorization: Bearer " . $adminToken
    ]);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        die("CURL Error (Update Category $m2CategoryId): " . curl_error($ch));
    }
    curl_close($ch);

    // 印出回應內容
    echo "<h5>PUT Response:</h5>";
    echo "<pre>" . htmlspecialchars($result) . "</pre>";
}

// -------------------------------------------------------------------------
// 8. 逐一比對 Magento 1 的分類 ID → 找到其對應的 Magento 2 ID
//    再將自訂屬性更新到 Magento 2
// -------------------------------------------------------------------------
echo "<h2>開始將 Magento 1 的自訂分類屬性同步到 Magento 2</h2>";

foreach ($categoryCustomValues as $m1CatId => $attrData) {
    // 判斷是否在對照表中
    if (!isset($migrationMap[$m1CatId])) {
        // 若找不到，表示該分類可能未遷移或無法對應
        echo "<p style='color:red;'>[M1 ID: {$m1CatId}] 找不到對應的 M2 Category ID，跳過。</p>";
        continue;
    }
    $m2CatId = $migrationMap[$m1CatId];

    // $attrData 形如 [ 'some_custom_code' => 'value', ... ]
    // 這些屬性若尚未在 M2 建立，可能需要先建立後再更新
    echo "<p>→ 更新自訂屬性：M1 Category ID = {$m1CatId} → M2 Category ID = {$m2CatId}</p>";

    updateMagento2CategoryCustomAttributes($m2CatId, $attrData, $m2Domain, $restEndpoint, $m2AdminToken);
}

echo "<h2>自訂屬性同步完成</h2>";
?>
