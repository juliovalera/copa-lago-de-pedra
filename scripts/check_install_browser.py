"""Checks the installation UI without installing an OS application."""
import base64
import functools
import http.server
import json
from pathlib import Path
import socket
import subprocess
import tempfile
import threading
import time
from urllib.request import urlopen
from websockets.sync.client import connect

ROOT=Path(__file__).resolve().parents[1]
class Handler(http.server.SimpleHTTPRequestHandler):
    def handle(self):
        try:
            super().handle()
        except ConnectionResetError:
            pass  # Chrome can close outstanding asset requests during reload.
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
        import re
        php=subprocess.check_output([r'C:\php\php.exe','-n',str(ROOT/'painel_php/public/index.php')]).decode('utf-8')
        html=re.sub(r'<script(?![^>]*src="install.js)[^>]*>.*?</script>','',php,flags=re.S)
        html=html.replace('<head>','<head><base href="/painel_php/public/">').replace('asset.php?file=styles.css','/site/styles.css?unused=1')
        (ROOT/'previews/install-test.html').write_text(html,encoding='utf-8')
        call('Page.navigate',dict(url=base+'/previews/install-test.html'));time.sleep(2)
        assert js("!document.querySelector('#install-open').hidden && !document.querySelector('#install-dialog').open")
        js("document.querySelector('#install-open').click()")
        assert js("document.querySelector('#install-dialog').open")
        for width in [320,390,768,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js("document.querySelector('#install-dialog').scrollWidth<=document.querySelector('#install-dialog').clientWidth"),width
        js("document.querySelector('#install-close').click()")
        time.sleep(.1)
        assert js("document.activeElement.id==='install-open'")
        # Synthetic events exercise UI decisions; they do not install an OS application.
        js("window.installCalls=0;window.offer=outcome=>{let e=new Event('beforeinstallprompt',{cancelable:true});e.prompt=async()=>{installCalls++};e.userChoice=Promise.resolve({outcome});dispatchEvent(e)};offer('dismissed')")
        assert js('installCalls===0')
        js("document.querySelector('#install-open').click();document.querySelector('#install-confirm').click()")
        time.sleep(.1)
        assert js("installCalls===1 && document.querySelector('#install-status').textContent.includes('depois') && document.querySelector('#install-confirm').hidden")
        js("offer('accepted');document.querySelector('#install-confirm').click()")
        time.sleep(.1)
        assert js("installCalls===2 && document.querySelector('#install-status').textContent.includes('aceito')")
        js("dispatchEvent(new Event('appinstalled'))")
        assert js("document.querySelector('#install-banner').hidden && document.querySelector('#install-confirm').hidden")
        call('Network.setUserAgentOverride',dict(userAgent='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1'))
        call('Page.reload');time.sleep(1)
        js("document.querySelector('#install-open').click()")
        assert js("document.querySelector('#install-ios').open && !document.querySelector('#install-android').open")
        js("Object.defineProperty(navigator,'clipboard',{value:{writeText:async()=>{throw Error('denied')}},configurable:true});document.querySelector('#install-copy').click()")
        time.sleep(.1)
        assert js("document.activeElement.id==='install-address' && document.querySelector('#install-status').textContent.includes('selecionado')")
        call('Emulation.setDeviceMetricsOverride',dict(width=390,height=900,deviceScaleFactor=1,mobile=False))
        (ROOT/'previews/install-ios.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
        hook=call('Page.addScriptToEvaluateOnNewDocument',dict(source="Object.defineProperty(navigator,'standalone',{value:true})"))['identifier']
        call('Page.reload');time.sleep(1)
        assert js("document.querySelector('#install-banner').hidden")
        call('Page.removeScriptToEvaluateOnNewDocument',dict(identifier=hook))
        call('Page.reload');time.sleep(1)
        js("document.querySelector('#install-dismiss').click()")
        assert js("document.querySelector('#install-banner').hidden")
        call('Page.reload');time.sleep(1)
        assert js("document.querySelector('#install-banner').hidden")
        js("localStorage.clear()")
        call('Page.reload');time.sleep(1)
        assert js("!document.querySelector('#install-banner').hidden")
        hook=call('Page.addScriptToEvaluateOnNewDocument',dict(source="Object.defineProperty(window,'localStorage',{get(){throw Error('blocked')}})"))['identifier']
        call('Page.reload');time.sleep(1)
        js("document.querySelector('#install-dismiss').click()")
        assert js("document.querySelector('#install-banner').hidden")
        call('Page.removeScriptToEvaluateOnNewDocument',dict(identifier=hook))
        manifest=json.loads((ROOT/'painel_php/public/manifest.webmanifest').read_text(encoding='utf-8'))
        assert manifest['start_url']=='./index.php' and manifest['scope']=='./' and manifest['id']=='./'
        from PIL import Image
        for icon in manifest['icons']:
            with Image.open(ROOT/'painel_php/public'/icon['src']) as im: assert f'{im.width}x{im.height}'==icon['sizes']
        assert js("navigator.serviceWorker.getRegistrations().then(r=>r.length)")==0
        print('OK: public integration, manifest scope/icons, fallback, explicit prompt only, cancellation, acceptance, installed mode, iPhone instructions, copy fallback and responsive dialog. Native OS installation requires physical devices.')

finally:
    process.terminate()
    try: process.wait(timeout=3)
    except subprocess.TimeoutExpired: process.kill()
    server.shutdown()
