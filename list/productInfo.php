<?php
// 讀取外層設定檔 ../config.json
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

// 取得 Magento API 設定
$magentoHost = $config['magento_domain']; // 例如 "http://magentohost"
$apiUser     = $config['api_user'];       // 例如 "apiUser"
$apiKey      = $config['api_key'];        // 例如 "apiKey"

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

// 取得產品列表（catalog_product.list）
try {
    $productList = $client->call($session, 'catalog_product.list');
} catch (SoapFault $fault) {
    die("取得產品列表失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n");
}
if (!is_array($productList)) {
    $productList = (array)$productList;
}

echo "\n取得 " . count($productList) . " 筆產品列表資料\n";

// 逐一取得每個產品的詳細資訊並合併
$mergedProducts = array();
foreach ($productList as $product) {
    // 以 product_id 作為識別參數
    if (!isset($product['product_id']) || $product['product_id'] === "") {
        continue;
    }
    $prodId = $product['product_id'];
    try {
        $info = $client->call($session, 'catalog_product.info', array($prodId));
    } catch (SoapFault $fault) {
        echo "查詢產品資訊 (ID: $prodId) 失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
        // 如果查詢失敗，直接使用列表資料
        $info = array();
    }
    // 合併列表資料與詳細資訊（如果有相同鍵，info 覆蓋 product）
    $merged = array_merge($product, $info);
    $mergedProducts[] = $merged;
}

// 如果沒有任何產品合併資料，則提示並結束
if (empty($mergedProducts)) {
    echo "\n沒有取得任何產品資料。\n";
    try {
        $client->endSession($session);
    } catch (SoapFault $fault) {
        echo "結束 Session 發生錯誤: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
    }
    exit;
}

// 取得所有欄位（union），以便做 CSV 表頭
$allKeys = array();
foreach ($mergedProducts as $item) {
    $allKeys = array_merge($allKeys, array_keys($item));
}
$headers = array_unique($allKeys);

// 可依需求排序表頭，這裡先保留文件中較重要的欄位，然後補上其他欄位
$desiredOrder = array(
    "product_id", "sku", "name", "set", "type", "category_ids", "website_ids",
    "attribute_set_id", "attribute_set_name", // 若有屬性集合資訊，可包含
    "price", "special_price", "status", "created_at", "updated_at" // 依文件可能還有其他欄位
);
$remaining = array_diff($headers, $desiredOrder);
$headers = array_merge($desiredOrder, $remaining);

// 若您希望以某個欄位排序產品，可在此處對 $mergedProducts 排序，這裡以 product_id 升冪排序
usort($mergedProducts, function($a, $b) {
    if (isset($a['product_id']) && isset($b['product_id'])) {
        return intval($a['product_id']) - intval($b['product_id']);
    }
    return 0;
});

// 設定 CSV 輸出檔案名稱
$outputFile = __DIR__ . '/catalog_products.csv';
$fp = fopen($outputFile, 'w');
if ($fp === false) {
    die("無法開啟檔案 {$outputFile} 用於寫入。\n");
}

// 寫入 CSV 標題列
fputcsv($fp, $headers);

// 寫入每一筆合併產品資料
foreach ($mergedProducts as $item) {
    $row = array();
    foreach ($headers as $key) {
        if (isset($item[$key])) {
            // 如果是陣列，轉換為 JSON 格式
            if (is_array($item[$key])) {
                $row[] = json_encode($item[$key]);
            } else {
                $row[] = $item[$key];
            }
        } else {
            $row[] = "";
        }
    }
    fputcsv($fp, $row);
}
fclose($fp);

echo "\n產品列表與詳細資訊已合併並輸出成 CSV 檔案：{$outputFile}\n";
echo "總共取得 " . count($mergedProducts) . " 筆產品資料\n";

// 結束 Session
try {
    $client->endSession($session);
} catch (SoapFault $fault) {
    echo "結束 Session 發生錯誤: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
}
?>
