<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 讀取 config.json 設定檔 (位於上層目錄)
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

// 取得 Attribute Set 資料（全部欄位），僅針對 attribute_set_id 為 22 與 23
$sqlSet = "SELECT * FROM eav_attribute_set WHERE attribute_set_id IN (22, 23) ORDER BY attribute_set_id ASC";
$stmt = $pdo->query($sqlSet);
$attributeSets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 建立 Attribute Set mapping，方便後續使用
$setMapping = array();
foreach ($attributeSets as $set) {
    $setMapping[$set['attribute_set_id']] = $set;
}

// 取得 Attribute Group 資料 (針對 set 22 與 23)
$sqlGroup = "
    SELECT 
        s.attribute_set_id,
        s.attribute_set_name,
        g.attribute_group_id,
        g.attribute_group_name
    FROM eav_attribute_group AS g
    JOIN eav_attribute_set AS s ON g.attribute_set_id = s.attribute_set_id
    WHERE g.attribute_set_id IN (22, 23)
    ORDER BY g.attribute_set_id, g.attribute_group_id
";
$stmt = $pdo->query($sqlGroup);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 建立群組 mapping： mapping[set_id][group_id] = group_name
$groupMapping = array();
foreach ($groups as $grp) {
    $setId = $grp['attribute_set_id'];
    $groupId = $grp['attribute_group_id'];
    $groupMapping[$setId][$groupId] = $grp['attribute_group_name'] ?? '';
}

// 取得每個 Attribute Set 下所有自定義屬性資料（is_user_defined = 1），抓取 eav_attribute 的所有欄位
$sqlAttr = "
    SELECT 
        eea.attribute_set_id,
        eea.attribute_group_id,
        a.*
    FROM eav_entity_attribute AS eea
    JOIN eav_attribute AS a ON eea.attribute_id = a.attribute_id
    WHERE eea.attribute_set_id IN (22, 23)
      AND a.is_user_defined = 1
    ORDER BY eea.attribute_set_id, eea.attribute_group_id, eea.sort_order
";
$stmt = $pdo->query($sqlAttr);
$attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 將屬性依據 attribute_set_id 與 attribute_group_id 分組
$setGroupData = array();
foreach ($attributes as $attr) {
    $setId = $attr['attribute_set_id'];
    $groupId = $attr['attribute_group_id'];
    if (!isset($setGroupData[$setId])) {
        $setGroupData[$setId] = array();
    }
    if (!isset($setGroupData[$setId][$groupId])) {
        $setGroupData[$setId][$groupId] = array();
    }
    $setGroupData[$setId][$groupId][] = $attr;
}

// HTML 輸出
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>Magento 1.9 Attribute Set Group 與 Attribute 詳細資訊</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2, h3 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; font-size: 14px; }
        th { background-color: #f2f2f2; }
        pre { background-color: #f9f9f9; padding: 8px; }
    </style>
</head>
<body>
    <h1>Magento 1.9 Attribute Set Group 與 Attribute 詳細資訊</h1>
    
    <?php foreach ([22, 23] as $setId): 
            $setInfo = $setMapping[$setId] ?? null;
            if (!$setInfo) continue;
    ?>
        <h2>Attribute Set ID: <?php echo htmlspecialchars((string)$setId); ?> - <?php echo htmlspecialchars((string)($setInfo['attribute_set_name'] ?? '')); ?></h2>
        
        <?php 
        // 若此 Attribute Set 存在群組資料，依每個群組輸出表格
        if (isset($setGroupData[$setId]) && !empty($setGroupData[$setId])):
            foreach ($setGroupData[$setId] as $groupId => $attrList):
                $groupName = $groupMapping[$setId][$groupId] ?? 'N/A';
        ?>
            <h3>Attribute Group ID: <?php echo htmlspecialchars((string)$groupId); ?> - <?php echo htmlspecialchars((string)$groupName); ?></h3>
            <table>
                <thead>
                    <tr>
                        <?php
                        // 取得第一筆資料的欄位名稱作為表頭（排除 attribute_set_id 與 attribute_group_id）
                        if (!empty($attrList)) {
                            $first = reset($attrList);
                            foreach (array_keys($first) as $key) {
                                if (in_array($key, array('attribute_set_id','attribute_group_id'))) continue;
                                echo "<th>" . htmlspecialchars((string)$key) . "</th>";
                            }
                        }
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attrList as $attr): ?>
                        <tr>
                            <?php
                            foreach ($attr as $key => $value) {
                                if (in_array($key, array('attribute_set_id','attribute_group_id'))) continue;
                                if (is_array($value)) {
                                    $value = json_encode($value);
                                }
                                // 使用 null 合併運算子，若值為 null則轉成空字串
                                echo "<td>" . htmlspecialchars((string)($value ?? '')) . "</td>";
                            }
                            ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php 
            endforeach;
        else:
            echo "<p>此 Attribute Set 無群組或屬性資料。</p>";
        endif;
        ?>
    <?php endforeach; ?>
</body>
</html>
