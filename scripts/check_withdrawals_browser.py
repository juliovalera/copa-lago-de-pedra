"""Checks withdrawal report UI/PDF with isolated fixtures from audit_http.py."""
import base64
import functools
import http.server
import json
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import threading
import time
from urllib.request import urlopen
from websockets.sync.client import connect
import fitz

ROOT=Path(__file__).resolve().parents[1]
class Handler(http.server.SimpleHTTPRequestHandler):
    def do_GET(self):
        if self.path.startswith('/previews/vendor/'):
            self.path=self.path.replace('/previews/vendor/','/painel_php/public/vendor/',1)
        return super().do_GET()
    def log_message(self,*args): pass

server=http.server.ThreadingHTTPServer(('127.0.0.1',0),functools.partial(Handler,directory=str(ROOT)))
threading.Thread(target=server.serve_forever,daemon=True).start()
base=f'http://127.0.0.1:{server.server_port}'
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
process=subprocess.Popen([r'C:\Program Files\Google\Chrome\Application\chrome.exe','--headless=new','--disable-gpu','--no-first-run',f'--remote-debugging-port={port}','--user-data-dir='+tempfile.mkdtemp(prefix='copa-export-browser-'),'about:blank'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
try:
    for _ in range(100):
        try: pages=json.load(urlopen(f'http://127.0.0.1:{port}/json',timeout=1));break
        except Exception: time.sleep(.1)
    with connect(next(p for p in pages if p.get('type')=='page')['webSocketDebuggerUrl'],max_size=20*1024*1024) as ws:
        seq=0
        def call(method,params=None):
            global seq
            seq+=1; ws.send(json.dumps(dict(id=seq,method=method,params=params or {})))
            while True:
                result=json.loads(ws.recv(timeout=60))
                if result.get('id')==seq:
                    assert 'error' not in result,result
                    return result.get('result',{})
        def js(expression):
            result=call('Runtime.evaluate',dict(expression=expression,returnByValue=True,awaitPromise=True))
            assert 'exceptionDetails' not in result,result
            return result['result'].get('value')
        call('Page.enable')
        call('Browser.setDownloadBehavior',dict(behavior='deny'))
        html=(ROOT/'previews/withdrawal-preview.html').read_text(encoding='utf-8').replace('<head>','<head><base href="/painel_php/public/">').replace('../painel_php/public/','/painel_php/public/')
        (ROOT/'previews/withdrawal-browser.html').write_text(html,encoding='utf-8')
        call('Page.navigate',dict(url=base+'/previews/withdrawal-browser.html'))
        time.sleep(1)
        for width in [320,390,768,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js('document.documentElement.scrollWidth<=innerWidth'),width
        call('Emulation.setDeviceMetricsOverride',dict(width=390,height=900,deviceScaleFactor=1,mobile=False))
        (ROOT/'previews/withdrawal-mobile.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
        js("window.originalFetch=fetch;window.fetch=(url,opts)=>String(url).includes('desistencias.php')?originalFetch('/previews/withdrawal-report.json'):originalFetch(url,opts);document.querySelector('.export-form').requestSubmit()")
        for _ in range(180):
            if not js("document.querySelector('.export-form button[type=submit]').disabled"):break
            time.sleep(.25)
        assert js("!document.querySelector('.export-ready').hidden"),js("document.querySelector('.export-status').textContent")
        encoded=js("(async()=>{let b=await (await originalFetch(document.querySelector('.export-ready').href)).blob();return await new Promise(r=>{let f=new FileReader();f.onload=()=>r(f.result.split(',')[1]);f.readAsDataURL(b)});})()")
        raw=base64.b64decode(encoded);(ROOT/'previews/withdrawal.pdf').write_bytes(raw)
        pdf=fitz.open(stream=raw,filetype='pdf');text=''.join(page.get_text() for page in pdf)
        assert 'Antes' in text and 'Depois' in text and 'Fictional decision' in text
        assert len(pdf[0].get_images())>=2 and len(pdf)>2
        pdf[0].get_pixmap(matrix=fitz.Matrix(1,1)).save(str(ROOT/'previews/withdrawal-pdf.png'))
        print('OK: private withdrawal report responsive 320/390/768/1280, actual generated PDF, before/after, logos and multipage original games')

finally:
    process.terminate();process.wait(timeout=10);server.shutdown()
