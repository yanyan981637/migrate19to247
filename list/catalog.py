import json
import requests
from io import BytesIO
import zeep
from zeep import Settings
from zeep.helpers import serialize_object

def load_config(config_file='../config.json'):
    """
    讀取設定檔，預期內容包含 magento_domain、api_user 與 api_key
    """
    try:
        with open(config_file, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        print(f"讀取設定檔失敗: {e}")
        return None

def fetch_wsdl(wsdl_url):
    """
    下載 WSDL，直接返回原始 WSDL bytes（SOAP V1 端點）
    """
    try:
        response = requests.get(wsdl_url)
        response.raise_for_status()
        return response.content
    except Exception as e:
        print(f"下載 WSDL 時發生錯誤: {e}")
        return None

def main():
    config = load_config()
    if not config:
        return

    magento_domain = config.get('magento_domain')
    api_user = config.get('api_user')
    api_key = config.get('api_key')

    # SOAP V1 的 WSDL URL
    wsdl_url = f"{magento_domain}/api/soap/?wsdl"
    wsdl_bytes = fetch_wsdl(wsdl_url)
    if not wsdl_bytes:
        print("無法取得 WSDL")
        return

    wsdl_file = BytesIO(wsdl_bytes)
    settings = Settings(strict=False)
    client = zeep.Client(wsdl=wsdl_file, settings=settings)

    # 登入取得 Session ID
    try:
        session = client.service.login(api_user, api_key)
        print("取得 Session ID:", session)
    except Exception as e:
        print("登入失敗:", e)
        return

    # 嘗試查詢 store.list 以確認 store view 值（注意可能出現 dummy schema 警告）
    try:
        stores = client.service.call(session, 'store.list', [])
        stores = serialize_object(stores)
        print("\nStore List:")
        for store in stores:
            print(store)
    except Exception as e:
        print("取得 Store List 失敗:", e)

    # 依據您的 Magento 系統，嘗試不同的 parentId 值以找到有效的分類節點
    parent_ids_to_test = ["1", "2"]
    for pid in parent_ids_to_test:
        try:
            print(f"\n嘗試 parentId = {pid} 與 storeView = 'default'")
            # 傳入 args 為一個陣列，第一個元素為 parentId，第二個為 storeView
            tree = client.service.call(session, 'catalog_category.tree', [pid, "default"])
            tree = serialize_object(tree)
            if tree is None:
                print(f"parentId = {pid} 回傳 None")
            else:
                print(f"parentId = {pid} 成功取得分類樹")
                # 若需要可以進一步印出 tree 結構
                # print(tree)
        except Exception as e:
            print(f"使用 parentId = {pid} 時出現錯誤: {e}")

    # 若找到有效的 parentId（例如假設有效的是 "2"），則以此進行後續處理：
    valid_parent_id = None
    for pid in parent_ids_to_test:
        try:
            tree = client.service.call(session, 'catalog_category.tree', [pid, "default"])
            tree = serialize_object(tree)
            if tree is not None:
                valid_parent_id = pid
                break
        except Exception:
            continue

    if valid_parent_id is None:
        print("未找到有效的 parentId，請檢查 Magento 系統中分類結構。")
    else:
        print(f"\n採用有效的 parentId = {valid_parent_id} 進行後續查詢。")
        try:
            tree = client.service.call(session, 'catalog_category.tree', [valid_parent_id, "default"])
            tree = serialize_object(tree)
        except Exception as e:
            print("重新取得分類樹失敗:", e)
            tree = None

        if tree is not None:
            # 將分類樹平坦化為列表
            def flatten_tree(node):
                nodes = [node]
                if isinstance(node, dict) and 'children' in node and node['children']:
                    for child in node['children']:
                        nodes.extend(flatten_tree(child))
                return nodes

            categories = flatten_tree(tree)
            print(f"\n總共取得 {len(categories)} 個分類")
            
            # 呼叫 catalog_category.info 取得每個分類的詳細資訊
            cat_infos = []
            for cat in categories:
                cat_id = cat.get('category_id')
                try:
                    info = client.service.call(session, 'catalog_category.info', [cat_id, "default"])
                    info = serialize_object(info)
                    cat_infos.append(info)
                except Exception as e:
                    print(f"取得分類 {cat_id} 詳細資訊時出現錯誤: {e}")
            
            # 依 category_id 升冪排序（轉換成整數排序）
            cat_infos_sorted = sorted(cat_infos, key=lambda x: int(x.get('category_id', 0)))
            
            # 定義顯示欄位
            headers = [
                "category_id", "is_active", "position", "level", "parent_id",
                "name", "url_key", "description", "created_at", "updated_at",
                "children_count", "include_in_menu"
            ]
            print("\n分類資訊 (依 category_id 升冪排序):")
            print("{:<12} {:<10} {:<10} {:<8} {:<12} {:<20} {:<15} {:<30} {:<20} {:<20} {:<15} {:<15}".format(*headers))
            for info in cat_infos_sorted:
                row = [str(info.get(col, "")) for col in headers]
                print("{:<12} {:<10} {:<10} {:<8} {:<12} {:<20} {:<15} {:<30} {:<20} {:<20} {:<15} {:<15}".format(*row))
    
    try:
        client.service.endSession(session)
    except Exception as e:
        print("結束 Session 發生錯誤:", e)

if __name__ == '__main__':
    main()
