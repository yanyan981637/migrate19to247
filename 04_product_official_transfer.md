# product_official_transfer

此程式主要目的在於將 Magento 1.9 的產品資料轉換為符合 Magento 2.4.7 REST API 格式的 payload，並將資料 POST 到 Magento 2。該程式結合了 Magento 1.9 的 SOAP API 與資料庫查詢，進行資料轉換、圖片處理、屬性選單轉換及 API 傳送，並在網頁上即時顯示各項除錯與處理結果。

---

## 主要功能

1. **資料轉換**  
   - 從 Magento 1.9 取得所有產品資料（僅處理 attribute_set_id 在映射表中的產品）。
   - 將產品資料轉換成 Magento 2.4.7 /V1/products 所需的 payload 格式（不含 id 欄位）。
   - 根據映射表轉換 attribute_set_id 與 category_links。

2. **圖片處理**  
   - 針對產品的 media_gallery_entries，根據 Magento 1.9 的圖片路徑組合完整 URL。
   - 使用 HTTP 讀取圖片並轉成 base64 格式；若讀取失敗則不納入 payload，並記錄錯誤圖片 URL。

3. **屬性選單轉換**  
   - 針對 custom_attributes 中特定的選單型屬性（例如在 $attributesToConvert 列表中的屬性），若其值為數字（代表 Magento 1 的 option_id），則根據 Magento 1 與 Magento 2 的對應關係進行轉換，只取 store_id 為 0 的唯一值。

4. **產品相關附加資訊**  
   - 組合 extension_attributes，包含網站、分類連結、庫存、可配置產品選項與連結等資訊。
   - 取得 tier_prices 並保留其他產品連結與選項資訊。

5. **API POST 與錯誤處理**  
   - 依序將轉換後的產品 payload POST 至 Magento 2.4.7 的 REST API。
   - 當遇到 URL key 重複錯誤時，修改 custom_attributes 中的 url_key（加上 "-1"）並重試，最多重試 2 次。
   - 在網頁上即時輸出每筆 POST 的 Payload 與 API Response，並將所有結果彙整成表格顯示。

---

## 程式運作流程詳解

### 1. 環境設定與初始化

- **執行環境設定**  
  - 使用 `set_time_limit(0)` 取消執行時間限制。
  - 透過 `ini_set` 與 `error_reporting(E_ALL)` 顯示所有錯誤訊息，方便除錯。

- **配置檔讀取**  
  - 從 `../config.json` 讀取 Magento 1 與 Magento 2 的相關設定，包含 API 參數、資料庫連線資訊以及各項映射資料（例如 category 與 attribute_set 的對照）。

- **顯示控制**  
  - 變數 `$showPayloadDetails` 可用來控制是否在頁面上詳細顯示 Magento 2 payload 資料表格。

- **圖片錯誤記錄**  
  - 全域陣列 `$imageErrors` 用於記錄圖片讀取失敗的 URL。

### 2. 建立連線

- **Magento 1 SOAP API 連線**  
  - 利用 Magento 1 的 SOAP API 登入，取得產品列表與單筆產品詳細資訊。

- **Magento 1 與 Magento 2 資料庫連線**  
  - 使用 PDO 建立與 Magento 1 及 Magento 2 資料庫的連線，方便後續屬性值及選單型資料的轉換。

### 3. 資料轉換與 Payload 組合

- **屬性與分類映射**  
  - 透過 `$migrationMap` 與 `$attributeSetMap` 將 Magento 1 的 category 與 attribute_set_id 轉換為 Magento 2 的對應值。

- **處理 extension_attributes**  
  - 組合網站 ID、category_links（根據映射表轉換）、可配置產品的選項與連結等，構成 Magento 2 需要的 extension_attributes。

- **處理 media_gallery_entries**  
  - 從 Magento 1 資料庫撈取產品圖片資料，根據圖片相對路徑組合完整 URL。
  - 嘗試使用 `file_get_contents` 讀取圖片，若成功則轉換為 base64 格式，並組成符合 Magento 2 payload 格式的 media_gallery entry；若失敗，則記錄錯誤並不納入 payload。

- **處理 tier_prices**  
  - 從 Magento 1 資料庫取得 tier_prices 資料，若無則設為空陣列。

- **組合 custom_attributes**  
  - 使用 UNION 查詢從各個 backend 表（varchar、int、text、decimal、datetime）取得產品屬性值，僅取 store_id 為 0 的值。
  - 對於指定需轉換選單型屬性的 attribute，依據 Magento 1 option label 與 Magento 2 的對應關係，從 Magento 2 資料庫取得正確的 option_id，並覆蓋原值。

- **最終產品資料組合**  
  - 將上述所有資料（SKU、名稱、價格、狀態、類型、日期、重量、extension_attributes、custom_attributes、media_gallery_entries、tier_prices 等）整合成符合 Magento 2 /V1/products payload 格式的產品資料。

### 4. API POST 與除錯輸出

- **結束 Magento 1 SOAP Session**  
  - 完成產品資料處理後，呼叫 `endSession` 結束 SOAP 連線。

- **POST 產品資料至 Magento 2**  
  - 當使用者在頁面上按下表單的 "POST 至 Magento2" 按鈕時：
    - 從 Magento 2 REST API 取得 admin token。
    - 依序針對每筆產品資料進行 API POST 傳送：
      - 若遇到「URL key 重複」錯誤，修改 custom_attributes 中的 url_key 值（加上 "-1"）並重送，最多嘗試 2 次。
    - 將每筆 API 呼叫的 Payload 與 Response 詳細資訊存入陣列，並於頁面上以表格方式呈現。

- **結果與除錯資訊顯示**  
  - 頁面上除了顯示每筆產品 API POST 結果外，也會依序列出每個產品的 Magento 1 原始資料與轉換後的 Magento 2 payload 內容（若 $showPayloadDetails 為 true）。
  - 若圖片讀取失敗，將在頁面底部列出所有錯誤的圖片 URL。
  - 同時提供常見錯誤（如 401 Unauthorized、400 Bad Request）提示，方便使用者根據錯誤碼進行檢查與除錯。

---

## 總結

此腳本實現了從 Magento 1.9 到 Magento 2.4.7 的產品資料遷移流程，主要功能包括：

- 整合 SOAP API 與資料庫查詢取得 Magento 1 的產品資料。
- 根據映射表轉換 attribute_set_id 與 category 連結，組合符合 Magento 2 的 payload 格式。
- 處理產品圖片，將圖片轉成 base64 格式後納入 media_gallery_entries。
- 對指定選單型屬性進行選項 ID 的轉換，確保資料正確映射至 Magento 2。
- 逐筆透過 Magento 2 REST API POST 產品資料，並即時顯示傳送結果與除錯資訊。

透過此腳本，能夠完整且自動化地將 Magento 1.9 的產品資料遷移至 Magento 2.4.7，並藉由詳細的除錯輸出協助使用者確認資料遷移狀況與處理錯誤。

