# Magento Migration Project - Setting and Introduction

本文件提供 Magento 1.9 到 Magento 2.4.7 資料遷移的整體介紹，包含基本環境設定說明與從第 1 步驟到第 5 步驟的流程概述。請依據以下說明設定相關環境，並依步驟依序執行。

---

## 系統設定說明

以下為 `config.json` 的設定範例，請依實際環境填入各項參數：

```json
{
  "magento_domain": "https://magento19_domain.com/",
  "api_user": "",
  "api_key": "",
  "magento2_domain": "https://magento247_domain.com/",
  "api_key2": "",
  "rest_endpoint": "/rest/all/V1",
  "db_host": "",
  "db_name": "",
  "db_user": "",
  "db_password": "",
  "db_host_m2": "",
  "db_name_m2": "",
  "db_user_m2": "",
  "db_password_m2": ""
}
```

在開始遷移之前，請先建立一份設定檔，用於儲存以下重要參數：

- **Magento 1.9 網域**：設定 Magento 1 的基本網址。
- **Magento 1 API 認證資訊**：包含 API 使用者名稱與 API 金鑰，供 Magento 1 SOAP API 使用。
- **Magento 2.4.7 網域**：設定 Magento 2 的基本網址。
- **Magento 2 REST API 端點**：例如 `/rest/all/V1`。
- **Magento 2 API 認證資訊**：通常為管理員帳號與密鑰，用於取得 Admin Token。
- **Magento 1 資料庫連線資訊**：包含資料庫主機、資料庫名稱、使用者與密碼。
- **Magento 2 資料庫連線資訊**：若部分屬性轉換需要，需提供 Magento 2 資料庫的連線資訊。

請依據您的環境將上述各項參數正確填入設定檔中，以便後續各步驟程式能夠正常運作。

---

## 遷移流程概述

本次遷移共分為五個主要步驟，各步驟說明如下：

### 步驟 1：建立 Category Tree
- **目標**: 將 Magento 1.9 指定的分類（例如 MioWORK™, MioCARE™, MioEYE™, MioServ™）及其子分類，遷移至 Magento 2.4.7。
- **流程**:
  - 利用 Magento 1 SOAP API 取得完整分類樹。
  - 遞迴搜尋目標分類，並利用 Magento 2 REST API 建立對應分類，同時保留原有的樹狀結構。
  - 每次建立分類時會輸出 API 請求的 Payload 與回應，方便除錯。

### 步驟 2：Category Attribute Transfer
- **目標**: 將 Magento 1.9 各分類的屬性數值同步更新至 Magento 2。
- **流程**:
  - 從 Magento 1 資料庫撈取分類屬性與數值（不過濾系統內建屬性）。
  - 根據先前遷移建立的 (m1_category_id => m2_category_id) 對照表，透過 Magento 2 REST API 更新對應分類的自訂屬性。
  - 詳細的 API 請求與回應會在程式中輸出，便於追蹤狀況。

### 步驟 3：Attribute Official Transfer
- **目標**: 將 Magento 1 的產品屬性定義（包含屬性群組、屬性選項等）遷移至 Magento 2。
- **流程**:
  - 從 Magento 1 SOAP API 與資料庫讀取屬性組與屬性資料，補足必要欄位（如 default_value、apply_to、frontend_labels、validation_rules 等）。
  - 利用 Magento 2 REST API 建立屬性群組與屬性；若屬性已存在或被系統保留，則透過搜尋取得現有資料，必要時進行更新。
  - 最後根據 attribute_code 排序，依序將屬性指派至對應的屬性群組。

### 步驟 4：Product Official Transfer
- **目標**: 將 Magento 1.9 的產品資料轉換為符合 Magento 2.4.7 REST API 所需的 payload 格式，並上傳至 Magento 2。
- **流程**:
  - 整合 Magento 1 SOAP API 與資料庫查詢取得產品詳細資料，並根據映射表轉換 attribute_set_id 與 category_links。
  - 處理產品圖片：將圖片相對路徑組合為完整 URL，讀取圖片後轉為 base64 格式，讀取失敗的圖片會被記錄但不納入 payload。
  - 處理 custom_attributes，對於特定選單型屬性，根據 Magento 1 與 Magento 2 的對應關係進行 option_id 轉換（僅取 store_id 為 0 的值）。
  - 將轉換後的產品 payload 依序透過 Magento 2 REST API POST 上傳，並在網頁上即時輸出每筆請求的 Payload 與回應資訊。

### 步驟 5：Attribute Set 比較
- **目標**: 比較 Magento 1.9 與 Magento 2.4.7 中指定 Attribute Set 的屬性清單，找出兩邊的差異。
- **流程**:
  - 從 Magento 1 資料庫讀取 Attribute Set（例如 ID 22 與 23）的屬性清單。
  - 透過 Magento 2 REST API 取得對應 Attribute Set（例如 ID 10 與 9）的屬性清單。
  - 根據映射關係（例如 M1 的 22 對應 M2 的 10，M1 的 23 對應 M2 的 9），計算出在 Magento 1 有但 Magento 2 沒有的屬性，以及在 Magento 2 有但 Magento 1 沒有的屬性。
  - 將結果以 HTML 表格形式輸出，便於檢視與後續調整。

---

## 總結

本專案的資料遷移流程共分為 5 個主要步驟：
1. 建立 Category Tree
2. Category Attribute Transfer
3. Attribute Official Transfer
4. Product Official Transfer
5. Attribute Set 比較

每個步驟都結合了 API 呼叫、資料庫查詢與資料轉換技術，並提供詳細的除錯與回應輸出，方便確認各個環節的運作狀況。請依據本文件進行環境設定與流程執行，以確保從 Magento 1.9 到 Magento 2.4.7 的資料遷移順利進行。
