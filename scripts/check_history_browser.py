"""Checks optional history comparison with fictitious data only."""
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
        import re
        fixture = dict(source='SQLite',players=[dict(id=str(i),name=name,position=i,played=2,wins=1,draws=0,losses=1,points=3,goalsFor=2,goalsAgainst=2,goalDifference=0,history=[dict(date='2026-10-01',position=i,points=0),dict(date='2026-10-08',position=5-i,points=3)]) for i,name in enumerate(['Ana <Teste>', 'Bruno', 'Carla', 'Daniel'],1)],games=[],issues=[],totals=dict(points=12),playedGames=0,pendingGames=0,historyUndatedGames=1)
        html=(ROOT/'site/index.html').read_text(encoding='utf-8')
        html=re.sub(r'<script src="data\.js[^"]*" defer></script>', lambda m: '<script>window.COPA_DATA='+json.dumps(fixture).replace('<',r'\u003c')+';</script>',html)
        html=html.replace('<head>','<head><base href="/site/">')
        (ROOT/'previews/history.html').write_text(html,encoding='utf-8')
        call('Page.navigate',dict(url=base+'/previews/history.html#evolucao'))
        time.sleep(.6)
        assert js("document.querySelector('.history-table tbody').rows.length===2")
        assert js("document.querySelector('#history-comparisons').hidden")
        js("document.querySelector('#history-compare').click()")
        assert js("!document.querySelector('#history-comparisons').hidden && !!document.querySelector('.history-table')")
        js("[...document.querySelectorAll('[data-history-peer]')].forEach((s,i)=>{s.value=String(i+2);s.dispatchEvent(new Event('change'))})")
        assert js("document.querySelectorAll('[data-history-series]').length===4")
        assert js("document.querySelectorAll('.history-legend li').length===4 && document.querySelector('.history-legend').textContent.includes('Ana <Teste>')")
        assert js("document.querySelectorAll('.history-comparison-table thead th').length===5")
        assert js("document.querySelector('.history-comparison-table tbody td').textContent.includes('0 pontos')")
        assert js("[...document.querySelectorAll('[data-history-peer]')].every(s=>[...s.options].filter(o=>o.value && o.value!==s.value).every(o=>o.disabled))")
        for width in [320,390,768,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js('document.documentElement.scrollWidth<=innerWidth'),(width,js("[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth && e.getBoundingClientRect().width).map(e=>[e.tagName,e.className,e.getBoundingClientRect().right]).slice(0,15)"))
        js("document.querySelector('#history-compare').click()")
        assert js("!!document.querySelector('.history-table') && !document.querySelector('[data-history-series]')")
        js("document.querySelector('#history-compare').click();let s=document.querySelector('#history-player');s.value='2';s.dispatchEvent(new Event('change'))")
        assert js("document.querySelector('[data-history-peer]').value==='' && document.querySelectorAll('[data-history-series]').length===3")
        js("window.COPA_DATA.players[2].history=[];document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        assert js("document.querySelector('.history-comparison-table').textContent.includes('Sem registro')")
        js("window.COPA_DATA.players.forEach(p=>p.history=p.history.slice(0,1));document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        assert js("document.querySelector('.history-comparison-table tbody').rows.length===1")
        js("window.COPA_DATA.players.forEach(p=>p.history=[]);document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        assert js("!!document.querySelector('.history-empty')")
        call('Page.reload');time.sleep(.4)
        js("document.querySelector('#history-compare').click();[...document.querySelectorAll('[data-history-peer]')].forEach((s,i)=>{s.value=String(i+2);s.dispatchEvent(new Event('change'))});document.querySelector('#evolucao').scrollIntoView()")
        call('Emulation.setDeviceMetricsOverride',dict(width=390,height=1200,deviceScaleFactor=1,mobile=False))
        (ROOT/'previews/history.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
        print('OK: individual/comparison, four series, duplicates, escaping, exact table values, missing history, single date, empty history and mobile layout')

finally:
    process.terminate()
    try: process.wait(timeout=3)
    except subprocess.TimeoutExpired: process.kill()
    server.shutdown()
