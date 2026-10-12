"""Checks missing match date validation with a fictitious form."""
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
        html='<html><meta charset="utf-8"><form class="game-form"><input name="score_a" type="number"><input name="score_b" type="number"><input name="played_at" type="date"><button>Salvar</button></form><script src="/painel_php/public/game-filters.js"></script></html>'
        (ROOT/'previews/date-validation.html').write_text(html,encoding='utf-8')
        call('Page.navigate',dict(url=base+'/previews/date-validation.html'));time.sleep(.4)
        js("window.sent=0;document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();sent++});window.f=document.querySelector('form');f.score_a.value='0';f.score_b.value='3';f.score_a.dispatchEvent(new Event('input'));f.requestSubmit()")
        assert js("sent===0 && f.score_a.value==='0' && f.score_b.value==='3' && f.played_at.validationMessage.includes('data do jogo')")
        js("f.played_at.value='2026-10-12';f.played_at.dispatchEvent(new Event('input'));f.requestSubmit()")
        assert js('sent===1')
        js("f.score_a.value='';f.score_b.value='';f.played_at.value='';f.score_a.dispatchEvent(new Event('input'));f.requestSubmit()")
        assert js('sent===2 && !f.played_at.required')
        print('OK: missing date blocks submit and preserves zero score; filling date allows submit; clearing both scores permits removal')

finally:
    process.terminate()
    try: process.wait(timeout=3)
    except subprocess.TimeoutExpired: process.kill()
    server.shutdown()
