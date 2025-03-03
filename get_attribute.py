import json
import requests
from lxml import etree
from io import BytesIO
import zeep
from zeep import Settings
from zeep.helpers import serialize_object
import pprint
import json

# 嘗試使用 tabulate 套件格式化表格輸出
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
    若無則在 <wsdl:types> 區塊中加入 dummy 定義，
    返回修改後的 WSDL bytes。
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

def clean_text(text, prefix):
    """移除指定前綴，不分大小寫"""
    if text and text.lower().startswith(prefix.lower()):
        return text[len(prefix):].strip()
    return text.strip() if text else ""

def parse_attribute_set_list(attr_sets_raw):
    """
    解析 Attribute Set 列表資料，並移除 set_id 與 name 文字中的前綴。
    頂層 attr_sets_raw 為 OrderedDict，其 '_value_1' 包含真正的資料列表。
    每筆資料預期為 OrderedDict，唯一鍵為 '_value_1'，
    該鍵的值為一個列表，內部通常只有一個 OrderedDict，其 '_value_1' 為包含 XML Elements 的列表：
    第一個 XML Element 的文字為 set_id（如 "set_id4"），第二個為 set_name（如 "nameDefault"）。
    """
    valid_attr_sets = []
    if isinstance(attr_sets_raw, dict) and '_value_1' in attr_sets_raw:
        raw_list = attr_sets_raw['_value_1']
    else:
        print("回傳資料格式不符，無法找到 _value_1")
        return valid_attr_sets

    print("\n【除錯資訊】Raw Attribute Set Data:")
    pprint.pprint(raw_list)
    print("-" * 40)

    for idx, item in enumerate(raw_list, start=1):
        if isinstance(item, dict) and '_value_1' in item:
            inner = item['_value_1']
            if isinstance(inner, list) and len(inner) > 0:
                # 若第一個元素為 OrderedDict且有 '_value_1'，取其值作為 elems
                if isinstance(inner[0], dict) and '_value_1' in inner[0]:
                    elems = inner[0]['_value_1']
                else:
                    elems = inner
                print(f"資料 {idx} 的 elems raw:")
                for e in elems:
                    if isinstance(e, etree._Element):
                        text_val = etree.tostring(e, method='text', encoding='unicode').strip()
                        print(f"  tag: {e.tag}, text: {text_val}")
                    else:
                        print(e)
                if isinstance(elems, list) and len(elems) >= 2:
                    raw_set_id = etree.tostring(elems[0], method="text", encoding="unicode").strip()
                    raw_set_name = etree.tostring(elems[1], method="text", encoding="unicode").strip()
                    # 移除前綴 "set_id" 與 "name"
                    set_id = clean_text(raw_set_id, "set_id")
                    set_name = clean_text(raw_set_name, "name")
                    valid_attr_sets.append({"set_id": set_id, "name": set_name})
                else:
                    print(f"資料 {idx} 的 elems 長度不足：", elems)
            else:
                print(f"資料 {idx} 的 inner 不是列表或空：", inner)
        else:
            print(f"跳過無效的 Attribute Set 項目: {item}")
    return valid_attr_sets

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
        raw_attr_sets = client.service.call(session, "catalog_product_attribute_set.list", [])
        attr_sets_raw = serialize_object(raw_attr_sets)
    except Exception as e:
        print("取得 Attribute Set 列表時發生錯誤:", e)
        return

    valid_attr_sets = parse_attribute_set_list(attr_sets_raw)
    if not valid_attr_sets:
        print("沒有取得任何有效的 Attribute Set 資料。")
        return

    # 輸出基本 Attribute Set 資訊表格
    headers = ["Attribute Set ID", "Attribute Set Name"]
    table_data = [[attr.get("set_id"), attr.get("name")] for attr in valid_attr_sets]
    
    print("\nAttribute Sets:")
    if use_tabulate:
        from tabulate import tabulate
        print(tabulate(table_data, headers=headers, tablefmt="grid"))
    else:
        print(f"{headers[0]:<20} {headers[1]:<40}")
        for row in table_data:
            print(f"{row[0]:<20} {row[1]:<40}")

    # 取得詳細資訊（如果 API 正確設定此路徑）
    print("\nAttribute Set 詳細資訊:")
    for attr in valid_attr_sets:
        set_id = attr.get("set_id")
        set_name = attr.get("name")
        try:
            # 注意：如果 API 路徑或參數格式有問題，此處可能需要調整
            info = client.service.call(session, "catalog_product_attribute_set.info", [int(set_id)])
            info_data = serialize_object(info)
            print(f"\nAttribute Set {set_id} - {set_name} 詳細資訊:")
            print(json.dumps(info_data, indent=4, ensure_ascii=False))
        except Exception as e:
            print(f"取得 Attribute Set {set_id} 詳細資料時發生錯誤: {e}")

    try:
        client.service.endSession(session)
    except Exception as e:
        print("結束 Session 發生錯誤:", e)

if __name__ == '__main__':
    main()
