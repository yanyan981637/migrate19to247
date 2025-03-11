<?php
/**
 * Magento1 → Magento2 Attribute Migration Script
 *
 * Mapping:
 *   Magento1 attribute set id 23 → Magento2 attribute set id 9
 *   Magento1 attribute set id 22 → Magento2 attribute set id 10
 *
 * 流程：
 * 1. 從 Magento2 REST API (/rest/all/V1/integration/admin/token) 取得 token。
 * 2. 從 Magento1 資料庫與 SOAP API 撈取 attribute group 與 attribute 資料，
 *    補足 default_value、apply_to、options、frontend_label（來源使用資料庫的 frontend_label）、
 *    validation_rules、custom_attributes 等欄位。
 * 3. 建立 Magento2 attribute group (POST /products/attribute-sets/groups)；若建立失敗則搜尋取得 group id。
 * 4. 建立 Magento2 attribute (POST /products/attributes)；若建立失敗（錯誤訊息包含 "already exists" 或 "reserved by system"）則以 GET 搜尋取得 attribute，
 *    如果搜尋結果中 is_visible 為 false，則以 GET 回傳的完整 attribute 物件修改 is_visible 為 true（並更新 default_frontend_label），然後以 PUT 更新該屬性。
 * 5. 指派 attribute 至 group (POST /products/attribute-sets/attributes)。
 *
 * 注意：
 *   - frontend_input 直接採用 Magento1 讀取到的 frontend_input，若無值則預設為 "text"（若 attribute code 為 "weight" 也強制設定為 "text"）。
 *   - frontend_labels：從 Magento1 的 eav_attribute 表讀取 frontend_label 欄位，
 *     若有值則轉換成格式 
 *       [ ["store_id" => 0, "label" => <讀取到的值>] ]
 *     若無則傳入空陣列。
 *   - 若 default_frontend_label 為空，則以 attribute_code 的值作為 default_frontend_label，
 *     並將底線轉成空白，且每個單字的首字母大寫。
 *   - options 資料將移除 option_id，並新增 label 欄位，值與 value 相同。
 *
 * 為避免執行時間過長，使用 set_time_limit(0)。
 */

set_time_limit(0);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ----------------------------
// 讀取 config.json 設定檔
// ----------------------------
$configFile = __DIR__ . '/../config.json';
if (!file_exists($configFile)) {
    die("找不到 config.json 檔案");
}
$configData = json_decode(file_get_contents($configFile), true);
if (!$configData) {
    die("config.json 格式錯誤");
}

// Magento1 SOAP API 與 DB 設定
$magentoDomain = trim($configData['magento_domain'] ?? '');
$apiUser       = trim($configData['api_user'] ?? '');
$apiKey        = trim($configData['api_key'] ?? '');
if (empty($magentoDomain) || empty($apiUser) || empty($apiKey)) {
    die("Magento1 API 設定不完整");
}
$dbHost     = trim($configData['db_host'] ?? '');
$dbName     = trim($configData['db_name'] ?? '');
$dbUser     = trim($configData['db_user'] ?? '');
$dbPassword = trim($configData['db_password'] ?? '');
if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
    die("Magento1 資料庫連線設定不完整");
}

// Magento2 REST API 設定
$magento2Domain = rtrim(trim($configData['magento2_domain'] ?? ''), '/');
$restEndpoint   = trim($configData['rest_endpoint'] ?? ''); // 例如 /rest/all/V1
if (empty($magento2Domain) || empty($restEndpoint)) {
    die("Magento2 REST API 設定不完整");
}

// ----------------------------
// 輔助函式：格式化 attribute_code 為前台標籤
// 將底線轉成空白，並將每個單字的第一個字母大寫
// ----------------------------
function formatAttributeCode($code) {
    $words = explode('_', $code);
    $words = array_map('ucfirst', $words);
    return implode(' ', $words);
}

// ----------------------------
// 取得 Magento2 Token
// ----------------------------
function getMagento2Token($domain, $endpoint, $username, $password) {
    $url = $domain . $endpoint . "/integration/admin/token";
    $data = json_encode([
        "username" => $username,
        "password" => $password
    ]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data)
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        die("cURL Error (token): " . $error_msg);
    }
    curl_close($ch);
    $token = json_decode($response, true);
    if (!$token) {
        die("無法取得 Magento2 token: " . $response);
    }
    return $token;
}
$magento2Token = getMagento2Token($magento2Domain, $restEndpoint, $apiUser, $apiKey);

// ----------------------------
// Mapping 設定：來源 attribute set 與目標 attribute set
// ----------------------------
$attributeSetIds = [22, 23];
$attributeSetMapping = [
    23 => 9,
    22 => 10
];

// ----------------------------
// 建立 Magento1 SOAP API 連線 (SOAP V1)
// ----------------------------
$soapUrl = rtrim($magentoDomain, '/') . '/api/soap/?wsdl';
try {
    $client = new SoapClient($soapUrl, ['trace' => 1]);
    $session = $client->login($apiUser, $apiKey);
} catch (Exception $e) {
    die("Magento1 SOAP API Error: " . $e->getMessage());
}

// ----------------------------
// 建立 PDO 連線 (Magento1 資料庫)
// ----------------------------
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Magento1 資料庫連線失敗：" . $e->getMessage());
}

// ----------------------------
// SQL：取得 attribute group 資料（來源）
// ----------------------------
$groupSql = "SELECT * FROM eav_attribute_group WHERE attribute_set_id = :setId ORDER BY sort_order ASC";

// ----------------------------
// SQL：取得 attribute 基本資料（來源）
// ----------------------------
$attrSql = "
SELECT 
    ea.attribute_id,
    ea.attribute_code,
    ea.backend_model,
    ea.backend_type,
    ea.default_value,
    ea.frontend_label AS default_frontend_label,
    ea.frontend_class,
    cea.is_filterable,
    cea.apply_to,
    cea.is_filterable_in_search,
    ea.is_unique,
    0 AS is_used_for_promo_rules,
    ea.is_user_defined,
    cea.is_visible_on_front AS is_visible,
    cea.position,
    ea.source_model,
    ea.frontend_label
FROM eav_attribute AS ea
LEFT JOIN catalog_eav_attribute AS cea ON ea.attribute_id = cea.attribute_id
INNER JOIN eav_entity_attribute AS eea ON eea.attribute_id = ea.attribute_id
WHERE eea.attribute_set_id = :setId AND eea.attribute_group_id = :groupId
";

// ----------------------------
// 定義函式：取得 options 與 frontend_label（來源欄位）
// ----------------------------
function getAttributeOptions(PDO $pdo, $attrId) {
    $stmtOpt = $pdo->prepare("SELECT o.option_id, v.value FROM eav_attribute_option AS o LEFT JOIN eav_attribute_option_value AS v ON o.option_id = v.option_id WHERE o.attribute_id = :attrId ORDER BY o.sort_order ASC");
    $stmtOpt->bindParam(':attrId', $attrId, PDO::PARAM_INT);
    $stmtOpt->execute();
    $options = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($opt) {
        return [ "label" => $opt['value'] ];
    }, $options);
}
function getFrontendLabel(PDO $pdo, $attrId) {
    $stmtLbl = $pdo->prepare("SELECT frontend_label FROM eav_attribute WHERE attribute_id = :attrId");
    $stmtLbl->bindParam(':attrId', $attrId, PDO::PARAM_INT);
    $stmtLbl->execute();
    $result = $stmtLbl->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['frontend_label'] : "";
}

// ----------------------------
// 取得 Magento1 來源資料，存入 $finalResult
// ----------------------------
$finalResult = [];
foreach ($attributeSetIds as $setId) {
    $stmtGroup = $pdo->prepare($groupSql);
    $stmtGroup->bindParam(':setId', $setId, PDO::PARAM_INT);
    $stmtGroup->execute();
    $groups = $stmtGroup->fetchAll(PDO::FETCH_ASSOC);
    if (!$groups) { continue; }
    $finalResult[$setId] = [];
    foreach ($groups as $group) {
        $groupId   = $group['attribute_group_id'];
        $groupName = $group['attribute_group_name'];
        $stmtAttr = $pdo->prepare($attrSql);
        $stmtAttr->bindParam(':setId', $setId, PDO::PARAM_INT);
        $stmtAttr->bindParam(':groupId', $groupId, PDO::PARAM_INT);
        $stmtAttr->execute();
        $attributes = $stmtAttr->fetchAll(PDO::FETCH_ASSOC);
        $groupResult = [];
        foreach ($attributes as $attr) {
            $attrId = $attr['attribute_id'];
            try {
                $apiInfo = $client->call($session, 'product_attribute.info', $attrId);
                if (!is_array($apiInfo)) {
                    $apiInfo = [];
                }
            } catch (Exception $e) {
                $apiInfo = ["api_error" => $e->getMessage()];
            }
            $merged = array_merge($attr, $apiInfo);
            if (isset($merged['additional_fields']) && is_array($merged['additional_fields']) && isset($merged['additional_fields']['is_html_allowed_on_front'])) {
                $merged['is_html_allowed_on_front'] = $merged['additional_fields']['is_html_allowed_on_front'];
                unset($merged['additional_fields']['is_html_allowed_on_front']);
            }
            $merged['options'] = getAttributeOptions($pdo, $attrId);
            $frontendLabel = getFrontendLabel($pdo, $attrId);
            $merged['frontend_labels'] = !empty($frontendLabel) ? [ ["store_id" => 0, "label" => $frontendLabel] ] : [];
            $merged['validation_rules'] = [];
            $merged['custom_attributes'] = [];
            $merged['attribute_set_id'] = $setId;
            $merged['attribute_group_id'] = $groupId;
            $merged['attribute_group_name'] = $groupName;
            $groupResult[] = $merged;
        }
        // 排序：依照 attribute_code 升冪排列
        usort($groupResult, function($a, $b) {
            return strcmp($a['attribute_code'], $b['attribute_code']);
        });
        $finalResult[$setId][$groupId] = [
            'group_name' => $groupName,
            'attributes' => $groupResult
        ];
    }
}

// ----------------------------
// 定義函式：呼叫 Magento2 REST API (使用 cURL)
// ----------------------------
function callMagento2Api($endpoint, $method, $data, $magento2Domain, $restEndpoint, $token) {
    $url = $magento2Domain . $restEndpoint . $endpoint;
    if (strtoupper($method) === "GET" && !empty($data)) {
        $url .= '?' . http_build_query($data);
        $jsonData = '';
    } else {
        $jsonData = json_encode($data);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if (!empty($jsonData)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'Content-Length: ' . strlen($jsonData)
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        die("cURL Error: " . $error_msg);
    }
    curl_close($ch);
    return json_decode($response, true);
}

// ----------------------------
// 正式執行：批次遷移
// ----------------------------
foreach ($finalResult as $sourceSetId => $groups) {
    $targetSetId = $attributeSetMapping[$sourceSetId] ?? "未知";
    echo "<h2>Attribute Set Mapping: Magento1 ID " . htmlspecialchars((string)$sourceSetId) . " → Magento2 ID " . htmlspecialchars((string)$targetSetId) . "</h2>";
    
    foreach ($groups as $groupId => $groupData) {
        $groupName = $groupData['group_name'] ?? '';
        $groupCode = strtolower(trim(preg_replace('/\s+/', '-', $groupName)));
        
        // 建立 Magento2 attribute group
        $groupPayload = [
            "group" => [
                "attribute_group_name" => $groupName,
                "attribute_set_id" => (int)$targetSetId,
                "extension_attributes" => [
                    "attribute_group_code" => $groupCode,
                    "sort_order" => "10"
                ]
            ]
        ];
        $groupResponse = callMagento2Api("/products/attribute-sets/groups", "POST", $groupPayload, $magento2Domain, $restEndpoint, $magento2Token);
        echo "<pre>Group API Response: " . print_r($groupResponse, true) . "</pre>";
        if (isset($groupResponse['message']) && (strpos($groupResponse['message'], "already exists") !== false || strpos($groupResponse['message'], "can't be saved") !== false)) {
            echo "Group {$groupName} 建立失敗，進行搜尋...<br>";
            $searchQuery = [
                "searchCriteria" => [
                    "currentPage" => 1,
                    "pageSize" => 1000,
                    "filterGroups" => [
                        [
                            "filters" => [
                                [
                                    "field" => "attribute_set_id",
                                    "value" => $targetSetId,
                                    "conditionType" => "eq"
                                ]
                            ]
                        ],
                        [
                            "filters" => [
                                [
                                    "field" => "attribute_group_name",
                                    "value" => $groupName,
                                    "conditionType" => "eq"
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            $searchResponse = callMagento2Api("/products/attribute-sets/groups/list", "GET", $searchQuery, $magento2Domain, $restEndpoint, $magento2Token);
            echo "<pre>Group Search API Response: " . print_r($searchResponse, true) . "</pre>";
            $foundGroupId = null;
            if (isset($searchResponse['items']) && is_array($searchResponse['items'])) {
                foreach ($searchResponse['items'] as $item) {
                    if (strtolower($item['attribute_group_name']) === strtolower($groupName)) {
                        $foundGroupId = $item['attribute_group_id'];
                        break;
                    }
                }
            }
            if (empty($foundGroupId)) {
                echo "找不到已存在的 group {$groupName} 的 id，跳過此群組。<br>";
                continue;
            } else {
                $targetGroupId = $foundGroupId;
                echo "找到已存在的 group {$groupName}，Magento2 group id：{$targetGroupId}<br>";
            }
        } elseif (!isset($groupResponse['attribute_group_id'])) {
            echo "建立 group {$groupName} 失敗，跳過此群組。<br>";
            continue;
        } else {
            $targetGroupId = $groupResponse['attribute_group_id'];
            echo "建立 group {$groupName} 成功，Magento2 group id：{$targetGroupId}<br>";
        }
        
        // 處理該 group 下的每個 attribute
        foreach ($groupData['attributes'] as $attribute) {
            $frontendInput = (isset($attribute['frontend_input']) && !empty($attribute['frontend_input'])) ? $attribute['frontend_input'] : "text";
            if (strtolower($attribute['attribute_code']) === "weight") {
                $frontendInput = "text";
            }
            $frontendLabelValue = getFrontendLabel($pdo, $attribute['attribute_id']);
            $frontendLabelsArray = !empty($frontendLabelValue) ? [ ["store_id" => 0, "label" => $frontendLabelValue] ] : [];
            $defaultFrontendLabel = !empty($attribute['default_frontend_label']) ? $attribute['default_frontend_label'] : formatAttributeCode($attribute['attribute_code']);
            
            $attributePayload = [
                "attribute" => [
                    "extension_attributes" => (isset($attribute['extension_attributes']) && $attribute['extension_attributes'] !== '') ? $attribute['extension_attributes'] : new stdClass(),
                    "is_wysiwyg_enabled" => isset($attribute['is_wysiwyg_enabled']) ? $attribute['is_wysiwyg_enabled'] : true,
                    "is_html_allowed_on_front" => isset($attribute['is_html_allowed_on_front']) ? $attribute['is_html_allowed_on_front'] : true,
                    "used_for_sort_by" => isset($attribute['used_for_sort_by']) ? $attribute['used_for_sort_by'] : true,
                    "is_filterable" => isset($attribute['is_filterable']) ? $attribute['is_filterable'] : true,
                    "is_filterable_in_search" => isset($attribute['is_filterable_in_search']) ? $attribute['is_filterable_in_search'] : true,
                    "is_used_in_grid" => isset($attribute['is_used_in_grid']) ? $attribute['is_used_in_grid'] : true,
                    "is_visible_in_grid" => isset($attribute['is_visible_in_grid']) ? $attribute['is_visible_in_grid'] : true,
                    "is_filterable_in_grid" => isset($attribute['is_filterable_in_grid']) ? $attribute['is_filterable_in_grid'] : true,
                    "position" => isset($attribute['position']) ? (int)$attribute['position'] : 0,
                    "apply_to" => isset($attribute['apply_to']) ? $attribute['apply_to'] : [],
                    "is_searchable" => isset($attribute['is_searchable']) ? $attribute['is_searchable'] : "",
                    "is_visible_in_advanced_search" => isset($attribute['is_visible_in_advanced_search']) ? $attribute['is_visible_in_advanced_search'] : "",
                    "is_comparable" => isset($attribute['is_comparable']) ? $attribute['is_comparable'] : "",
                    "is_used_for_promo_rules" => isset($attribute['is_used_for_promo_rules']) ? $attribute['is_used_for_promo_rules'] : "",
                    "is_visible_on_front" => isset($attribute['is_visible']) ? $attribute['is_visible'] : "",
                    "used_in_product_listing" => isset($attribute['used_in_product_listing']) ? $attribute['used_in_product_listing'] : "",
                    "is_visible" => true,
                    "scope" => isset($attribute['scope']) ? $attribute['scope'] : "",
                    "attribute_code" => isset($attribute['attribute_code']) ? $attribute['attribute_code'] : "",
                    "frontend_input" => $frontendInput,
                    "entity_type_id" => "4",
                    "is_required" => isset($attribute['is_required']) ? $attribute['is_required'] : true,
                    "options" => isset($attribute['options']) ? $attribute['options'] : [],
                    "is_user_defined" => isset($attribute['is_user_defined']) ? $attribute['is_user_defined'] : true,
                    "default_frontend_label" => $defaultFrontendLabel,
                    "frontend_labels" => $frontendLabelsArray,
                    "note" => isset($attribute['note']) ? $attribute['note'] : "",
                    "backend_type" => isset($attribute['backend_type']) ? $attribute['backend_type'] : "",
                    "backend_model" => isset($attribute['backend_model']) ? $attribute['backend_model'] : "",
                    "source_model" => isset($attribute['source_model']) ? $attribute['source_model'] : "",
                    "default_value" => isset($attribute['default_value']) ? $attribute['default_value'] : "",
                    "is_unique" => isset($attribute['is_unique']) ? $attribute['is_unique'] : "",
                    "frontend_class" => isset($attribute['frontend_class']) ? $attribute['frontend_class'] : "",
                    "validation_rules" => isset($attribute['validation_rules']) ? $attribute['validation_rules'] : [],
                    "custom_attributes" => isset($attribute['custom_attributes']) ? $attribute['custom_attributes'] : []
                ]
            ];
            
            echo "<pre>Attribute Payload: " . print_r($attributePayload, true) . "</pre>";
            
            $attrResponse = callMagento2Api("/products/attributes", "POST", $attributePayload, $magento2Domain, $restEndpoint, $magento2Token);
            echo "<pre>Attribute API Response: " . print_r($attrResponse, true) . "</pre>";
            
            if (isset($attrResponse['message']) && (strpos($attrResponse['message'], "already exists") !== false || strpos($attrResponse['message'], "reserved by system") !== false)) {
                $attributeCode = $attribute['attribute_code'] ?? '';
                echo "Attribute {$attributeCode} 已存在或被系統保留，進行搜尋...<br>";
                $getResponse = callMagento2Api("/products/attributes/" . urlencode($attributeCode), "GET", [], $magento2Domain, $restEndpoint, $magento2Token);
                echo "<pre>Attribute GET Response: " . print_r($getResponse, true) . "</pre>";
                if (isset($getResponse['attribute_id'])) {
                    $targetAttrId = $getResponse['attribute_id'];
                    echo "找到 attribute {$attributeCode}，Magento2 attribute id：{$targetAttrId}<br>";
                    if (isset($getResponse['is_visible']) && !$getResponse['is_visible']) {
                        // 使用 GET 回傳的完整屬性物件進行更新
                        $existingAttribute = $getResponse;
                        $existingAttribute['is_visible'] = true;
                        $existingAttribute['default_frontend_label'] = $defaultFrontendLabel;
                        // 移除 attribute_code 避免衝突
                        unset($existingAttribute['attribute_code']);
                        $updatePayload = ["attribute" => $existingAttribute];
                        echo "<pre>Attribute Update Payload: " . print_r($updatePayload, true) . "</pre>";
                        $updateResponse = callMagento2Api("/products/attributes/" . urlencode($attributeCode), "PUT", $updatePayload, $magento2Domain, $restEndpoint, $magento2Token);
                        echo "<pre>Attribute Update API Response: " . print_r($updateResponse, true) . "</pre>";
                        if (isset($updateResponse['attribute_id'])) {
                            echo "更新 attribute {$attributeCode} is_visible 為 true 成功。<br>";
                        } else {
                            echo "更新 attribute {$attributeCode} is_visible 為 true 失敗。<br>";
                        }
                    }
                } else {
                    echo "搜尋 attribute {$attributeCode} 失敗，跳過此 attribute。<br>";
                    continue;
                }
            } elseif (!isset($attrResponse['attribute_id'])) {
                echo "建立 attribute (code: " . htmlspecialchars($attribute['attribute_code']) . ") 失敗，跳過此 attribute。<br>";
                continue;
            } else {
                $targetAttrId = $attrResponse['attribute_id'];
            }
            echo "建立 attribute " . htmlspecialchars($attribute['attribute_code']) . " 成功，Magento2 attribute id：{$targetAttrId}<br>";
            
            $assignPayload = [
                "attributeSetId" => (int)$targetSetId,
                "attributeGroupId" => (int)$targetGroupId,
                "attributeCode" => isset($attribute['attribute_code']) ? $attribute['attribute_code'] : "",
                "sortOrder" => isset($attribute['position']) ? (int)$attribute['position'] : 10
            ];
            echo "<pre>Attribute Assignment Request Payload: " . print_r($assignPayload, true) . "</pre>";
            $assignResponse = callMagento2Api("/products/attribute-sets/attributes", "POST", $assignPayload, $magento2Domain, $restEndpoint, $magento2Token);
            echo "<pre>Attribute Assignment API Response: " . print_r($assignResponse, true) . "</pre>";
            if (is_numeric($assignResponse)) {
                echo "指派 attribute " . htmlspecialchars($attribute['attribute_code']) . " 到 group {$groupName} 成功，回傳 ID：{$assignResponse}<br>";
            } elseif (is_array($assignResponse) && isset($assignResponse['attribute_id'])) {
                echo "指派 attribute " . htmlspecialchars($attribute['attribute_code']) . " 到 group {$groupName} 成功，回傳 ID：" . $assignResponse['attribute_id'] . "<br>";
            } else {
                echo "指派 attribute " . htmlspecialchars($attribute['attribute_code']) . " 到 group {$groupName} 失敗。<br>";
            }
        }
        echo "<hr>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>正式執行的 Magento1 → Magento2 Attribute Migration</title>
</head>
<body>
    <h1>Attribute Migration 完成</h1>
</body>
</html>
