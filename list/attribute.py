import json
import requests
from io import BytesIO
import zeep
from zeep import Settings
from zeep.helpers import serialize_object
from collections import OrderedDict

def load_config(config_file='config.json'):
    try:
        with open(config_file, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        print(f"讀取設定檔失敗: {e}")
        return None

def fetch_modified_wsdl(wsdl_url):
    try:
        response = requests.get(wsdl_url)
        response.raise_for_status()
        wsdl_xml = response.content
        from lxml import etree
        root = etree.fromstring(wsdl_xml)
        ns = {'wsdl': 'http://schemas.xmlsoap.org/wsdl/', 
              'xsd': 'http://www.w3.org/2001/XMLSchema'}
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
    config = load_config()
    if not config:
        return
    magento_domain = config.get('magento_domain')
    api_user = config.get('api_user')
    api_key = config.get('api_key')

    wsdl_url = f"{magento_domain}/api/v2_soap/?wsdl"
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

    # 取得所有屬性集合
    try:
        raw_attr_set_list = client.service.catalogProductAttributeSetList(session)
        attr_set_list = serialize_object(raw_attr_set_list)
    except Exception as e:
        print("取得屬性集合列表時發生錯誤:", e)
        return

    print(f"\n總共取得 {len(attr_set_list)} 個屬性集合")
    # 用來儲存所有屬性，利用屬性 code 去重複
    all_attributes = {}
    for attr_set in attr_set_list:
        set_id = attr_set.get("set_id")
        set_name = attr_set.get("name")
        print(f"處理屬性集合 {set_name} (ID: {set_id})")
        try:
            raw_attr_list = client.service.catalogProductAttributeList(session, set_id)
            attr_list = serialize_object(raw_attr_list)
        except Exception as e:
            print(f"取得屬性集合 {set_name} 時發生錯誤:", e)
            continue

        if not isinstance(attr_list, list):
            print(f"屬性集合 {set_name} 回傳格式不正確")
            continue

        for attr in attr_list:
            code = attr.get("code")
            if code and code not in all_attributes:
                all_attributes[code] = attr

    total_all = len(all_attributes)
    print(f"\n合併後共取得 {total_all} 筆不同屬性資料")

    # 顯示部分欄位
    headers = ["attribute_id", "code", "type", "required", "scope"]
    print("\n完整屬性列表:")
    print(f"{headers[0]:<15} {headers[1]:<25} {headers[2]:<15} {headers[3]:<10} {headers[4]:<10}")
    for attr in all_attributes.values():
        row0 = str(attr.get("attribute_id", ""))
        row1 = str(attr.get("code", ""))
        row2 = str(attr.get("type", ""))
        row3 = str(attr.get("required", ""))
        row4 = str(attr.get("scope", ""))
        print(f"{row0:<15} {row1:<25} {row2:<15} {row3:<10} {row4:<10}")

    # 在此處，你還可以加入額外邏輯，針對那些未綁定任何屬性集合的屬性進行處理，
    # 但這通常需要透過資料庫查詢 eav_attribute 表來補足。

    try:
        client.service.endSession(session)
    except Exception as e:
        print("結束 Session 發生錯誤:", e)

if __name__ == '__main__':
    main()
