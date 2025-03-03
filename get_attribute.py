import json
import requests
from lxml import etree
from io import BytesIO
import zeep
from zeep import Settings
try:
    from tabulate import tabulate
    use_tabulate = True
except ImportError:
    use_tabulate = False

def load_config(config_file='config.json'):
    """讀取外部設定檔"""
    try:
        with open(config_file, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        print(f"讀取設定檔失敗: {e}")
        return None

def fetch_modified_wsdl(wsdl_url):
    """
    下載 WSDL，檢查是否已定義 {http://xml.apache.org/xml-soap}Map，
    若無則在 <wsdl:types> 區塊中加入 dummy 定義，返回修改後的 WSDL bytes。
    """
    try:
        response = requests.get(wsdl_url)
        response.raise_for_status()
        wsdl_xml = response.content
        root = etree.fromstring(wsdl_xml)
        ns = {
            'wsdl': 'http://schemas.xmlsoap.org/wsdl/',
            'xsd': 'http://www.w3.org/2001/XMLSchema'
        }
        types_elem = root.find("wsdl:types", ns)
        if types_elem is None:
            types_elem = etree.Element("{http://schemas.xmlsoap.org/wsdl/}types")
            root.insert(0, types_elem)
        found = False
        for schema in types_elem.findall("xsd:schema", ns):
            if schema.get("targetNamespace") == "http://xml.apache.org/xml-soap":
                found = True
                break
        if not found:
            dummy_schema_str = '''
            <schema xmlns="http://www.w3.org/2001/XMLSchema" targetNamespace="http://xml.apache.org/xml-soap">
                <complexType name="Map">
                    <sequence>
                        <any processContents="lax" minOccurs="0" maxOccurs="unbounded"/>
                    </sequence>
                </complexType>
            </schema>
            '''
            dummy_schema = etree.fromstring(dummy_schema_str.encode("utf-8"))
            types_elem.append(dummy_schema)
        modified_wsdl = etree.tostring(root)
        return modified_wsdl
    except Exception as e:
        print(f"下載或修改 WSDL 時發生錯誤: {e}")
        return None

def main():
    # 讀取設定檔
    config = load_config()
    if not config:
        return

    magento_domain = config.get('magento_domain')
    api_user = config.get('api_user')
    api_key = config.get('api_key')

    wsdl_url = f"{magento_domain}/api/soap/?wsdl=2"
    modified_wsdl = fetch_modified_wsdl(wsdl_url)
    if not modified_wsdl:
        print("無法取得或修改 WSDL")
        return

    # 將修改後的 WSDL 以 BytesIO 物件傳給 Zeep
    wsdl_file = BytesIO(modified_wsdl)
    settings = Settings(strict=False, xml_huge_tree=True)
    client = zeep.Client(wsdl=wsdl_file, settings=settings)

    try:
        session = client.service.login(api_user, api_key)
        print("取得 Session ID:", session)
    except Exception as e:
        print("登入失敗:", e)
        return

    try:
        # 呼叫通用的 call() 方法取得屬性集合資料，資源路徑為 "catalog_product_attribute_set.list"
        attribute_sets = client.service.call(session, "catalog_product_attribute_set.list", [])
        # 印出 raw 結果 (除錯用)
        # print("Raw attribute_sets:", attribute_sets)
        
        # 整理資料
        headers = ["Attribute Set ID", "Attribute Set Name"]
        table_data = []
        for attr_set in attribute_sets:
            # 若 attr_set 為 dict 型態，則直接讀取 set_id 與 name
            if isinstance(attr_set, dict):
                set_id = attr_set.get("set_id", "")
                name = attr_set.get("name", "")
            # 若為其他型態（例如 str），則直接以該字串當作名稱，ID 留空
            else:
                set_id = ""
                name = str(attr_set)
            table_data.append([set_id, name])
        print("\nAttribute Sets:")
        if use_tabulate:
            print(tabulate(table_data, headers=headers, tablefmt="grid"))
        else:
            # 簡單表格輸出
            print(f"{headers[0]:<20} {headers[1]:<40}")
            for row in table_data:
                print(f"{row[0]:<20} {row[1]:<40}")
    except Exception as e:
        print("取得 Attribute Set 資料時發生錯誤:", e)
    finally:
        try:
            client.service.endSession(session)
        except Exception as e:
            print("結束 Session 發生錯誤:", e)

if __name__ == '__main__':
    main()
