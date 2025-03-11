<?php
/**
 * Magento1 → Magento2 Attribute Migration (正式執行版)
 *
 * Mapping:
 *   Magento1 attribute set id 23  → Magento2 attribute set id 9
 *   Magento1 attribute set id 22  → Magento2 attribute set id 10
 *
 * 此程式會：
 * 1. 透過 Magento2 REST API 取得管理員 token（POST /rest/all/V1/integration/admin/token）
 * 2. 從 Magento1 資料庫撈取 attribute set、attribute group 及 attribute 資料（並透過 SOAP API 補足部分欄位）
 * 3. 整理出 Magento2 REST API 的 Request Payload（建立 group、attribute 與 attribute 指派）
 */

// ----------------------------
// 開啟錯誤訊息顯示（除錯用）
// ----------------------------
ini_set('display_errors', 1);
error_reporting(E_ALL);

// -------------------------------------------------
// 讀取 config.json 設定檔（包含 Magento1 DB 與 Magento2 REST API 設定）
// -------------------------------------------------
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
$apiUser1      = trim($configData['api_user'] ?? ''); // 此處假設 Magento1 API 使用者與 Magento2 相同，如有不同請分開設定
$apiKey1       = trim($configData['api_key'] ?? '');

$dbHost     = trim($configData['db_host'] ?? '');
$dbName     = trim($configData['db_name'] ?? '');
$dbUser     = trim($configData['db_user'] ?? '');
$dbPassword = trim($configData['db_password'] ?? '');
if (empty($magentoDomain) || empty($apiUser1) || empty($apiKey1)) {
    die("Magento1 API 設定不完整");
}
if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
    die("Magento1 資料庫連線設定不完整");
}

// Magento2 REST API 設定
$magento2Domain = rtrim(trim($configData['magento2_domain'] ?? ''), '/');
$restEndpoint   = trim($configData['rest_endpoint'] ?? ''); // 應為 /rest/all/V1
$apiUser2       = trim($configData['api_user'] ?? ''); // 使用同一組 api_user 與 api_key
$apiKey2        = trim($configData['api_key'] ?? '');
if (empty($magento2Domain) || empty($restEndpoint) || empty($apiUser2) || empty($apiKey2)) {
    die("Magento2 REST API 設定不完整");
}

// -------------------------------------------------
// 取得 Magento2 Token (呼叫 /rest/all/V1/integration/admin/token)
// -------------------------------------------------
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
    if(curl_errno($ch)){
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

$magento2Token = getMagento2Token($magento2Domain, $restEndpoint, $apiUser2, $apiKey2);

// -------------------------------------------------
// 固定參數與 Mapping：來源 attribute set及目標 attribute set
// -------------------------------------------------
$attributeSetIds = [22, 23];
$attributeSetMapping = [
    23 => 9,
    22 => 10
];

// -------------------------------------------------
// 建立 Magento1 SOAP API 連線 (使用 SOAP V1)
// -------------------------------------------------
$soapUrl = rtrim($magentoDomain, '/') . '/api/soap/?wsdl';
try {
    $client  = new SoapClient($soapUrl, ['trace' => 1]);
    $session = $client->login($apiUser1, $apiKey1);
} catch (Exception $e) {
    die("Magento1 SOAP API Error: " . $e->getMessage());
}

// -------------------------------------------------
// 建立 PDO 連線 (Magento1 資料庫)
// -------------------------------------------------
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Magento1 資料庫連線失敗：" . $e->getMessage());
}

// -------------------------------------------------
// SQL：取得 attribute group 資料（來源）
// -------------------------------------------------
$groupSql = "SELECT * FROM eav_attribute_group WHERE attribute_set_id = :setId ORDER BY sort_order ASC";

// -------------------------------------------------
// SQL：取得 attribute 基本資料（來源），包含 default_value 與 apply_to
// -------------------------------------------------
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
    ea.source_model
FROM eav_attribute AS ea
LEFT JOIN catalog_eav_attribute AS cea ON ea.attribute_id = cea.attribute_id
INNER JOIN eav_entity_attribute AS eea ON eea.attribute_id = ea.attribute_id
WHERE eea.attribute_set_id = :setId AND eea.attribute_group_id = :groupId
";

// -------------------------------------------------
// 定義函式：取得 options 與 frontend_labels
// -------------------------------------------------
function getAttributeOptions(PDO $pdo, $attrId) {
    $stmtOpt = $pdo->prepare("SELECT o.option_id, v.value FROM eav_attribute_option AS o LEFT JOIN eav_attribute_option_value AS v ON o.option_id = v.option_id WHERE o.attribute_id = :attrId ORDER BY o.sort_order ASC");
    $stmtOpt->bindParam(':attrId', $attrId, PDO::PARAM_INT);
    $stmtOpt->execute();
    return $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
}

function getFrontendLabels(PDO $pdo, $attrId) {
    $stmtLbl = $pdo->prepare("SELECT store_id, value AS label FROM eav_attribute_label WHERE attribute_id = :attrId");
    $stmtLbl->bindParam(':attrId', $attrId, PDO::PARAM_INT);
    $stmtLbl->execute();
    return $stmtLbl->fetchAll(PDO::FETCH_ASSOC);
}

// -------------------------------------------------
// 取得來源資料，存入 $finalResult
// 結構：$finalResult[source_set_id][group_id] = ['group_name'=>..., 'attributes'=>[...] ]
// -------------------------------------------------
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
                if (!is_array($apiInfo)) { $apiInfo = []; }
            } catch (Exception $e) {
                $apiInfo = ["api_error" => $e->getMessage()];
            }
            $merged = array_merge($attr, $apiInfo);
            if (isset($merged['additional_fields']) && is_array($merged['additional_fields']) && isset($merged['additional_fields']['is_html_allowed_on_front'])) {
                $merged['is_html_allowed_on_front'] = $merged['additional_fields']['is_html_allowed_on_front'];
                unset($merged['additional_fields']['is_html_allowed_on_front']);
            }
            $merged['options'] = getAttributeOptions($pdo, $attrId);
            $merged['frontend_labels'] = getFrontendLabels($pdo, $attrId);
            $merged['validation_rules'] = [];
            $merged['custom_attributes'] = [];
            $merged['attribute_set_id'] = $setId;
            $merged['attribute_group_id'] = $groupId;
            $merged['attribute_group_name'] = $groupName;
            $groupResult[] = $merged;
        }
        $finalResult[$setId][$groupId] = [
            'group_name' => $groupName,
            'attributes' => $groupResult
        ];
    }
}

// -------------------------------------------------
// 定義函式：呼叫 Magento2 REST API (使用 cURL)
// -------------------------------------------------
function callMagento2Api($endpoint, $method, $data, $magento2Domain, $restEndpoint, $token) {
    $url = $magento2Domain . $restEndpoint . $endpoint;
    $ch = curl_init($url);
    $jsonData = json_encode($data);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'Content-Length: ' . strlen($jsonData)
    ]);
    $response = curl_exec($ch);
    if(curl_errno($ch)){
        $error_msg = curl_error($ch);
        curl_close($ch);
        die("cURL Error: " . $error_msg);
    }
    curl_close($ch);
    return json_decode($response, true);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Magento1 → Magento2 Attribute Migration - 正式執行</title>
    <style>
        body { font-family: Arial, sans-serif; }
        h1, h2, h3, h4 { text-align: center; }
        .attribute-table { border-collapse: collapse; width: 60%; margin: 20px auto; }
        .attribute-table th, .attribute-table td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
        .attribute-table th { background-color: #f2f2f2; text-align: left; width: 30%; }
        .attribute-table td { width: 70%; }
        .group-section { margin-bottom: 40px; }
        pre { background: #eee; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Magento1 Attribute Groups & Attributes</h1>
    <!-- 顯示來源資料 -->
    <?php foreach ($finalResult as $setId => $groups): ?>
        <h2>Attribute Set ID: <?php echo htmlspecialchars((string)$setId ?? ''); ?></h2>
        <?php if (empty($groups)): ?>
            <p style="text-align:center;">此屬性集無任何 Attribute Group</p>
        <?php else: ?>
            <?php foreach ($groups as $groupId => $groupData): ?>
                <div class="group-section">
                    <h3>Attribute Group: <?php echo htmlspecialchars($groupData['group_name'] ?? ''); ?> (Group ID: <?php echo htmlspecialchars((string)$groupId ?? ''); ?>)</h3>
                    <?php if (empty($groupData['attributes'])): ?>
                        <p style="text-align:center;">此群組無任何 Attribute</p>
                    <?php else: ?>
                        <?php foreach ($groupData['attributes'] as $attribute): 
                            $allKeys = array_keys($attribute);
                            sort($allKeys);
                        ?>
                            <table class="attribute-table">
                                <thead>
                                    <tr>
                                        <th colspan="2">Attribute ID: <?php echo htmlspecialchars((string)$attribute['attribute_id'] ?? ''); ?> / Code: <?php echo htmlspecialchars($attribute['attribute_code'] ?? ''); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allKeys as $key): ?>
                                        <tr>
                                            <th><?php echo htmlspecialchars((string)$key ?? ''); ?></th>
                                            <td>
                                                <?php 
                                                $value = $attribute[$key] ?? '';
                                                if (is_array($value)) {
                                                    echo '<pre>' . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                                                } else {
                                                    echo htmlspecialchars((string)$value);
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

    <hr>
    <h1>整理後的 Request 資料</h1>
    <?php
    // 整理 Request 資料：依據每個來源 attribute set與 group，產生 Magento2 的 Request Payload
    foreach ($finalResult as $sourceSetId => $groups):
        $targetSetId = $attributeSetMapping[$sourceSetId] ?? "未知";
    ?>
        <h2>Attribute Set Mapping: Magento1 ID <?php echo htmlspecialchars((string)$sourceSetId); ?> → Magento2 ID <?php echo htmlspecialchars((string)$targetSetId); ?></h2>
        <?php foreach ($groups as $groupId => $groupData):
            $groupName = $groupData['group_name'] ?? '';
            $groupCode = strtolower(trim(preg_replace('/\s+/', '-', $groupName)));
            $groupPayload = [
                "group" => [
                    "attribute_group_name" => $groupName,
                    "attribute_set_id" => $targetSetId,
                    "extension_attributes" => [
                        "attribute_group_code" => $groupCode,
                        "sort_order" => "0"
                    ]
                ]
            ];
        ?>
            <h3>Attribute Group: <?php echo htmlspecialchars($groupName); ?> (來源 Group ID: <?php echo htmlspecialchars((string)$groupId); ?>)</h3>
            <strong>Group Request Payload:</strong>
            <pre><?php echo htmlspecialchars(json_encode($groupPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?? ''); ?></pre>
            <?php foreach ($groupData['attributes'] as $attribute):
                $attributePayload = [
                    "attribute" => [
                        "extension_attributes" => isset($attribute['extension_attributes']) && $attribute['extension_attributes'] !== '' ? $attribute['extension_attributes'] : new stdClass(),
                        "is_wysiwyg_enabled" => isset($attribute['is_wysiwyg_enabled']) ? (bool)$attribute['is_wysiwyg_enabled'] : true,
                        "is_html_allowed_on_front" => isset($attribute['is_html_allowed_on_front']) ? (bool)$attribute['is_html_allowed_on_front'] : true,
                        "used_for_sort_by" => isset($attribute['used_for_sort_by']) ? $attribute['used_for_sort_by'] : true,
                        "is_filterable" => isset($attribute['is_filterable']) ? (bool)$attribute['is_filterable'] : true,
                        "is_filterable_in_search" => isset($attribute['is_filterable_in_search']) ? (bool)$attribute['is_filterable_in_search'] : true,
                        "is_used_in_grid" => isset($attribute['is_used_in_grid']) ? (bool)$attribute['is_used_in_grid'] : true,
                        "is_visible_in_grid" => isset($attribute['is_visible_in_grid']) ? (bool)$attribute['is_visible_in_grid'] : true,
                        "is_filterable_in_grid" => isset($attribute['is_filterable_in_grid']) ? (bool)$attribute['is_filterable_in_grid'] : true,
                        "position" => isset($attribute['position']) ? (int)$attribute['position'] : 0,
                        "apply_to" => isset($attribute['apply_to']) ? $attribute['apply_to'] : [],
                        "is_searchable" => isset($attribute['is_searchable']) ? $attribute['is_searchable'] : "",
                        "is_visible_in_advanced_search" => isset($attribute['is_visible_in_advanced_search']) ? $attribute['is_visible_in_advanced_search'] : "",
                        "is_comparable" => isset($attribute['is_comparable']) ? $attribute['is_comparable'] : "",
                        "is_used_for_promo_rules" => isset($attribute['is_used_for_promo_rules']) ? $attribute['is_used_for_promo_rules'] : "",
                        "is_visible_on_front" => isset($attribute['is_visible']) ? $attribute['is_visible'] : "",
                        "used_in_product_listing" => isset($attribute['used_in_product_listing']) ? $attribute['used_in_product_listing'] : "",
                        "is_visible" => isset($attribute['is_visible']) ? (bool)$attribute['is_visible'] : true,
                        "scope" => isset($attribute['scope']) ? $attribute['scope'] : "",
                        "attribute_code" => isset($attribute['attribute_code']) ? $attribute['attribute_code'] : "",
                        "frontend_input" => isset($attribute['frontend_input']) ? $attribute['frontend_input'] : "",
                        "entity_type_id" => "4",
                        "is_required" => isset($attribute['is_required']) ? (bool)$attribute['is_required'] : true,
                        "options" => isset($attribute['options']) ? $attribute['options'] : [],
                        "is_user_defined" => isset($attribute['is_user_defined']) ? (bool)$attribute['is_user_defined'] : true,
                        "default_frontend_label" => isset($attribute['default_frontend_label']) ? $attribute['default_frontend_label'] : "",
                        "frontend_labels" => isset($attribute['frontend_labels']) ? $attribute['frontend_labels'] : [],
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
                $assignPayload = [
                    "attributeSetId" => $targetSetId,
                    "attributeGroupId" => "(group API 回傳的 attribute_group_id)",
                    "attributeCode" => isset($attribute['attribute_code']) ? $attribute['attribute_code'] : "",
                    "sortOrder" => isset($attribute['position']) ? (int)$attribute['position'] : 0
                ];
            ?>
                <h4>Attribute: <?php echo htmlspecialchars($attribute['attribute_code'] ?? ''); ?></h4>
                <strong>Attribute Request Payload:</strong>
                <pre><?php echo htmlspecialchars(json_encode($attributePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?? ''); ?></pre>
                <strong>Attribute Assignment Payload:</strong>
                <pre><?php echo htmlspecialchars(json_encode($assignPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?? ''); ?></pre>
            <?php endforeach; ?>
            <hr>
        <?php endforeach; ?>
    <?php endforeach; ?>
</body>
</html>
