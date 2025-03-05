<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 從外層目錄讀取設定檔 ../config.json
$configFile = __DIR__ . '/../config.json';
if (!file_exists($configFile)) {
    die("設定檔 {$configFile} 不存在。\n");
}
$configJson = file_get_contents($configFile);
if ($configJson === false) {
    die("讀取設定檔失敗。\n");
}
$config = json_decode($configJson, true);
if ($config === null) {
    die("設定檔 JSON 格式錯誤。\n");
}

// Magento API 設定 (SOAP V2)
$magentoHost = $config['magento_domain']; // 例如 "http://magentohost"
$apiUser     = $config['api_user'];       // 例如 "apiUser"
$apiKey      = $config['api_key'];        // 例如 "apiKey"

// 建立 SOAP 客戶端 (SOAP V2)
try {
    $wsdlUrl = $magentoHost . '/api/v2_soap/?wsdl';
    $client = new SoapClient($wsdlUrl, array('trace' => 1));
} catch (Exception $e) {
    die("建立 SoapClient 失敗: " . $e->getMessage() . "\n");
}

// 登入取得 Session ID (SOAP V2)
try {
    $sessionObj = $client->login($apiUser, $apiKey);
    $session = $sessionObj->result;
} catch (SoapFault $fault) {
    die("登入失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n");
}

// 取得所有 Attribute Set（使用 SOAP V2 方法 catalogProductAttributeSetList）
try {
    $attrSetObj = $client->catalogProductAttributeSetList($session);
    if (isset($attrSetObj->result)) {
        $attributeSets = $attrSetObj->result;
    } else {
        $attributeSets = $attrSetObj; // 某些版本直接回傳陣列
    }
} catch (SoapFault $fault) {
    die("取得 Attribute Set 列表失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n");
}
if (!is_array($attributeSets)) {
    $attributeSets = (array)$attributeSets;
}

// 依 set_id 升冪排序
usort($attributeSets, function($a, $b) {
    return intval($a->set_id) - intval($b->set_id);
});

// 取得每個 Attribute Set 的詳細資訊（包含群組）
$results = array();
foreach ($attributeSets as $set) {
    $setId = $set->set_id;
    $setName = $set->name;
    
    try {
        $setInfoObj = $client->catalogProductAttributeSetInfo($session, $setId);
        $setInfo = $setInfoObj->result;
    } catch (SoapFault $fault) {
        $results[] = array(
            "set_id" => $setId,
            "set_name" => $setName,
            "error" => $fault->faultcode . " - " . $fault->faultstring,
            "groups" => array()
        );
        continue;
    }
    
    // 將群組資料存入結果
    $groups = array();
    if (isset($setInfo->groups) && is_array($setInfo->groups) && !empty($setInfo->groups)) {
        foreach ($setInfo->groups as $group) {
            $groups[] = array(
                "attribute_group_id" => isset($group->attribute_group_id) ? $group->attribute_group_id : "null",
                "attribute_group_name" => isset($group->attribute_group_name) ? $group->attribute_group_name : "",
                "attribute_set_id" => isset($group->attribute_set_id) ? $group->attribute_set_id : ""
            );
        }
        // 可依 attribute_group_id 排序
        usort($groups, function($a, $b) {
            return intval($a['attribute_group_id']) - intval($b['attribute_group_id']);
        });
    }
    $results[] = array(
        "set_id" => $setId,
        "set_name" => $setName,
        "error" => "",
        "groups" => $groups
    );
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>Magento Attribute Set 與 Attribute Group 列表 (SOAP V2)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        h1, h2, h3 { color: #333; }
    </style>
</head>
<body>
    <h1>Magento Attribute Set 與 Attribute Group 列表 (SOAP V2)</h1>

    <h2>所有 Attribute Set</h2>
    <table>
        <thead>
            <tr>
                <th>Attribute Set ID</th>
                <th>Attribute Set Name</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($attributeSets as $set): ?>
                <tr>
                    <td><?php echo htmlspecialchars($set->set_id); ?></td>
                    <td><?php echo htmlspecialchars($set->name); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>各 Attribute Set 的 Attribute Group 列表</h2>
    <?php foreach ($results as $res): ?>
        <h3>Attribute Set ID: <?php echo htmlspecialchars($res['set_id']); ?> - <?php echo htmlspecialchars($res['set_name']); ?></h3>
        <?php if (!empty($res['error'])): ?>
            <p style="color:red;">取得 Attribute Set <?php echo htmlspecialchars($res['set_id']); ?> (<?php echo htmlspecialchars($res['set_name']); ?>) 詳細資訊失敗：<?php echo htmlspecialchars($res['error']); ?></p>
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th>attribute_group_id</th>
                    <th>attribute_group_name</th>
                    <th>attribute_set_id</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($res['groups'])): ?>
                    <?php foreach ($res['groups'] as $group): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($group['attribute_group_id']); ?></td>
                            <td><?php echo htmlspecialchars($group['attribute_group_name']); ?></td>
                            <td><?php echo htmlspecialchars($group['attribute_set_id']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3">無群組資料</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</body>
</html>
