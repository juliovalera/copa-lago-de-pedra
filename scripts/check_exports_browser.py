"""Checks download UI/PDF with fictitious snapshots from audit_http.py only."""
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
        call('Page.navigate',dict(url=base+'/previews/export-page.html'))
        time.sleep(1)
        assert js("!!document.querySelector('#export-dialog')")
        # Replace only the API call; all UI, library, font and PDF code is real.
        js("""window.originalFetch=window.fetch;window.fetch=(url,options)=>{
          const u=new URL(url,location.href);
          if(u.pathname.endsWith('/exportar.php'))return originalFetch('report-'+u.searchParams.get('content')+'.json');
          return originalFetch(url,options);
        };document.querySelector('#download').focus();document.querySelector('#download').click();""")
        assert js("document.querySelector('#export-dialog').open")
        for width in [320,390,768,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js("(()=>{const d=document.querySelector('#export-dialog');return d.scrollWidth<=d.clientWidth && d.getBoundingClientRect().right<=innerWidth;})()"),width
        call('Input.dispatchKeyEvent',dict(type='keyDown',key='Escape',code='Escape',windowsVirtualKeyCode=27))
        call('Input.dispatchKeyEvent',dict(type='keyUp',key='Escape',code='Escape',windowsVirtualKeyCode=27))
        assert js("!document.querySelector('#export-dialog').open && document.activeElement.id==='download'")
        js("document.querySelector('[data-page=jogos]').click()")
        for width in [320,390,768,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js("(()=>{const b=document.querySelector('#jogos [data-export-open]');const r=b.getBoundingClientRect();return !document.querySelector('#jogos').hidden && r.width>0 && r.left>=0 && r.right<=innerWidth;})()"),width
        js("document.querySelector('#jogos [data-export-open]').focus();document.querySelector('#jogos [data-export-open]').click()")
        assert js("document.querySelector('#export-dialog').open && document.querySelectorAll('#export-format option').length===3")
        js("document.querySelector('[data-export-close]').click()")
        assert js("document.activeElement===document.querySelector('#jogos [data-export-open]')")
        print('OK: download visible in Games at 320/390/768/1280px; both triggers open the same dialog and restore focus')
        if '--ui-only' in sys.argv: sys.exit(0)
        js("document.querySelector('#jogos [data-export-open]').click();document.querySelector('#export-format').value='pdf'")
        for selection in ['ranking','games','all']:
            js(f"document.querySelector('#export-content').value='{selection}';document.querySelector('.export-form').requestSubmit()")
            for _ in range(180):
                if not js("document.querySelector('.export-form button[type=submit]').disabled"):break
                time.sleep(.25)
            status=js("document.querySelector('.export-status').textContent")
            assert js("!document.querySelector('.export-ready').hidden"),status
            encoded=js("""(async()=>{const b=await (await originalFetch(document.querySelector('.export-ready').href)).blob();return await new Promise(resolve=>{let r=new FileReader();r.onload=()=>resolve(r.result.split(',')[1]);r.readAsDataURL(b);});})()""")
            raw=base64.b64decode(encoded)
            (ROOT/f'previews/export-{selection}.pdf').write_bytes(raw)
            with fitz.open(stream=raw,filetype='pdf') as pdf:
                text='\n'.join(p.get_text() for p in pdf)
                assert 'I Copa Lago de Pedra' in text
                for page in pdf:
                    assert page.rect.width>page.rect.height
                    assert 'Gerado em' in page.get_text()
                    assert f'P\u00e1gina {page.number+1} de {len(pdf)}' in page.get_text()
                    assert len(page.get_images())>=2
                    assert 'Botonista' in page.get_text() or 'Jogador A' in page.get_text()
                    for block in page.get_text('dict')['blocks']:
                        if block['type']!=0:continue
                        for line in block['lines']:
                            for span in line['spans']:
                                x0,y0,x1,y1=span['bbox']
                                assert x0>=15 and y0>=5 and x1<=page.rect.width-15 and y1<=page.rect.height-5,(page.number,span)
                if selection!='ranking':
                    # Each game row contains exactly one status, plus one legend in the title.
                    report=json.loads((ROOT/f'previews/report-{selection}.json').read_text(encoding='utf-8'))
                    games=report['sections'][-1]['rows']
                    assert text.count('Com resultado')==sum(r[-1]=='Com resultado' for r in games)
                    assert text.count('Sem resultado')==sum(r[-1]=='Sem resultado' for r in games)
                if selection=='all':
                    pdf[0].get_pixmap(matrix=fitz.Matrix(1.25,1.25)).save(str(ROOT/'previews/export-pdf-first.png'))
                    next(p for p in pdf if 'Jogador A' in p.get_text()).get_pixmap(matrix=fitz.Matrix(1.25,1.25)).save(str(ROOT/'previews/export-pdf-games.png'))
                print(f'OK: {selection} PDF, {len(pdf)} pages, logos, repeated headers, page numbers, no clipped text')
        call('Emulation.setDeviceMetricsOverride',dict(width=390,height=900,deviceScaleFactor=1,mobile=False))
        (ROOT/'previews/export-dialog-mobile.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
        js("window.fetch=async()=>new Response('failure',{status:503});document.querySelector('.export-form').requestSubmit()")
        assert js("document.querySelector('.export-status').dataset.error==='true' && !document.querySelector('.export-form button[type=submit]').disabled")
        print('OK: modal mobile/desktop, Escape/focus, PDF download, visible fallback link and recovery after HTTP error')
finally:
    process.terminate();process.wait(timeout=10);server.shutdown()
