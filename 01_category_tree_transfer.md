# category_tree_transfer

本文檔說明了此 PHP 程式碼的功能與流程，主要用於將 Magento 1.9 上的特定分類及其所有子分類遷移至 Magento 2.4.7，並保留原有的樹狀結構。

## 1. 功能概述

- **目標分類：**  
  程式主要針對下列 Magento 1 分類進行遷移：  
  - MioWORK™ (ID: 80)  
  - MioCARE™ (ID: 81)  
  - MioEYE™ (ID: 141)  
  - MioServ™ (ID: 149)

- **遷移方式：**  
  保留原有的樹狀結構，並利用 Magento 2 REST API 建立分類。對於這四個目標分類，在 Magento 2 中其上層 `parent_id` 固定設定為 2。

- **除錯資訊：**  
  每次建立分類時，程式均會印出送出的 payload 與 API 的回應訊息，方便除錯與驗證。

## 2. 配置與環境設定

- **配置檔案 (`config.json`)：**  
  程式開始時讀取一個 `config.json` 檔案，此檔案包含 Magento 1 與 Magento 2 的相關設定與 API 金鑰，包括：
  - `magento_domain`：Magento 1 的網域。
  - `magento2_domain`：Magento 2 的網域。
  - `api_user`、`api_key`：Magento 1 API 使用者及密鑰。
  - `api_key2`：Magento 2 API 金鑰。
  - `rest_endpoint`：Magento 2 的 REST API 路徑（例如 `/rest/all/V1`）。

## 3. Magento 1 部分

- **SOAP 連線：**  
  使用 Magento 1 的 SOAP API 連線，組裝 WSDL URL 並進行認證。

- **取得分類樹：**  
  透過 `catalog_category.tree` 方法，取得 Magento 1 的完整分類樹。

## 4. 目標分類搜尋

- **遞迴搜尋：**  
  定義了 `findCategoriesByIds` 遞迴函式，遍歷分類樹以搜尋指定的目標分類 ID。若未找到任何目標分類，則程式將終止並顯示錯誤訊息。

## 5. Magento 2 部分

### 5.1 取得 Admin Token

- **函式 `getMagento2AdminToken`：**  
  使用 Magento 2 的 REST API 請求 `/integration/admin/token`，透過 POST 傳送管理員使用者名稱與密碼，獲取用於後續 API 認證的 Admin Token。

### 5.2 建立分類

- **函式 `createMagento2Category`：**  
  - **功能：**  
    將 Magento 1 的分類資料轉換成 Magento 2 所需格式，並呼叫 Magento 2 REST API 以建立新分類。
  
  - **資料轉換：**  
    組合 payload 時會處理以下欄位：
    - 基本欄位：`parent_id`（指定上層分類，對於目標分類固定為 2）、`name`、`is_active`、`position`、`include_in_menu`。
    - 自訂屬性：若 Magento 1 中存在 `url_key`、`description`、`meta_title`、`meta_keywords`、`meta_description`，則加入 `custom_attributes`。

  - **除錯輸出：**  
    印出送出的 JSON payload 與 REST API 回應，幫助開發者追蹤流程與錯誤。

### 5.3 遞迴遷移分類樹

- **函式 `migrateCategoryTree`：**  
  此遞迴函式負責：
  1. 呼叫 `createMagento2Category` 建立當前節點分類，並取得新分類的 Magento 2 ID。
  2. 若當前分類存在子分類，則對每個子分類重複遞迴呼叫，並以新建立分類的 ID 作為其上層 `parent_id`。

## 6. 執行流程

1. **讀取配置檔案：**  
   程式從 `config.json` 讀取所有必要設定，若配置檔不存在或格式錯誤則終止。

2. **Magento 2 Admin Token 取得：**  
   透過 API 請求獲取管理員 Token，並輸出該 Token 作為除錯資訊。

3. **Magento 1 SOAP 連線與分類樹取得：**  
   連線 Magento 1 並取得完整分類樹，然後利用遞迴搜尋找到目標分類。

4. **分類遷移：**  
   依序處理每一個目標分類，並以 `parent_id = 2` 為上層分類開始遞迴遷移：
   - 對每個分類建立對應的 Magento 2 分類。
   - 對於子分類，依循新建立分類的 ID 作為上層 ID，繼續建立子分類。

5. **除錯與回應輸出：**  
   在每個分類建立步驟中，印出 payload 與 API 回應，並根據回應訊息顯示建立成功或失敗。

6. **結束提示：**  
   當所有目標分類與其子分類處理完成後，印出「遷移完成」的訊息。

## 7. 錯誤處理

- 程式在多個關鍵步驟中加入了錯誤檢查：
  - 配置檔案讀取與 JSON 解析失敗時終止。
  - Magento 1 SOAP 連線、分類樹取得、以及 Magento 2 分類建立失敗時，均會輸出錯誤訊息並終止程式。

## 8. 總結

此程式碼是一個自動化工具，主要用於從 Magento 1.9 遷移指定分類（及其子分類）到 Magento 2.4.7。它透過 Magento 1 的 SOAP API 擷取分類資料，再利用 Magento 2 的 REST API 建立新分類，並保留原有的分類層級結構。程式中提供詳細的除錯輸出，使開發者能夠在遷移過程中追蹤每一步驟的狀態與可能的錯誤。
