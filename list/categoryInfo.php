<?php
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

// 讀取 Magento API 設定
$magentoHost = $config['magento_domain']; // 例如 "http://magentohost"
$apiUser = $config['api_user'];           // 例如 "apiUser"
$apiKey  = $config['api_key'];            // 例如 "apiKey"

// 建立 SOAP 客戶端 (SOAP V1)
try {
    $wsdlUrl = $magentoHost . '/api/soap/?wsdl';
    $client = new SoapClient($wsdlUrl, array('trace' => 1));
} catch (Exception $e) {
    die("建立 SoapClient 失敗: " . $e->getMessage() . "\n");
}

// 登入取得 Session ID
try {
    $session = $client->login($apiUser, $apiKey);
    echo "取得 Session ID: $session\n";
} catch (SoapFault $fault) {
    die("登入失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n");
}

// 呼叫 catalog_category.tree 方法，這裡不傳入 parentId 與 storeView
try {
    $tree = $client->call($session, 'catalog_category.tree', array());
    $tree = (array)$tree;
    echo "\n取得分類樹成功。\n";
} catch (SoapFault $fault) {
    die("取得分類樹失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n");
}

// 遞迴平坦化分類樹
function flattenTree($node) {
    $result = array();
    if (isset($node['category_id'])) {
        $result[] = $node;
    }
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            $result = array_merge($result, flattenTree($child));
        }
    }
    return $result;
}

$flatCategories = flattenTree($tree);
echo "總共取得 " . count($flatCategories) . " 個分類\n";

// 依序呼叫 catalog_category.info 取得每個分類的詳細資訊
$catInfos = array();
foreach ($flatCategories as $cat) {
    $catId = $cat['category_id'];
    try {
        $info = $client->call($session, 'catalog_category.info', array($catId));
        $catInfos[] = (array)$info;
    } catch (SoapFault $fault) {
        echo "取得分類 {$catId} 資訊失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
    }
}

// 依 category_id 升冪排序（轉換成整數排序）
usort($catInfos, function ($a, $b) {
    return intval($a['category_id']) - intval($b['category_id']);
});

// 定義顯示欄位（依據文件中的欄位）
$headers = array(
    "category_id", "is_active", "position", "level", "parent_id",
    "name", "url_key", "description", "created_at", "updated_at",
    "children_count", "include_in_menu"
);

// 輸出到 CSV 檔案
$outputFile = __DIR__ . '/catalog_categories.csv';
$fp = fopen($outputFile, 'w');
if ($fp === false) {
    die("無法開啟檔案 {$outputFile} 用於寫入。\n");
}

// 寫入標題列
fputcsv($fp, $headers);

// 寫入每一筆資料
foreach ($catInfos as $info) {
    $row = array();
    foreach ($headers as $col) {
        $row[] = isset($info[$col]) ? $info[$col] : "";
    }
    fputcsv($fp, $row);
}
fclose($fp);

echo "\n分類資訊已輸出到 Excel 可讀的 CSV 檔案：{$outputFile}\n";

// 結束 Session
try {
    $client->endSession($session);
} catch (SoapFault $fault) {
    echo "結束 Session 發生錯誤: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
}
?>
