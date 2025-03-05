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

// 取得分類屬性清單
try {
    $attributes = $client->call($session, 'catalog_category_attribute.list');
} catch (SoapFault $fault) {
    die("取得分類屬性列表失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n");
}

// 如果回傳結果不是陣列，轉換之
if (!is_array($attributes)) {
    $attributes = (array)$attributes;
}

// 針對 type 為 select 或 multiselect 的屬性，查詢其選項
foreach ($attributes as &$attribute) {
    if (isset($attribute['type']) &&
        ($attribute['type'] == 'select' || $attribute['type'] == 'multiselect')) {
        try {
            $options = $client->call($session, 'catalog_category_attribute.options', $attribute['code']);
            $attribute['options'] = $options;
        } catch (SoapFault $fault) {
            echo "查詢屬性 {$attribute['code']} 選項失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
            $attribute['options'] = array();
        }
    }
}
unset($attribute); // 解除參照

// 依 attribute_id 升冪排序（若 attribute_id 為空，則視為 0）
usort($attributes, function($a, $b) {
    $idA = isset($a['attribute_id']) && $a['attribute_id'] !== "" ? intval($a['attribute_id']) : 0;
    $idB = isset($b['attribute_id']) && $b['attribute_id'] !== "" ? intval($b['attribute_id']) : 0;
    return $idA - $idB;
});

// 設定 CSV 輸出檔案名稱
$outputFile = __DIR__ . '/catalog_category_attributes.csv';
$fp = fopen($outputFile, 'w');
if ($fp === false) {
    die("無法開啟檔案 {$outputFile} 用於寫入。\n");
}

// 定義欲輸出的欄位：code, label, type, required, scope, options
// label 優先使用 frontend_label，如不存在則用 code
$headers = array(
    "attribute_id", "code", "label", "type", "required", "scope", "options"
);
fputcsv($fp, $headers);

// 寫入每一筆屬性資料
foreach ($attributes as $attr) {
    $row = array();
    // 若 attribute_id 為空，輸出 "null"
    $row[] = (isset($attr['attribute_id']) && $attr['attribute_id'] !== "") ? $attr['attribute_id'] : "null";
    // code
    $row[] = isset($attr['code']) ? $attr['code'] : "";
    // label 取 frontend_label；若不存在則取 code
    $row[] = isset($attr['frontend_label']) && $attr['frontend_label'] !== "" ? $attr['frontend_label'] : (isset($attr['code']) ? $attr['code'] : "");
    // type
    $row[] = isset($attr['type']) ? $attr['type'] : "";
    // required
    $row[] = isset($attr['required']) ? $attr['required'] : "";
    // scope
    $row[] = isset($attr['scope']) ? $attr['scope'] : "";
    // options: 若存在且為陣列，取出各選項的 label，以逗號分隔
    if (isset($attr['options']) && is_array($attr['options'])) {
        $opts = array();
        foreach ($attr['options'] as $opt) {
            $opts[] = isset($opt['label']) ? $opt['label'] : '';
        }
        $row[] = implode(", ", $opts);
    } else {
        $row[] = "";
    }
    fputcsv($fp, $row);
}
fclose($fp);

echo "\n分類屬性已輸出至 CSV 檔案：{$outputFile}\n";

// 結束 Session
try {
    $client->endSession($session);
} catch (SoapFault $fault) {
    echo "結束 Session 發生錯誤: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
}
?>
