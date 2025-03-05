<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 從上層目錄讀取 config.json 設定檔
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

// 資料庫連線設定
$dbHost = $config['db_host'];
$dbName = $config['db_name'];
$dbUser = $config['db_user'];
$dbPass = $config['db_password'];

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage() . "\n");
}

// 取得所有 Attribute Set（假設產品 entity_type_id 為 4）
$sql = "SELECT attribute_set_id, attribute_set_name FROM eav_attribute_set WHERE entity_type_id = 4 ORDER BY attribute_set_id ASC";
$stmt = $pdo->query($sql);
$attributeSets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 取得所有 Attribute Group 資料
$sql = "SELECT attribute_group_id, attribute_group_name, attribute_set_id FROM eav_attribute_group ORDER BY attribute_set_id ASC, attribute_group_id ASC";
$stmt = $pdo->query($sql);
$allGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 建立一個陣列，以 attribute_set_id 為鍵存放對應的群組資料
$groupBySet = array();
foreach ($allGroups as $group) {
    $setId = $group['attribute_set_id'];
    if (!isset($groupBySet[$setId])) {
        $groupBySet[$setId] = array();
    }
    $groupBySet[$setId][] = $group;
}

// 建立一個函式，取得指定 attribute_set_id 與 attribute_group_id 下的屬性列表（以 attribute_code 為主）
function getAttributesByGroup(PDO $pdo, $setId, $groupId) {
    // 從 eav_entity_attribute 與 eav_attribute 查詢屬性
    $sql = "
        SELECT a.attribute_code
        FROM eav_entity_attribute AS eea
        JOIN eav_attribute AS a ON eea.attribute_id = a.attribute_id
        WHERE eea.attribute_set_id = :setId AND eea.attribute_group_id = :groupId
        ORDER BY eea.sort_order ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':setId' => $setId, ':groupId' => $groupId));
    $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $attrCodes = array();
    foreach ($attributes as $attr) {
        $attrCodes[] = $attr['attribute_code'];
    }
    return implode(", ", $attrCodes);
}

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>Magento Attribute Set 與群組及屬性列表</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { color: #333; }
        table { border-collapse: collapse; width: 90%; margin-bottom: 40px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f4f4f4; }
    </style>
</head>
<body>
    <h1>Magento Attribute Set 與群組及屬性列表</h1>

    <?php if (!empty($attributeSets)): ?>
        <?php foreach ($attributeSets as $set): 
            $setId = $set['attribute_set_id'];
            $setName = $set['attribute_set_name'];
        ?>
            <h2>Attribute Set ID: <?php echo htmlspecialchars($setId); ?> - <?php echo htmlspecialchars($setName); ?></h2>
            <?php if (isset($groupBySet[$setId]) && !empty($groupBySet[$setId])): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Attribute Group ID</th>
                            <th>Attribute Group 名稱</th>
                            <th>Attribute List</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupBySet[$setId] as $group): 
                            $groupId = $group['attribute_group_id'];
                            $groupName = $group['attribute_group_name'];
                            $attrList = getAttributesByGroup($pdo, $setId, $groupId);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($groupId); ?></td>
                            <td><?php echo htmlspecialchars($groupName); ?></td>
                            <td><?php echo htmlspecialchars($attrList); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>無群組資料</p>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <p>沒有取得任何 Attribute Set 資料</p>
    <?php endif; ?>
</body>
</html>
