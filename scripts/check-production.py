"""Integration check against an isolated disposable CMS instance; never uses live data."""
import copy, hashlib, http.cookiejar, json, os, pathlib, secrets, socket, subprocess, tempfile, time, urllib.error, urllib.request
root=pathlib.Path(__file__).resolve().parents[1]
build=root/'build/production'
with tempfile.TemporaryDirectory(prefix='nl-cms-check-') as tmp:
    env=dict(os.environ,NL_DATA_DIR=tmp+'/data')
    token=secrets.token_hex(32)
    subprocess.run(['php',str(build/'cms/install.php'),hashlib.sha256(token.encode()).hexdigest()],env=env,check=True,stdout=subprocess.DEVNULL)
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    log=open(tmp+'/server.log','w+')
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(build),str(build/'router.php')],env=env,stdout=log,stderr=log)
    base=f'http://127.0.0.1:{port}'
    cookies=http.cookiejar.CookieJar(); client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
    def req(path,body=None,csrf=None,status=200,raw=False,headers=None):
        h=dict(headers or {})
        if csrf: h['X-CSRF-Token']=csrf
        if isinstance(body,dict):body=json.dumps(body).encode();h['Content-Type']='application/json'
        try:r=client.open(urllib.request.Request(base+path,body,headers=h))
        except urllib.error.HTTPError as ex:r=ex
        content=r.read()
        assert r.status==status,(path,r.status,content[:500])
        return (content,r.headers) if raw else json.loads(content)
    uploaded_path=None
    try:
        for i in range(30):
            try: req('/admin/api.php?action=session');break
            except OSError:time.sleep(.1)
        session=req('/admin/api.php?action=session');csrf=session['csrf']
        assert session['setup_required'] and not session['user']
        req('/admin/api.php?action=state',status=401)
        req('/admin/api.php?action=setup',{'token':token,'username':'qa-admin','password':secrets.token_urlsafe(24)},status=403)
        account=req('/admin/api.php?action=setup',{'token':token,'username':'qa-admin','password':secrets.token_urlsafe(24)},csrf);csrf=account['csrf']
        assert not pathlib.Path(tmp+'/data/setup.json').exists()
        records=req('/admin/api.php?action=state')['records'];assert len(records)==15
        for name in records:
            value=records[name]['value']
            saved=req('/admin/api.php?action=save',{'key':name,'value':value,'revision':records[name]['revision']},csrf)
            records[name]=dict(value=saved['value'],revision=saved['revision'])
        page=copy.deepcopy(records['page:home']);fid=next(iter(page['value']['fields']))
        page['value']['fields'][fid]['html']='Проверка <strong>редактора</strong><script>alert(1)</script><img src=x onerror=alert(2)>'
        page['value']['seo']['title']='Проверка SEO'
        saved=req('/admin/api.php?action=save',dict(key='page:home',**page),csrf)
        assert '<script' not in saved['value']['fields'][fid]['html'] and 'onerror' not in saved['value']['fields'][fid]['html']
        home,h=req('/',raw=True);home=home.decode()
        assert '<title>Проверка SEO</title>' in home and 'Проверка <strong>редактора</strong>' in home
        req('/admin/api.php?action=save',dict(key='page:home',**page),csrf,status=409)
        history=req('/admin/api.php?action=history&key=page%3Ahome')['history']
        req('/admin/api.php?action=restore',{'key':'page:home','id':history[0]['id'],'revision':saved['revision']},csrf)
        products=copy.deepcopy(records['products']);products['value'].append(dict(id='qa-product',name='Проверка напитка',series='sweetener',tag='Тест',desc='Проверка',pack='Тест',storage='Тест',photo='/assets/logo.svg',featured=True))
        req('/admin/api.php?action=save',dict(key='products',**products),csrf)
        catalog,_=req('/catalog/',raw=True);assert b'qa-product' in catalog
        # Multipart file upload: valid small PNG, then invalid executable.
        import base64
        png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        def upload(name,mime,payload,status=200):
            boundary='test-'+secrets.token_hex(12)
            body=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+payload+f'\r\n--{boundary}--\r\n'.encode())
            return req('/admin/api.php?action=upload',body,csrf,status=status,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
        uploaded=upload('qa.png','image/png',png)['file'];uploaded_path=build/uploaded['path'].lstrip('/');assert uploaded['path'].endswith('.png')
        filedata,_=req(uploaded['path'],raw=True);assert filedata==png
        upload('bad.php','application/x-php',b'<?php echo "bad";',400)
        gallery=copy.deepcopy(records['gallery']);gallery['value'].append(dict(id='qa-image',photo=uploaded['path'],thumbnail='',caption='Проверка нового фото',source_url=''))
        req('/admin/api.php?action=save',dict(key='gallery',**gallery),csrf)
        galleryhtml,_=req('/gallery/',raw=True);assert uploaded['path'].encode() in galleryhtml
        for path in ['/','/catalog/','/about/','/gallery/','/documents/','/reviews/','/contacts/','/privacy/']:
            html,headers=req(path,raw=True);text=html.decode();assert '<meta name="robots" content="index,follow' in text,path
            assert '<link rel="canonical" href="https://neverlimonad.ru' in text,path
            assert 'Warning:' not in text and 'Fatal error' not in text,path
        robots,_=req('/robots.txt',raw=True);assert b'Allow: /' in robots and b'Disallow: /admin/' in robots
        sitemap,_=req('/sitemap.xml',raw=True);assert sitemap.count(b'<loc>')==8
        for path in ['/cms/seed.json','/.env','/.git/config','/missing/']:
            req(path,status=404,raw=True)
        _,headers=req('/admin/',raw=True);assert 'noindex' in headers.get('X-Robots-Tag','') and headers.get('Cache-Control')=='no-store'
        exported=req('/admin/api.php?action=export');assert 'password_hash' not in json.dumps(exported)
        req('/admin/api.php?action=logout',{},csrf);req('/admin/api.php?action=state',status=401)
        print('PASS: setup, login session, CSRF, all 15 records, safe rich text, revision conflict/restore, product/gallery CRUD, uploads, 8 pages, SEO, sitemap, robots and private paths.')
    finally:
        server.terminate();server.wait();log.close()
        if uploaded_path and uploaded_path.exists():uploaded_path.unlink()
