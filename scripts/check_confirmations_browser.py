"""Checks reserved channel layouts with fictitious snapshots only."""
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
        for name in ['form','final']:
            call('Page.navigate',dict(url=base+'/previews/confirmation-'+name+'.html'))
            time.sleep(.5)
            for width in [320,390,768,1280]:
                call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
                assert js('document.documentElement.scrollWidth<=innerWidth'),(name,width)
            call('Emulation.setDeviceMetricsOverride',dict(width=390,height=900,deviceScaleFactor=1,mobile=False))
            if name=='form':
                js("document.querySelector('canvas').scrollIntoView({block:'center'})")
                rect=js("(()=>{const r=document.querySelector('canvas').getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}})()")
                call('Input.dispatchTouchEvent',dict(type='touchStart',touchPoints=[dict(x=rect['x']+15,y=rect['y']+50)]))
                for i in range(1,12):
                    call('Input.dispatchTouchEvent',dict(type='touchMove',touchPoints=[dict(x=rect['x']+15+i*12,y=rect['y']+50+(i%2)*30)]))
                call('Input.dispatchTouchEvent',dict(type='touchEnd',touchPoints=[]))
                assert js("JSON.parse(document.querySelector('[name=signature]').value)[0].length>=8")
                (ROOT/'previews/confirmation-touch.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
                js("document.querySelector('[data-clear-signature]').click()")
                assert js("document.querySelector('[name=signature]').value==='[]'")
            (ROOT/('previews/confirmation-'+name+'.png')).write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
            print('OK: confirmation '+name+' responsive at 320/390/768/1280px; touch signature and clear verified on form')

finally:
    process.terminate();process.wait(timeout=10);server.shutdown()
