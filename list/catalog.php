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

// 查詢 Store List，選取第一個 store view 作為查詢依據
$storeView = "";
try {
    $stores = $client->call($session, 'store.list', array());
    $stores = (array)$stores;
    echo "\nStore List:\n";
    print_r($stores);
    if (!empty($stores)) {
        // 假設取第一個 store view 的 'code'
        $firstStore = reset($stores);
        if (isset($firstStore['code'])) {
            $storeView = $firstStore['code'];
        } else {
            // 若 store view 結構不同，可依實際調整
            $storeView = "default";
        }
    } else {
        $storeView = "default";
    }
} catch (SoapFault $fault) {
    echo "取得 Store List 失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
    // 預設使用 "default"
    $storeView = "default";
}
echo "使用 store view: $storeView\n";

// 設定 parentId
// 請根據您的 Magento 系統確認，常見預設值可能為 "2"（Default Category）或 "1"
$parentId = "2";

// 呼叫 catalog_category.tree 方法
try {
    $tree = $client->call($session, 'catalog_category.tree');
    $tree = (array)$tree;
    echo "\n取得分類樹成功。\n";
    // 如需檢查完整樹狀結構，可使用 print_r($tree);
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

// 取得每個分類的詳細資訊
$catInfos = array();
foreach ($flatCategories as $cat) {
    $catId = $cat['category_id'];
    try {
        // catalog_category.info 的參數順序: session, categoryId, storeView (選填)
        $info = $client->call($session, 'catalog_category.info', array($catId, $storeView));
        // 轉換成陣列
        $catInfos[] = (array)$info;
    } catch (SoapFault $fault) {
        echo "取得分類 {$catId} 資訊失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
    }
}

// 檢查是否有成功取得分類資訊
if (empty($catInfos)) {
    echo "沒有取得任何分類資訊。\n";
    // 結束 Session
    try {
        $client->endSession($session);
    } catch (SoapFault $fault) {
        echo "結束 Session 發生錯誤: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
    }
    exit;
}

// 依 category_id 升冪排序
usort($catInfos, function ($a, $b) {
    return intval($a['category_id']) - intval($b['category_id']);
});

// 定義顯示欄位，根據文件內容
$headers = array(
    "category_id", "is_active", "position", "level", "parent_id",
    "name", "url_key", "description", "created_at", "updated_at",
    "children_count", "include_in_menu"
);

// 印出標題
echo "\n分類資訊 (依 category_id 升冪排序):\n";
echo implode("\t", $headers) . "\n";

// 印出每一筆分類資訊
foreach ($catInfos as $info) {
    $row = array();
    foreach ($headers as $col) {
        $row[] = isset($info[$col]) ? $info[$col] : "";
    }
    echo implode("\t", $row) . "\n";
}

// 結束 Session
try {
    $client->endSession($session);
} catch (SoapFault $fault) {
    echo "結束 Session 發生錯誤: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
}
?>
