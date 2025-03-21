# category_tree_attribute_transfer

本程式為分類屬性遷移的第二步，主要功能是將 Magento 1.9 中各分類的屬性數值轉移到 Magento 2。程式會根據先前產生的 (m1_category_id => m2_category_id) 對照表，透過 Magento 2 REST API 更新相對應的分類屬性。

## 功能概述

1. **讀取設定檔**  
   從 `../config.json` 讀取 Magento 1 與 Magento 2 的設定，包括資料庫連線資訊、Magento 網域、REST API 端點及 API 帳密等。

2. **連線 Magento 1 資料庫**  
   使用 PDO 建立與 Magento 1.9 資料庫的連線，供後續查詢分類屬性與屬性數值使用。

3. **取得 Magento 2 Admin Token**  
   利用 Magento 2 的 REST API `/integration/admin/token` 取得管理員 Token，此 Token 用於後續 API 請求認證。

4. **取得 Magento 1 分類屬性清單**  
   透過 SQL 查詢從 `eav_attribute` 與 `eav_entity_type` 表中獲取屬於 `catalog_category` 的所有屬性，不過濾系統內建屬性（即使 `is_user_defined` 為 false）。

5. **查詢屬性實際數值**  
   根據各屬性的 `backend_type`，從對應的資料表（例如 `catalog_category_entity_varchar`、`catalog_category_entity_int` 等）查詢各分類的屬性數值，並只取預設 store_id (0) 的值。查詢結果以 Magento 1 的 `category_id` 作為索引存入陣列。

6. **取得對照表**  
   使用先前遷移時產生的 (m1_category_id => m2_category_id) 對照表，確認 Magento 1 上的分類與 Magento 2 上對應分類的映射關係。

7. **更新 Magento 2 分類屬性**  
   定義函式 `updateMagento2CategoryCustomAttributes`，用於透過 Magento 2 REST API (PUT /V1/categories/:id) 更新分類的自訂屬性。此函式會：
   - 組合需更新的 `custom_attributes` 資料（排除 `available_sort_by` 與 `default_sort_by`）。
   - 輸出請求 URL 與 Payload 以便除錯。
   - 發送 PUT 請求並顯示 API 回應。

8. **執行屬性同步**  
   對於每個 Magento 1 分類（依據查詢結果的屬性數值），根據對照表找到對應的 Magento 2 分類 ID，然後呼叫更新函式同步自訂屬性。若對照表中找不到對應的分類，則跳過該筆資料。

## 程式流程詳解

1. **讀取設定檔與初始化連線**  
   - 讀取 `../config.json` 並解析 JSON 格式。
   - 取得 Magento 1 資料庫連線資訊及 Magento 2 API 設定。
   - 建立 PDO 與 Magento 1 資料庫連線，若連線失敗則終止程式。

2. **取得 Magento 2 Admin Token**  
   - 使用函式 `getMagento2AdminToken` 送出 POST 請求至 Magento 2 的 `/integration/admin/token`。
   - 成功取得 token 後，輸出該 token 供除錯參考。

3. **查詢 Magento 1 分類屬性**  
   - 執行 SQL 查詢，從 `eav_attribute` 與 `eav_entity_type` 表中取得所有 `catalog_category` 屬性。
   - 將查詢結果印出，確認屬性清單。

4. **取得屬性數值**  
   - 根據每個屬性的 `backend_type`，映射至相對應的資料表名稱。
   - 對每個屬性，從對應表中查詢 store_id = 0 的數值，並依據 `category_id` 組合成陣列，格式為 `[m1_category_id => [ attribute_code => value, ... ]]`。

5. **建立對照表**  
   - 透過事先定義好的 `$migrationMap`，將 Magento 1 的分類 ID 對應到 Magento 2 的分類 ID。

6. **更新 Magento 2 分類屬性**  
   - 定義更新函式 `updateMagento2CategoryCustomAttributes`，該函式會組合更新用的 Payload，然後發送 PUT 請求至 Magento 2。
   - 輸出每個請求的 URL、Payload 及 API 回應，方便追蹤除錯。

7. **執行屬性同步更新**  
   - 逐筆檢查從 Magento 1 查詢到的分類屬性數值，並比對對照表。
   - 若在對照表中有對應的 Magento 2 分類 ID，則調用更新函式同步該分類的自訂屬性；否則輸出錯誤提示並跳過。

8. **完成同步**  
   - 所有對應的分類屬性更新完成後，印出「自訂屬性同步完成」訊息。

## 總結

此程式主要用途在於將 Magento 1.9 中各分類的屬性數值轉移到 Magento 2 中，藉由查詢 Magento 1 的資料庫、組合對照表以及透過 Magento 2 REST API 更新資料，完成分類屬性資料的同步。程式中詳細的除錯資訊有助於追蹤更新過程與處理可能發生的錯誤。
