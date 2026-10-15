"""Read-only checks of the published site, optionally using its authoritative IP."""
import argparse, concurrent.futures, http.client, json, socket, ssl
from html.parser import HTMLParser
from urllib.parse import urlsplit
args=argparse.ArgumentParser();args.add_argument('origin');args.add_argument('--ip');options=args.parse_args()
origin=urlsplit(options.origin)
def fetch(path,method='GET',headers=None):
 cls=http.client.HTTPSConnection if origin.scheme=='https' else http.client.HTTPConnection
 c=cls(origin.hostname,port=origin.port,timeout=25)
 if options.ip:
  c._create_connection=lambda address,timeout=None,source_address=None:socket.create_connection((options.ip,address[1]),timeout,source_address)
 c.request(method,path,headers=headers or {});r=c.getresponse();body=r.read();meta=dict((k.lower(),v) for k,v in r.getheaders());status=r.status;c.close();return status,meta,body
class Links(HTMLParser):
 def __init__(self):super().__init__();self.resources=set()
 def handle_starttag(self,tag,attrs):
  for key,value in attrs:
   if value and value.startswith('/') and (key in ('src','poster') or key=='href' and value.startswith(('/assets/','/uploads/'))):self.resources.add(value)
resources=set();pages=[]
for path in ['/','/catalog/','/about/','/gallery/','/documents/','/reviews/','/contacts/','/privacy/']:
 status,headers,body=fetch(path);assert status==200,(path,status);text=body.decode();assert 'index,follow,max-image-preview:large' in text,path
 assert '<link rel="canonical" href="https://neverlimonad.ru' in text,path
 assert 'Fatal error' not in text and 'Warning:' not in text,path
 parser=Links();parser.feed(text);resources|=parser.resources;pages.append(path)
 if path=='/catalog/':assert text.count('class="product-card"')==13
 if path=='/gallery/':assert text.count('data-gallery-photo')==34
for path,needle in [('/robots.txt',b'Allow: /'),('/sitemap.xml',b'https://neverlimonad.ru/')]:
 status,_,body=fetch(path);assert status==200 and needle in body
for path in ['/cms/seed.json','/.env','/.env.local','/.git/config','/unknown-page/']:
 status,_,_=fetch(path);assert status in ([403,404,405] if path!='/unknown-page/' else [404]),(path,status)
status,headers,_=fetch('/admin/');assert status in [200,302]
if origin.scheme=='https':
 assert status==200 and 'noindex' in headers.get('x-robots-tag','') and headers.get('cache-control')=='no-store'
 status,_,body=fetch('/admin/api.php?action=session');assert status==200 and json.loads(body)['setup_required']
 status,_,_=fetch('/admin/api.php?action=state');assert status==401
status,headers,_=fetch('/assets/workshop.mp4',headers={'Range':'bytes=0-1023'});assert status==206 and headers.get('content-range','').startswith('bytes 0-1023/')
def resource(path):
 status,_,_=fetch(path,'HEAD');assert status==200,(path,status);return path
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:list(pool.map(resource,resources))
print(json.dumps({'origin':options.origin,'pages':len(pages),'resources':len(resources),'status':'PASS','robots':'indexable; admin excluded','video_range':206,'private_paths':'403/404/405','catalog':13,'album':34},ensure_ascii=False))
