<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// -------------------------------------------------------------------------
// 2. Magento 1.9 SOAP API 參數設定與連線
// -------------------------------------------------------------------------
$magentoDomain = rtrim($config['magento_domain'], '/') . '/';
$apiUser       = $config['api_user'];
$apiKey        = $config['api_key'];
$apiKey2       = $config['api_key2'];
$soapUrl       = $magentoDomain . 'api/soap/?wsdl';

try {
    $client = new SoapClient($soapUrl);
    $session = $client->login($apiUser, $apiKey);
} catch (Exception $e) {
    die("Magento 1 SOAP 連線或登入失敗，錯誤訊息: " . $e->getMessage());
}

// -------------------------------------------------------------------------
// 3. 取得 Magento 1 之 attribute set (ID: 22 與 23) 屬性列表
//    使用 product_attribute.list 方法 (SOAP V1)
// -------------------------------------------------------------------------
$m1AttributeSetIds = [22, 23];
$m1Results = [];
$totalM1Count = 0;

foreach ($m1AttributeSetIds as $setId) {
    try {
        // 使用 product_attribute.list 取得屬性清單
        $result = $client->call($session, 'product_attribute.list', array($setId));
        $attributes = array();
        if (is_array($result)) {
            foreach ($result as $attr) {
                // 根據文件，屬性代碼存放在 code 欄位
                if (isset($attr['code'])) {
                    $attributes[] = $attr['code'];
                } else {
                    $attributes[] = isset($attr['attribute_id']) ? $attr['attribute_id'] : '';
                }
            }
        }
        // 移除重複項目
        $attributes = array_unique($attributes);
        // 自然排序
        sort($attributes, SORT_NATURAL | SORT_FLAG_CASE);
        $count = count($attributes);
        $m1Results[$setId] = [
            'attributes' => $attributes,
            'count'      => $count,
            'raw_info'   => $result
        ];
        $totalM1Count += $count;
    } catch (Exception $e) {
        $m1Results[$setId] = [
            'attributes' => [],
            'count'      => 0,
            'error'      => $e->getMessage()
        ];
    }
}

// -------------------------------------------------------------------------
// 4. Magento 2 REST API 參數設定與取得 Admin Token
// -------------------------------------------------------------------------
$m2Domain     = rtrim($config['magento2_domain'], '/') . '/';
$restEndpoint = $config['rest_endpoint'];  // 例如 "/rest/all/V1"

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
$m2AdminToken = getMagento2AdminToken($m2Domain, $restEndpoint, $apiUser, $apiKey2);

// -------------------------------------------------------------------------
// 5. 取得 Magento 2 之 attribute set (ID: 9 與 10) 屬性列表（REST API）
// -------------------------------------------------------------------------
$m2AttributeSetIds = [9, 10];
$m2Results = [];
$totalM2Count = 0;

foreach ($m2AttributeSetIds as $setId) {
    $url = $m2Domain . $restEndpoint . "/products/attribute-sets/{$setId}/attributes";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $m2AdminToken
    ]);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        $m2Results[$setId] = [
            'attributes' => [],
            'count'      => 0,
            'error'      => curl_error($ch)
        ];
        curl_close($ch);
        continue;
    }
    curl_close($ch);
    $data = json_decode($result, true);
    if (!is_array($data)) {
        $data = [];
    }
    $attributes = array();
    foreach ($data as $attr) {
        if (isset($attr['attribute_code'])) {
            $attributes[] = $attr['attribute_code'];
        } else {
            $attributes[] = isset($attr['attribute_id']) ? $attr['attribute_id'] : '';
        }
    }
    $attributes = array_unique($attributes);
    sort($attributes, SORT_NATURAL | SORT_FLAG_CASE);
    $count = count($attributes);
    $m2Results[$setId] = [
        'attributes' => $attributes,
        'count'      => $count,
    ];
    $totalM2Count += $count;
}

// -------------------------------------------------------------------------
// 6. 比較：將 M1 22 對應 M2 10，M1 23 對應 M2 9
//    計算哪些屬性在 M1 有但 M2 沒有，以及哪些在 M2 有但 M1 沒有，並自然排序
// -------------------------------------------------------------------------
$mapping = [
    22 => 10,
    23 => 9,
];
$comparisonResults = [];

foreach ($mapping as $m1SetId => $m2SetId) {
    $m1List = isset($m1Results[$m1SetId]['attributes']) ? $m1Results[$m1SetId]['attributes'] : [];
    $m2List = isset($m2Results[$m2SetId]['attributes']) ? $m2Results[$m2SetId]['attributes'] : [];
    // 差集：在 M1 但不在 M2 的屬性
    $diffM1 = array_diff($m1List, $m2List);
    sort($diffM1, SORT_NATURAL | SORT_FLAG_CASE);
    // 差集：在 M2 但不在 M1 的屬性
    $diffM2 = array_diff($m2List, $m1List);
    sort($diffM2, SORT_NATURAL | SORT_FLAG_CASE);
    
    $comparisonResults[] = [
        'm1SetId'       => $m1SetId,
        'm1Count'       => count($m1List),
        'm1Attributes'  => $m1List,
        'm2SetId'       => $m2SetId,
        'm2Count'       => count($m2List),
        'm2Attributes'  => $m2List,
        'difference_m1' => $diffM1,  // M1 有但 M2 沒有
        'difference_m2' => $diffM2,  // M2 有但 M1 沒有
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>Attribute Set 比較</title>
    <style>
        table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
        th, td { border: 1px solid #333; padding: 8px; text-align: left; vertical-align: top; }
        th { background-color: #eee; }
        pre { background: #f9f9f9; padding: 10px; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <h2>Magento 1.9 SOAP API (Attribute Set ID: 22 與 23)</h2>
    <table>
        <tr>
            <th>Attribute Set ID</th>
            <th>Total Count</th>
            <th>Attribute List (自然排序)</th>
        </tr>
        <?php foreach ($m1Results as $setId => $result): ?>
        <tr>
            <td><?php echo htmlspecialchars($setId); ?></td>
            <td><?php echo htmlspecialchars($result['count']); ?></td>
            <td><?php echo htmlspecialchars(implode(", ", $result['attributes'])); ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <th>總計</th>
            <th><?php echo $totalM1Count; ?></th>
            <th></th>
        </tr>
    </table>

    <h2>Magento 2.4.7 REST API (Attribute Set ID: 9 與 10)</h2>
    <table>
        <tr>
            <th>Attribute Set ID</th>
            <th>Total Count</th>
            <th>Attribute List (自然排序)</th>
        </tr>
        <?php foreach ($m2Results as $setId => $result): ?>
        <tr>
            <td><?php echo htmlspecialchars($setId); ?></td>
            <td><?php echo htmlspecialchars($result['count']); ?></td>
            <td><?php echo htmlspecialchars(implode(", ", $result['attributes'])); ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <th>總計</th>
            <th><?php echo $totalM2Count; ?></th>
            <th></th>
        </tr>
    </table>

    <h2>Attribute Set 比較 (M1 vs M2)</h2>
    <table>
        <tr>
            <th>M1 Attribute Set ID</th>
            <th>M1 Total Count</th>
            <th>M1 Attribute List (自然排序)</th>
            <th>M2 Attribute Set ID</th>
            <th>M2 Total Count</th>
            <th>M2 Attribute List (自然排序)</th>
            <th>Difference (M1 有但 M2 沒有)</th>
            <th>Difference (M2 有但 M1 沒有)</th>
        </tr>
        <?php foreach ($comparisonResults as $cmp): ?>
        <tr>
            <td><?php echo htmlspecialchars($cmp['m1SetId']); ?></td>
            <td><?php echo htmlspecialchars($cmp['m1Count']); ?></td>
            <td><?php echo htmlspecialchars(implode(", ", $cmp['m1Attributes'])); ?></td>
            <td><?php echo htmlspecialchars($cmp['m2SetId']); ?></td>
            <td><?php echo htmlspecialchars($cmp['m2Count']); ?></td>
            <td><?php echo htmlspecialchars(implode(", ", $cmp['m2Attributes'])); ?></td>
            <td><?php echo htmlspecialchars(implode(", ", $cmp['difference_m1'])); ?></td>
            <td><?php echo htmlspecialchars(implode(", ", $cmp['difference_m2'])); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
