"""Checks official digital sheet display and A4 printing with fictional fixtures."""
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
        call('Page.navigate',dict(url=base+'/previews/digital-final.html'))
        time.sleep(1)
        assert js("document.querySelectorAll('.brand img').length===2 && [...document.querySelectorAll('.brand img')].every(i=>i.complete && i.naturalWidth>0)")
        assert js("document.querySelectorAll('.confirmed-signatures svg').length===3")
        for width in [320,390,768,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js('document.documentElement.scrollWidth<=innerWidth'),width
            assert js("[...document.querySelectorAll('.info-grid .filled')].every(e=>parseFloat(getComputedStyle(e).fontSize)>0 && e.getBoundingClientRect().height>0)")
        call('Emulation.setDeviceMetricsOverride',dict(width=390,height=900,deviceScaleFactor=1,mobile=False))
        (ROOT/'previews/digital-official-mobile.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
        js("window.printCalls=0;window.print=()=>printCalls++;document.querySelector('#digital-print').click()")
        assert js('printCalls===1')
        raw=base64.b64decode(call('Page.printToPDF',dict(printBackground=True,preferCSSPageSize=True))['data'])
        (ROOT/'previews/digital-official.pdf').write_bytes(raw)
        pdf=fitz.open(stream=raw,filetype='pdf');text=''.join(p.get_text() for p in pdf)
        assert len(pdf)==1,len(pdf)
        assert 'REGRA 12 TOQUES' in text and 'TURNO' in text and 'PLACAR FINAL' in text and 'MESA' in text
        assert len(pdf[0].get_images())>=2 and len(pdf[0].get_drawings())>=3
        pdf[0].get_pixmap(matrix=fitz.Matrix(1,1)).save(str(ROOT/'previews/digital-official-pdf.png'))
        js("document.querySelector('.notes p').textContent=('Observacao extensa para verificar paginas. '.repeat(48))+'FIM DAS OBSERVACOES';document.querySelector('.document-reference').textContent+=' FIM DO DOCUMENTO'")
        raw=base64.b64decode(call('Page.printToPDF',dict(printBackground=True,preferCSSPageSize=True))['data'])
        long=fitz.open(stream=raw,filetype='pdf');text=''.join(p.get_text() for p in long)
        assert 'FIM DAS OBSERVACOES' in text and 'FIM DO DOCUMENTO' in text
        print('OK: official digital sheet, real logos, three signatures, visible metadata, responsive widths, print button, one-page A4 and long notes preserved')

finally:
    process.terminate()
    try: process.wait(timeout=3)
    except subprocess.TimeoutExpired: process.kill()
    server.shutdown()
