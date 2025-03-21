# compare19and247attribute

此程式主要功能在於比較 Magento 1.9 與 Magento 2.4.7 中相對應的 Attribute Set 之屬性清單，藉由比對兩邊屬性列表的差異，找出：

- 在 Magento 1 有但 Magento 2 沒有的屬性
- 在 Magento 2 有但 Magento 1 沒有的屬性

透過此比較結果，團隊可以了解兩邊屬性集之間的差異，進一步進行資料同步或調整。

---

## 程式運作流程

### 1. 載入設定檔
- 從 `../config.json` 讀取設定資料，包含 Magento 1 與 Magento 2 的資料庫連線資訊、API 網域、REST API 端點等。
- 檢查設定檔是否存在與格式是否正確。

### 2. Magento 1.9 資料庫連線設定 (使用 PDO)
- 根據設定檔中的參數（db_host、db_name、db_user、db_password），建立與 Magento 1 資料庫的 PDO 連線。
- 設定 PDO 的錯誤處理模式為 Exception。

### 3. 取得 Magento 1.9 的 Attribute Set 屬性列表
- 針對 Attribute Set ID 為 **22** 與 **23** 的屬性進行查詢：
  - 透過 `eav_attribute` 表搭配 `eav_entity_attribute` 表查詢指定屬性集下的所有屬性。
  - 若屬性有 `attribute_code` 則取其值，否則使用 `attribute_id` 作為標示。
  - 移除重複值後，依照自然排序整理屬性清單，並統計每個屬性集的屬性數量。
- 結果存放在 `$m1Results` 陣列中，並累計總屬性數量。

### 4. Magento 2 REST API 參數設定與取得 Admin Token
- 根據設定檔中的 Magento 2 網域與 REST API 端點，建立相關參數。
- 呼叫 `getMagento2AdminToken()` 函式，透過 POST 請求取得 Magento 2 Admin Token，用於後續 API 呼叫認證。

### 5. 取得 Magento 2 的 Attribute Set 屬性列表 (透過 REST API)
- 指定 Attribute Set ID 為 **9** 與 **10**（分別對應 Magento 1 的屬性集 23 與 22）。
- 對每個 Attribute Set，發送 GET 請求至 `/products/attribute-sets/{setId}/attributes`。
- 處理回傳的 JSON 資料，提取每個屬性的 `attribute_code`（若無則取 `attribute_id`），並進行重複值移除與自然排序。
- 結果存放於 `$m2Results` 陣列中，並累計總屬性數量。

### 6. 比較 Magento 1 與 Magento 2 的屬性清單
- 定義映射關係：
  - Magento 1 Attribute Set ID **22** 對應 Magento 2 Attribute Set ID **10**
  - Magento 1 Attribute Set ID **23** 對應 Magento 2 Attribute Set ID **9**
- 對於每對映射：
  - 取得 Magento 1 與 Magento 2 各自的屬性清單。
  - 使用 `array_diff` 取得兩邊的差集：
    - **difference_m1**：在 Magento 1 有但 Magento 2 沒有的屬性。
    - **difference_m2**：在 Magento 2 有但 Magento 1 沒有的屬性。
- 將比較結果存入 `$comparisonResults` 陣列，包含各屬性集的 ID、屬性數量、屬性清單與差集結果。

### 7. 輸出 HTML 報表
- 最終以 HTML 頁面的形式呈現結果，包含三個主要表格：
  1. **Magento 1.9 資料庫查詢結果**  
     - 顯示 Attribute Set ID（22 與 23）、屬性總數及屬性列表（經自然排序）。
  2. **Magento 2.4.7 REST API 查詢結果**  
     - 顯示 Attribute Set ID（9 與 10）、屬性總數及屬性列表（經自然排序）。
  3. **Attribute Set 比較結果**  
     - 顯示 Magento 1 與 Magento 2 各屬性集的對照，包含各自屬性總數、屬性列表、以及差集結果（分別列出 M1 有但 M2 沒有與 M2 有但 M1 沒有的屬性）。

---

## 總結

此程式主要目的在於比對 Magento 1.9 與 Magento 2.4.7 之間指定 Attribute Set 的屬性清單差異，讓團隊可以輕鬆掌握兩個系統在屬性配置上的一致性與差異。藉由以下步驟達成：

- 從 Magento 1 資料庫讀取指定屬性集的屬性清單。
- 使用 Magento 2 REST API 取得對應屬性集的屬性清單。
- 根據映射關係進行比對，找出各自的缺漏項目。
- 以 HTML 表格方式輸出比較結果，方便檢視與後續調整。

透過此比較工具，可以協助確認 Magento 1 與 Magento 2 之間屬性轉換的正確性，並為後續資料同步或遷移工作提供依據。
