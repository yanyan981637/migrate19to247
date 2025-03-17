<?php
// 載入配置檔案
$configPath = '../config.json';
if (!file_exists($configPath)) {
    die("找不到配置檔案: {$configPath}");
}

$config = json_decode(file_get_contents($configPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("解析 JSON 配置檔錯誤: " . json_last_error_msg());
}

// 取得 Magento 1.9 的相關設定
$magentoDomain = rtrim($config['magento_domain'], '/') . '/';
$apiUser       = $config['api_user'];
$apiKey        = $config['api_key'];

// 組成 Magento 1.9 SOAP V1 的 WSDL URL
$wsdlUrl = $magentoDomain . 'api/soap/?wsdl';

// 遞迴函式：用來產生分類樹的 HTML 結構
function renderCategoryTree($category) {
    // 開始建立無序列表
    echo '<ul>';
    // 如果傳入的 $category 為一個分類集合（例如 children 為陣列）
    if (isset($category[0]) && is_array($category)) {
        foreach ($category as $cat) {
            echo '<li>';
            echo htmlspecialchars($cat['name']) . " (ID: " . htmlspecialchars($cat['category_id']) . ")";
            if (isset($cat['children']) && is_array($cat['children']) && count($cat['children']) > 0) {
                renderCategoryTree($cat['children']);
            }
            echo '</li>';
        }
    } else {
        // 若是單一分類物件
        echo '<li>';
        echo htmlspecialchars($category['name']) . " (ID: " . htmlspecialchars($category['category_id']) . ")";
        if (isset($category['children']) && is_array($category['children']) && count($category['children']) > 0) {
            renderCategoryTree($category['children']);
        }
        echo '</li>';
    }
    echo '</ul>';
}

try {
    // 建立 SOAP Client 並登入
    $client = new SoapClient($wsdlUrl);
    $session = $client->login($apiUser, $apiKey);
    
    // 呼叫 API 取得 category tree
    $result = $client->call($session, 'catalog_category.tree');
    
    // 將結果以 HTML 顯示
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Magento 1.9 Category Tree</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            ul { list-style-type: none; padding-left: 20px; }
            li { margin: 5px 0; }
        </style>
    </head>
    <body>
        <h1>Magento 1.9 Category Tree</h1>
        <?php
            // $result 為根目錄分類
            renderCategoryTree([$result]);
        ?>
    </body>
    </html>
    <?php
} catch (SoapFault $e) {
    echo "SOAP Error: " . $e->getMessage();
} catch (Exception $ex) {
    echo "Error: " . $ex->getMessage();
}
?>
