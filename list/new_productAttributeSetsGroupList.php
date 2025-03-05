<?php
header("Content-Type: text/html; charset=UTF-8");

//-------------------------------------------------
// 讀取 config.json 檔案
//-------------------------------------------------
$configFile = '../config.json';
if (!file_exists($configFile)) {
    die("找不到 config.json 檔案。");
}
$configData = json_decode(file_get_contents($configFile), true);
if (!$configData) {
    die("無法解析 config.json 檔案。");
}

//-------------------------------------------------
// 取得基本設定
//-------------------------------------------------
$magentoDomain = rtrim($configData['magento2_domain'], '/');
$apiUser       = $configData['api_user'];
$apiKey        = $configData['api_key'];
$restEndpoint  = isset($configData['rest_endpoint']) ? $configData['rest_endpoint'] : '/rest/all/V1';

//-------------------------------------------------
// 取得管理員 Token
//-------------------------------------------------
$tokenUrl = $magentoDomain . $restEndpoint . '/integration/admin/token';
$postData = json_encode([
    "username" => $apiUser,
    "password" => $apiKey
]);

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json",
    "Content-Length: " . strlen($postData)
]);
$response = curl_exec($ch);
if (curl_errno($ch)) {
    die("cURL error (token): " . curl_error($ch));
}
curl_close($ch);
$token = json_decode($response, true);
if (!isset($token) || !is_string($token)) {
    die("取得管理員 Token 失敗。回傳內容：" . htmlspecialchars($response));
}
echo "<p>管理員 Token： " . htmlspecialchars($token) . "</p>";

//-------------------------------------------------
// Part 1: 取得所有 Attribute Set 的內容
//-------------------------------------------------
$attributeSetsEndpoint = $restEndpoint . '/products/attribute-sets/sets/list';
$queryParams = [
    'searchCriteria[currentPage]' => 1,
    'searchCriteria[pageSize]'    => 100,
];
$urlAttributeSets = $magentoDomain . $attributeSetsEndpoint . '?' . http_build_query($queryParams);

$chAttrSets = curl_init($urlAttributeSets);
curl_setopt($chAttrSets, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chAttrSets, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json",
    "Authorization: Bearer " . $token
]);
$attrSetsResponse = curl_exec($chAttrSets);
if (curl_errno($chAttrSets)) {
    die("cURL error (attribute sets): " . curl_error($chAttrSets));
}
curl_close($chAttrSets);
$decodedAttrSets = json_decode($attrSetsResponse, true);

echo "<h3>所有 Attribute Set 的內容：</h3>";
if ($decodedAttrSets === null) {
    echo htmlspecialchars($attrSetsResponse);
} else {
    if (isset($decodedAttrSets['items']) && is_array($decodedAttrSets['items'])) {
        $attributeSets = $decodedAttrSets['items'];
        if (count($attributeSets) > 0) {
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<thead><tr>";
            echo "<th>Attribute Set ID</th>";
            echo "<th>Attribute Set Name</th>";
            echo "</tr></thead><tbody>";
            foreach ($attributeSets as $set) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($set['attribute_set_id']) . "</td>";
                echo "<td>" . htmlspecialchars($set['attribute_set_name']) . "</td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<p>沒有找到 Attribute Set 資料。</p>";
        }
    } else {
        echo "<pre>" . htmlspecialchars(json_encode($decodedAttrSets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
    }
}

//-------------------------------------------------
// Part 2: 依序列出所有 Attribute Group 的列表（依 attribute_set_id 過濾）
//-------------------------------------------------
echo "<h3>各 Attribute Set 的 Attribute Group 列表：</h3>";
if (isset($decodedAttrSets['items']) && is_array($decodedAttrSets['items'])) {
    foreach ($decodedAttrSets['items'] as $set) {
        $setId   = $set['attribute_set_id'];
        $setName = $set['attribute_set_name'];
        echo "<h4>Attribute Set ID: " . htmlspecialchars($setId) . " - " . htmlspecialchars($setName) . "</h4>";
        
        // 呼叫 /products/attribute-sets/groups/list 並過濾 attribute_set_id
        $queryParamsGroups = [
            'searchCriteria[currentPage]' => 1,
            'searchCriteria[pageSize]'    => 50,
            'searchCriteria[filterGroups][0][filters][0][field]' => 'attribute_set_id',
            'searchCriteria[filterGroups][0][filters][0][value]' => $setId,
            'searchCriteria[filterGroups][0][filters][0][conditionType]' => 'eq',
            'searchCriteria[sortOrders][0][field]' => 'attribute_group_id',
            'searchCriteria[sortOrders][0][direction]' => 'ASC'
        ];
        $attributeGroupsEndpoint = $restEndpoint . '/products/attribute-sets/groups/list';
        $urlAttributeGroups = $magentoDomain . $attributeGroupsEndpoint . '?' . http_build_query($queryParamsGroups);
        
        $chAttributeGroups = curl_init($urlAttributeGroups);
        curl_setopt($chAttributeGroups, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chAttributeGroups, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Accept: application/json",
            "Authorization: Bearer " . $token
        ]);
        $attributeGroupsResponse = curl_exec($chAttributeGroups);
        if (curl_errno($chAttributeGroups)) {
            die("cURL error (attribute groups list): " . curl_error($chAttributeGroups));
        }
        curl_close($chAttributeGroups);
        $decodedAttributeGroups = json_decode($attributeGroupsResponse, true);
        
        if ($decodedAttributeGroups === null) {
            echo "<pre>" . htmlspecialchars($attributeGroupsResponse) . "</pre>";
        } else {
            if (isset($decodedAttributeGroups['items']) && is_array($decodedAttributeGroups['items'])) {
                $groups = $decodedAttributeGroups['items'];
                if (count($groups) > 0) {
                    echo "<table border='1' cellpadding='5' cellspacing='0'>";
                    echo "<thead><tr>";
                    $headers = array_keys($groups[0]);
                    foreach ($headers as $header) {
                        echo "<th>" . htmlspecialchars($header) . "</th>";
                    }
                    echo "</tr></thead><tbody>";
                    foreach ($groups as $group) {
                        echo "<tr>";
                        foreach ($headers as $header) {
                            $value = isset($group[$header]) ? $group[$header] : '';
                            if (is_array($value)) {
                                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                            }
                            echo "<td>" . htmlspecialchars($value) . "</td>";
                        }
                        echo "</tr>";
                    }
                    echo "</tbody></table>";
                } else {
                    echo "<p>沒有找到該 Attribute Set 的 Attribute Group 資料。</p>";
                }
            } else {
                echo "<pre>" . htmlspecialchars(json_encode($decodedAttributeGroups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
            }
        }
    }
} else {
    echo "<p>無法取得 Attribute Set 資料，因此無法列出 Attribute Group 列表。</p>";
}
?>
