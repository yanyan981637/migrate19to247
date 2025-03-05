<?php
header("Content-Type: text/html; charset=UTF-8");

// 讀取 config.json 檔案
$configFile = '../config.json';
if (!file_exists($configFile)) {
    die("找不到 config.json 檔案。");
}

$configData = json_decode(file_get_contents($configFile), true);
if (!$configData) {
    die("無法解析 config.json 檔案。");
}

// 取得基本設定
$magentoDomain = rtrim($configData['magento2_domain'], '/');
$apiUser       = $configData['api_user'];
$apiKey        = $configData['api_key'];
// 將 /rest/all/V1 由 config 讀取
$restEndpoint  = isset($configData['rest_endpoint']) ? $configData['rest_endpoint'] : '/rest/all/V1';

// 建立 Token 取得 URL：組合域名與 token 端點（注意這邊 token 端點為 /integration/admin/token）
$tokenUrl = $magentoDomain . $restEndpoint . '/integration/admin/token';

// 建立分類列表 API 端點 URL：組合 config 中的 rest_endpoint 與 categories/list
$categoryEndpoint = $restEndpoint . '/categories/list';

// 取得管理員 Token
$postData = json_encode([
    "username" => $apiUser,
    "password" => $apiKey
]);

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json",
    "Content-Length: " . strlen($postData)
]);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    die("cURL error (token): " . curl_error($ch));
}
curl_close($ch);

$token = json_decode($response, true);
if (!isset($token) || !is_string($token)) {
    die("取得管理員 Token 失敗。回傳內容：" . htmlspecialchars($response));
}
echo "<p>管理員 Token： " . htmlspecialchars($token) . "</p>";

// 呼叫分類列表 API
$queryParams = [
    'searchCriteria[currentPage]' => 1,
    'searchCriteria[pageSize]'    => 10,
];
$categoryUrl = $magentoDomain . $categoryEndpoint . '?' . http_build_query($queryParams);

$chCategories = curl_init($categoryUrl);
curl_setopt($chCategories, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chCategories, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json",
    "Authorization: Bearer " . $token
]);

$categoriesResponse = curl_exec($chCategories);
if (curl_errno($chCategories)) {
    die("cURL error (categories list): " . curl_error($chCategories));
}
curl_close($chCategories);

$decodedResponse = json_decode($categoriesResponse, true);

echo "<h3>分類列表查詢結果（JSON 格式）：</h3>";
if ($decodedResponse === null) {
    echo $categoriesResponse;
} else {
    // 若回傳資料中有 items，則整理成 HTML 資料表
    if (isset($decodedResponse['items']) && is_array($decodedResponse['items'])) {
        $items = $decodedResponse['items'];
        if (count($items) > 0) {
            // 以第一筆資料的 key 作為表頭
            $headers = array_keys($items[0]);
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<thead><tr>";
            foreach ($headers as $header) {
                echo "<th>" . htmlspecialchars($header) . "</th>";
            }
            echo "</tr></thead><tbody>";
            foreach ($items as $item) {
                echo "<tr>";
                foreach ($headers as $header) {
                    $value = isset($item[$header]) ? $item[$header] : '';
                    // 若值為陣列，轉換為 JSON 字串
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    echo "<td>" . htmlspecialchars($value) . "</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<p>沒有找到分類資料。</p>";
        }
    } else {
        echo "<pre>";
        print_r($decodedResponse);
        echo "</pre>";
    }
}
?>
