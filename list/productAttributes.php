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

// 取得所有 Attribute Set（產品屬性集合）
try {
    $attributeSets = $client->call($session, 'catalog_product_attribute_set.list');
} catch (SoapFault $fault) {
    die("取得產品屬性集合列表失敗: " . $fault->faultcode . " - " . $fault->faultstring . "\n");
}
if (!is_array($attributeSets)) {
    $attributeSets = (array)$attributeSets;
}
echo "\n共有 " . count($attributeSets) . " 個 Attribute Set\n";

// 用來儲存所有產品屬性，利用 attribute_code 作為唯一鍵以去除重複
$uniqueAttributes = array();
foreach ($attributeSets as $set) {
    $setId   = isset($set['set_id']) ? $set['set_id'] : "";
    $setName = isset($set['name']) ? $set['name'] : "";
    
    // 呼叫產品屬性列表方法，傳入 setId
    try {
        $attributes = $client->call($session, 'product_attribute.list', array($setId));
    } catch (SoapFault $fault) {
        echo "取得屬性集合 ID {$setId} ({$setName}) 產品屬性列表失敗: " 
             . $fault->faultcode . " - " . $fault->faultstring . "\n";
        continue;
    }
    
    if (!is_array($attributes)) {
        $attributes = (array)$attributes;
    }
    
    echo "Attribute Set {$setId} ({$setName}) 取得 " . count($attributes) . " 筆屬性\n";
    
    foreach ($attributes as $attr) {
        $code = "";
        if (isset($attr['attribute_code']) && $attr['attribute_code'] !== "") {
            $code = $attr['attribute_code'];
        } elseif (isset($attr['code']) && $attr['code'] !== "") {
            $code = $attr['code'];
        }
        if ($code !== "" && !isset($uniqueAttributes[$code])) {
            $uniqueAttributes[$code] = $attr;
        }
    }
}

if (empty($uniqueAttributes)) {
    echo "\n沒有取得任何產品屬性，請檢查是否有有效屬性集合或 API 權限設定。\n";
    try {
        $client->endSession($session);
    } catch (SoapFault $fault) {
        echo "結束 Session 發生錯誤: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
    }
    exit;
}

// 將 uniqueAttributes 轉換成索引陣列
$allAttributes = array_values($uniqueAttributes);

// 依 attribute_id 升冪排序；若 attribute_id 為空則以 attribute_code 排序
usort($allAttributes, function($a, $b) {
    if (isset($a['attribute_id']) && isset($b['attribute_id']) && $a['attribute_id'] !== "" && $b['attribute_id'] !== "") {
        return intval($a['attribute_id']) - intval($b['attribute_id']);
    } else {
        $codeA = isset($a['attribute_code']) ? strtolower($a['attribute_code']) : '';
        $codeB = isset($b['attribute_code']) ? strtolower($b['attribute_code']) : '';
        return strcmp($codeA, $codeB);
    }
});

// 逐一查詢每個產品屬性完整資訊 (使用 product_attribute.info)
// 若查詢失敗，直接使用 list 中的資料
$fullAttributes = array();
foreach ($allAttributes as $attr) {
    $identifier = "";
    if (isset($attr['attribute_code']) && $attr['attribute_code'] !== "") {
        $identifier = $attr['attribute_code'];
    } elseif (isset($attr['attribute_id']) && $attr['attribute_id'] !== "") {
        $identifier = $attr['attribute_id'];
    }
    if ($identifier === "") {
        continue;
    }
    try {
        $info = $client->call($session, 'product_attribute.info', array($identifier));
        if (is_array($info)) {
            $fullAttributes[] = $info;
        }
    } catch (SoapFault $fault) {
        // 若查詢失敗，直接使用原 list 的資料（不填 error 欄位）
        $fullAttributes[] = $attr;
        echo "查詢屬性資訊 {$identifier} 失敗，使用 list 資料代替。\n";
    }
}

if (empty($fullAttributes)) {
    echo "\n沒有取得任何產品屬性詳細資訊。\n";
    try {
        $client->endSession($session);
    } catch (SoapFault $fault) {
        echo "結束 Session 發生錯誤: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
    }
    exit;
}

// 對完整屬性資訊依 attribute_id 升冪排序
usort($fullAttributes, function($a, $b) {
    if (isset($a['attribute_id']) && isset($b['attribute_id']) && $a['attribute_id'] !== "" && $b['attribute_id'] !== "") {
        return intval($a['attribute_id']) - intval($b['attribute_id']);
    } else {
        $codeA = isset($a['attribute_code']) ? strtolower($a['attribute_code']) : '';
        $codeB = isset($b['attribute_code']) ? strtolower($b['attribute_code']) : '';
        return strcmp($codeA, $codeB);
    }
});

// 定義 CSV 輸出檔案名稱（覆蓋原檔）
$outputFile = __DIR__ . '/catalog_product_attributes.csv';
$fp = fopen($outputFile, 'w');
if ($fp === false) {
    die("無法開啟檔案 {$outputFile} 用於寫入。\n");
}

// 定義 CSV 欄位，包含文獻中所有 response 欄位
$headers = array(
    "attribute_id", 
    "attribute_code", 
    "frontend_input", 
    "default_value", 
    "is_unique", 
    "is_required", 
    "scope", 
    "apply_to", 
    "is_configurable", 
    "is_searchable", 
    "is_visible_in_advanced_search", 
    "is_comparable", 
    "is_used_for_promo_rules", 
    "is_visible_on_front", 
    "used_in_product_listing", 
    "additional_fields", 
    "options", 
    "frontend_label"
);
fputcsv($fp, $headers);

// 輸出每一筆屬性詳細資訊到 CSV
foreach ($fullAttributes as $attr) {
    $row = array();
    // attribute_id：若空則顯示 "null"
    $row[] = (isset($attr['attribute_id']) && $attr['attribute_id'] !== "") ? $attr['attribute_id'] : "null";
    // attribute_code
    $row[] = isset($attr['attribute_code']) ? $attr['attribute_code'] : "";
    // frontend_input
    $row[] = isset($attr['frontend_input']) ? $attr['frontend_input'] : "";
    // default_value
    $row[] = isset($attr['default_value']) ? $attr['default_value'] : "";
    // is_unique
    $row[] = isset($attr['is_unique']) ? $attr['is_unique'] : "";
    // is_required
    $row[] = isset($attr['is_required']) ? $attr['is_required'] : "";
    // scope
    $row[] = isset($attr['scope']) ? $attr['scope'] : "";
    // apply_to：若為陣列則以逗號分隔
    if (isset($attr['apply_to']) && is_array($attr['apply_to'])) {
        $row[] = implode(", ", $attr['apply_to']);
    } else {
        $row[] = isset($attr['apply_to']) ? $attr['apply_to'] : "";
    }
    // is_configurable
    $row[] = isset($attr['is_configurable']) ? $attr['is_configurable'] : "";
    // is_searchable
    $row[] = isset($attr['is_searchable']) ? $attr['is_searchable'] : "";
    // is_visible_in_advanced_search
    $row[] = isset($attr['is_visible_in_advanced_search']) ? $attr['is_visible_in_advanced_search'] : "";
    // is_comparable
    $row[] = isset($attr['is_comparable']) ? $attr['is_comparable'] : "";
    // is_used_for_promo_rules
    $row[] = isset($attr['is_used_for_promo_rules']) ? $attr['is_used_for_promo_rules'] : "";
    // is_visible_on_front
    $row[] = isset($attr['is_visible_on_front']) ? $attr['is_visible_on_front'] : "";
    // used_in_product_listing
    $row[] = isset($attr['used_in_product_listing']) ? $attr['used_in_product_listing'] : "";
    // additional_fields：以 JSON 字串表示
    $row[] = isset($attr['additional_fields']) ? json_encode($attr['additional_fields']) : "";
    // options：若存在且為陣列，合併各選項的 label
    if (isset($attr['options']) && is_array($attr['options'])) {
        $opts = array();
        foreach ($attr['options'] as $opt) {
            $opts[] = isset($opt['label']) ? $opt['label'] : "";
        }
        $row[] = implode(", ", $opts);
    } else {
        $row[] = "";
    }
    // frontend_label：若為陣列則合併成逗號分隔字串；否則直接輸出
    if (isset($attr['frontend_label']) && is_array($attr['frontend_label'])) {
        $labels = array();
        foreach ($attr['frontend_label'] as $labelInfo) {
            $labels[] = isset($labelInfo['label']) ? $labelInfo['label'] : "";
        }
        $row[] = implode(", ", $labels);
    } else {
        $row[] = isset($attr['frontend_label']) ? $attr['frontend_label'] : "";
    }
    
    fputcsv($fp, $row);
}
fclose($fp);

echo "\n完整產品屬性詳細資訊已輸出成 CSV 檔案：{$outputFile}\n";
echo "總共取得 " . count($fullAttributes) . " 筆產品屬性詳細資訊\n";

// 結束 Session
try {
    $client->endSession($session);
} catch (SoapFault $fault) {
    echo "結束 Session 發生錯誤: " . $fault->faultcode . " - " . $fault->faultstring . "\n";
}
?>
