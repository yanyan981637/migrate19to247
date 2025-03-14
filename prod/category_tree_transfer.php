<?php
/**
 * 此程式從 Magento 1.9 抓取指定分類（MioWORK™ (ID: 80)、MioCARE™ (ID: 81)、MioEYE™ (ID: 141)、MioServ™ (ID: 149)）
 * 及其所有子分類，並依照原有樹狀結構遷移至 Magento 2.4.7，
 * 四個分類的 Magento 2 上層 parent_id 固定設定為 2。
 * 程式中將印出每次建立分類的 payload 與 API 回應內容供除錯使用。
 */

// ----------------- 讀取設定檔 -----------------
$configPath = '../config.json';
if (!file_exists($configPath)) {
    die("找不到配置檔案: {$configPath}");
}
$config = json_decode(file_get_contents($configPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("解析 JSON 配置檔錯誤: " . json_last_error_msg());
}

// ----------------- Magento 1 設定 -----------------
$m1Domain  = rtrim($config['magento_domain'], '/') . '/';
$m1ApiUser = $config['api_user'];
$m1ApiKey  = $config['api_key'];
$m1WsdlUrl = $m1Domain . 'api/soap/?wsdl';

// ----------------- Magento 2 設定 -----------------
$m2Domain     = rtrim($config['magento2_domain'], '/') . '/';
$restEndpoint = $config['rest_endpoint']; // 例如 "/rest/all/V1"
$defaultParentId = 2; // 四個目標分類的上層 parent_id 固定為 2

// ----------------- 取得 Magento 2 Admin Token -----------------
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
$m2AdminToken = getMagento2AdminToken($m2Domain, $restEndpoint, $m1ApiUser, $m1ApiKey);
echo "<h3>Magento 2 Admin Token:</h3><pre>" . htmlspecialchars($m2AdminToken) . "</pre>";

// ----------------- 連線 Magento 1 SOAP -----------------
try {
    $m1Client = new SoapClient($m1WsdlUrl);
    $m1Session = $m1Client->login($m1ApiUser, $m1ApiKey);
} catch (SoapFault $e) {
    die("Magento 1 SOAP Error: " . $e->getMessage());
}

// 取得完整 Magento 1 category tree（從根目錄抓取）
try {
    $m1Tree = $m1Client->call($m1Session, 'catalog_category.tree');
} catch (SoapFault $e) {
    die("取得 Magento 1 Category Tree 錯誤: " . $e->getMessage());
}

// ----------------- 尋找指定目標分類 -----------------
$targetIds = [80, 81, 141, 149];
$foundCategories = [];

/**
 * 遞迴搜尋指定 category_id 的節點，並存入 $found
 *
 * @param array $node
 * @param array $targetIds
 * @param array &$found
 */
function findCategoriesByIds($node, $targetIds, &$found) {
    if (in_array((int)$node['category_id'], $targetIds)) {
        $found[] = $node;
    }
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            findCategoriesByIds($child, $targetIds, $found);
        }
    }
}
findCategoriesByIds($m1Tree, $targetIds, $foundCategories);
if (empty($foundCategories)) {
    die("找不到目標分類");
}

// ----------------- Magento 2 建立分類函式 -----------------
/**
 * 利用 Magento 2 REST API 建立分類
 *
 * @param array $m1Cat Magento 1 的分類資料（需含基本欄位）
 * @param int $parentIdM2 Magento 2 上層分類 ID
 * @param string $m2Domain Magento 2 網域（結尾有 "/"）
 * @param string $restEndpoint Magento 2 REST endpoint (例如 "/rest/all/V1")
 * @param string $adminToken Magento 2 Admin Token
 * @return int|null 新建立的 Magento 2 分類 ID，若失敗則傳回 null
 */
function createMagento2Category($m1Cat, $parentIdM2, $m2Domain, $restEndpoint, $adminToken) {
    // 組合 payload，將 Magento 1 的資料轉換至 Magento 2 所需格式
    $payload = [
        "category" => [
            "parent_id"       => $parentIdM2,
            "name"            => $m1Cat['name'],
            "is_active"       => (bool)$m1Cat['is_active'],
            "position"        => (int)$m1Cat['position'],
            "include_in_menu" => (isset($m1Cat['include_in_menu']) && $m1Cat['include_in_menu'] == '1') ? true : false,
            "custom_attributes" => []
        ]
    ];

    // 若 Magento 1 中存在以下欄位，則加入 custom_attributes
    $attributesMap = [
        "url_key"         => "url_key",
        "description"     => "description",
        "meta_title"      => "meta_title",
        "meta_keywords"   => "meta_keywords",
        "meta_description"=> "meta_description"
    ];
    foreach ($attributesMap as $m1Field => $m2Attr) {
        if (isset($m1Cat[$m1Field]) && $m1Cat[$m1Field] !== "") {
            $payload["category"]["custom_attributes"][] = [
                "attribute_code" => $m2Attr,
                "value"          => $m1Cat[$m1Field]
            ];
        }
    }

    // 除錯用：印出 payload
    echo "<pre>Payload for Category '{$m1Cat['name']}' (Magento 1 ID: {$m1Cat['category_id']}), Parent in M2: $parentIdM2\n";
    echo json_encode($payload, JSON_PRETTY_PRINT);
    echo "</pre>";

    // 呼叫 Magento 2 REST API 建立分類
    $url = $m2Domain . $restEndpoint . "/categories";
    $ch = curl_init($url);
    $jsonPayload = json_encode($payload);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Content-Length: " . strlen($jsonPayload),
        "Authorization: Bearer " . $adminToken
    ]);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        die("CURL Error (Create Category): " . curl_error($ch));
    }
    curl_close($ch);
    // 除錯用：印出 REST API 回應內容
    echo "<pre>Response for Category '{$m1Cat['name']}' (Magento 1 ID: {$m1Cat['category_id']}):\n";
    echo htmlspecialchars($result);
    echo "</pre>";

    $response = json_decode($result, true);
    if (isset($response['id'])) {
        echo "<p>建立分類 '{$m1Cat['name']}' 成功，新 Magento 2 ID：" . $response['id'] . "</p>\n";
        return $response['id'];
    } else {
        echo "<p style='color:red;'>建立分類 '{$m1Cat['name']}' 失敗：$result</p>\n";
        return null;
    }
}

// ----------------- Magento 2 分類遷移函式（遞迴） -----------------
/**
 * 遞迴將 Magento 1 分類樹結構遷移至 Magento 2
 *
 * @param array $m1Cat Magento 1 的分類節點（包含 children）
 * @param int $parentIdM2 Magento 2 上層分類 ID
 * @param string $m2Domain Magento 2 網域
 * @param string $restEndpoint Magento 2 REST endpoint
 * @param string $adminToken Magento 2 Admin Token
 */
function migrateCategoryTree($m1Cat, $parentIdM2, $m2Domain, $restEndpoint, $adminToken) {
    // 呼叫建立分類 API，並取得新分類的 Magento 2 ID
    $newM2Id = createMagento2Category($m1Cat, $parentIdM2, $m2Domain, $restEndpoint, $adminToken);
    if (!$newM2Id) {
        return;
    }
    // 如果有子分類，則遞迴處理
    if (isset($m1Cat['children']) && is_array($m1Cat['children']) && count($m1Cat['children']) > 0) {
        foreach ($m1Cat['children'] as $child) {
            migrateCategoryTree($child, $newM2Id, $m2Domain, $restEndpoint, $adminToken);
        }
    }
}

// ----------------- 執行遷移 -----------------
echo "<h2>開始 Magento 1 至 Magento 2 分類遷移</h2>\n";
foreach ($foundCategories as $m1Category) {
    echo "<hr>";
    echo "<h3>Migrate Category: " . htmlspecialchars($m1Category['name']) . " (Magento 1 ID: " . htmlspecialchars($m1Category['category_id']) . ")</h3>\n";
    // 依題目要求，指定目標分類的 parent_id 固定為 $defaultParentId = 2
    migrateCategoryTree($m1Category, $defaultParentId, $m2Domain, $restEndpoint, $m2AdminToken);
}
echo "<h2>遷移完成</h2>\n";
?>
