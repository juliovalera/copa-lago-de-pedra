"""Checks sheet photo preparation with generated images only."""
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
        html='<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/painel_php/public/admin.css"><link rel="stylesheet" href="/painel_php/public/attachments.css"><main class="admin attachments-page"><form class="attachment-upload" method="post"><input type="hidden" name="MAX_FILE_SIZE" value="2097152"><label>Arquivo<input type="file" name="file" required></label><p id="attachment-preparation" role="status"></p><figure id="attachment-preview" hidden><a target="_blank"><img alt="Pr?via"></a><figcaption>Confira antes de enviar.</figcaption></figure><button name="action" value="upload">Enviar arquivo</button></form></main><script src="/painel_php/public/attachments.js"></script></html>'
        (ROOT/'previews/attachment-compression.html').write_text(html,encoding='utf-8')
        call('Page.navigate',dict(url=base+'/previews/attachment-compression.html'))
        time.sleep(.4)
        js("window.chooseFile = f => {const d=new DataTransfer();d.items.add(f);const i=document.querySelector('input[type=file]');i.files=d.files;i.dispatchEvent(new Event('change'));};window.preparationDone=()=>new Promise((resolve,reject)=>{let n=0;const t=setInterval(()=>{if(!document.querySelector('#attachment-preparation').textContent.includes('Aguarde')){clearInterval(t);resolve(true)}else if(++n>300){clearInterval(t);reject(Error('timeout'))}},100)});")
        # Noise avoids a trivially compressible test file. Source remains unchanged.
        js("(async()=>{const c=document.createElement('canvas');c.width=1800;c.height=2400;const x=c.getContext('2d'),d=x.createImageData(c.width,c.height);let seed=42;for(let i=0;i<d.data.length;i+=4){seed=(seed*1664525+1013904223)>>>0;d.data[i]=seed&255;d.data[i+1]=(seed>>>8)&255;d.data[i+2]=(seed>>>16)&255;d.data[i+3]=255}x.putImageData(d,0,0);window.largePng=new File([await new Promise(r=>c.toBlob(r,'image/png'))],'camera.png',{type:'image/png'});window.largeJpg=new File([await new Promise(r=>c.toBlob(r,'image/jpeg',1))],'camera.jpg',{type:'image/jpeg'});chooseFile(largePng);return true})()")
        assert js('largePng.size>2097152 && largeJpg.size>2097152')
        assert js('preparationDone()')
        assert js("document.querySelector('input[type=file]').files[0].size<=2097152 && document.querySelector('input[type=file]').files[0].type==='image/jpeg'")
        assert js("!document.querySelector('button').disabled && !document.querySelector('#attachment-preview').hidden")
        assert js("new FormData(document.querySelector('form')).get('file').size<=2097152 && largePng.size>2097152")
        assert js("document.querySelector('a').href.startsWith('blob:')")
        for width in [320,390,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js('document.documentElement.scrollWidth<=innerWidth'),width
        js('chooseFile(largeJpg)');assert js('preparationDone()')
        assert js("document.querySelector('input[type=file]').files[0].size<=2097152")
        js("window.smallPhoto=document.querySelector('input[type=file]').files[0];chooseFile(smallPhoto)")
        assert js('preparationDone()')
        assert js("document.querySelector('input[type=file]').files[0]===smallPhoto")
        # Oversized PDF and corrupt image must block submission.
        js("chooseFile(new File([new Uint8Array(2097153)],'large.pdf',{type:'application/pdf'}))")
        assert js("document.querySelector('button').disabled && !document.querySelector('input[type=file]').validity.valid")
        js("chooseFile(new File(['broken'],'broken.jpg',{type:'image/jpeg'}))");assert js('preparationDone()')
        assert js("document.querySelector('button').disabled && document.querySelector('#attachment-preview').hidden")
        # A new selection wins over a pending image preparation.
        js("chooseFile(largePng);chooseFile(new File(['%PDF-1.4'],'new.pdf',{type:'application/pdf'}))")
        time.sleep(.8)
        assert js("document.querySelector('input[type=file]').files[0].name==='new.pdf' && !document.querySelector('button').disabled && document.querySelector('#attachment-preview').hidden")
        # Encoding failure must not send the original oversized file.
        js("window.realToBlob=HTMLCanvasElement.prototype.toBlob;HTMLCanvasElement.prototype.toBlob=function(callback){callback(null)};chooseFile(largePng)")
        assert js('preparationDone()')
        assert js("document.querySelector('button').disabled && !document.querySelector('input[type=file]').validity.valid")
        js("HTMLCanvasElement.prototype.toBlob=realToBlob;chooseFile(largePng)");assert js('preparationDone()')
        call('Emulation.setDeviceMetricsOverride',dict(width=390,height=900,deviceScaleFactor=1,mobile=False))
        (ROOT/'previews/attachment-compression.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
        print('OK: large PNG/JPEG, exact multipart file, small file untouched, preview, PDF limit, corrupt image, selection race, encoding failure and mobile layout')

finally:
    process.terminate()
    try: process.wait(timeout=3)
    except subprocess.TimeoutExpired: process.kill()
    server.shutdown()
