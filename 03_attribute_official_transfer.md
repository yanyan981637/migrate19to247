# attribute_official_transfer

此程式用於將 Magento1 的產品屬性資料完整遷移至 Magento2，並進行屬性群組與屬性的建立與指派。整個遷移過程除了建立屬性群組與屬性外，還處理了重複、系統保留以及可見性等狀況，確保最終遷移至 Magento2 的屬性資料正確且完整。

## 映射對照

- Magento1 attribute set id **23** → Magento2 attribute set id **9**
- Magento1 attribute set id **22** → Magento2 attribute set id **10**

## 程式流程

1. **取得 Magento2 Token**
   - 透過 Magento2 REST API (`/rest/all/V1/integration/admin/token`) 使用管理員帳密取得 API token，此 token 用於後續的 REST API 請求認證。

2. **讀取 Magento1 資料來源**
   - 從 `config.json` 中讀取 Magento1 與 Magento2 的設定，包括 Magento1 的 SOAP API 與資料庫連線資訊，以及 Magento2 的 REST API 網域與端點。
   - 建立 Magento1 SOAP API 連線（SOAP V1）以及透過 PDO 建立與 Magento1 資料庫的連線。

3. **撈取 Magento1 Attribute Group 與 Attribute 資料**
   - 使用 SQL 查詢從 `eav_attribute_group` 表中取得指定 attribute set 的群組資料。
   - 從 `eav_attribute`、`catalog_eav_attribute` 與 `eav_entity_attribute` 等表中撈取屬性基本資料，並透過 Magento1 SOAP API (`product_attribute.info`) 補足其他欄位資訊。
   - 進一步取得每個屬性的選項資料（options）以及 frontend_label（從資料庫讀取），並組成符合 Magento2 格式的 `frontend_labels` 陣列。
   - 其他欄位（如 default_value、apply_to、validation_rules、custom_attributes）也會一起處理。

4. **建立 Magento2 Attribute Group**
   - 以 POST 請求（`/products/attribute-sets/groups`）建立 Magento2 屬性群組。
   - 若建立失敗（例如訊息中包含 "already exists" 或 "can't be saved"），則使用 GET 請求搜尋現有群組並取得 group id。

5. **建立 Magento2 Attribute**
   - 利用 POST 請求（`/products/attributes`）建立屬性。
   - 注意事項：
     - `frontend_input` 直接採用 Magento1 的值；若無值則預設為 `"text"`，且若屬性 code 為 `"weight"`，強制設定為 `"text"`。
     - `frontend_labels` 根據 Magento1 讀取到的 `frontend_label` 組成，格式為 `[ { "store_id": 0, "label": <值> } ]`，若無值則傳入空陣列。
     - 若 `default_frontend_label` 為空，則使用格式化後的 attribute code（將底線轉成空白並將每個單字首字母大寫）作為預設值。
     - 對於 `options`，移除原本的 `option_id`，新增 `label` 欄位，其值與 `value` 相同。
   - 如果建立屬性失敗，且錯誤訊息包含 "already exists" 或 "reserved by system"，則使用 GET 搜尋取得該屬性。
     - 若搜尋結果中 `is_visible` 為 `false`，則調整該屬性的 `is_visible` 為 `true`，並更新 `default_frontend_label`，再使用 PUT 請求更新屬性。

6. **指派 Attribute 至群組**
   - 將每個屬性指派到前面建立的 Magento2 attribute group（使用 POST `/products/attribute-sets/attributes`）。
   - 屬性依據 `attribute_code` 進行升冪排序，並根據資料中的 `sort_order` 更新指派時的 `sortOrder`（從 1 開始）。

## 輔助功能與注意事項

- **格式化輔助函式**
  - `formatAttributeCode`：將 attribute code 中的底線轉成空白並將每個單字首字母大寫，作為預設的 `default_frontend_label`。

- **API 呼叫封裝**
  - 使用 `callMagento2Api` 函式統一處理 Magento2 REST API 請求，支援 GET、POST、PUT 等 HTTP 方法，並統一輸出除錯資訊（包含請求的 URL、Payload 與回應）。

- **錯誤處理**
  - 程式在建立 attribute group 與 attribute 時都會檢查回應結果，並針對已存在或系統保留的狀況進行額外處理（例如透過搜尋取得現有資料，再進行更新）。
  - 若發生 cURL 錯誤則直接中斷程式並輸出錯誤訊息。

- **效能與除錯**
  - 為避免執行時間過長，使用 `set_time_limit(0)`。
  - 透過 `ini_set('display_errors', 1)` 與 `error_reporting(E_ALL)` 顯示所有錯誤訊息，方便除錯。

## 總結

此腳本整合了 Magento1 的 SOAP API 與資料庫查詢，完整撈取產品屬性及其群組資訊，並透過 Magento2 的 REST API 進行以下動作：

- 建立或搜尋 Magento2 屬性群組
- 建立或更新 Magento2 屬性（處理已存在、不可見等情況）
- 將屬性指派到對應的屬性群組並更新排序

最終達成從 Magento1 遷移產品屬性到 Magento2 的目的，確保遷移後屬性資料完整且符合 Magento2 的格式要求。
