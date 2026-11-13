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
        fixture['games']=[dict(id=str(i),game=i,round=1 if i<15 else 2,turn=1,a='Bruno' if i%2 else 'Ana <Teste>',b='Ana <Teste>' if i%2 else 'Bruno',status='played',scoreA=0,scoreB=0,playedAt='2026-10-08') for i in range(1,29)]
        for day,status in [('2026-09-20','played'),(None,'played'),('2026-02-30','played'),('2026-10-09','pending')]:
            fixture['games'].append(dict(id=str(len(fixture['games'])+1),game=99,round=3,turn=1,a='Carla',b='Daniel',status=status,scoreA=1,scoreB=0,playedAt=day))
        html=(ROOT/'site/index.html').read_text(encoding='utf-8')
        html=re.sub(r'<script src="data\.js[^"]*" defer></script>', lambda m: '<script>window.COPA_DATA='+json.dumps(fixture).replace('<',r'\u003c')+';</script>',html)
        html=html.replace('<head>','<head><base href="/site/">')
        html=html.replace('<p id="import-date"></p>', '<div class="public-freshness"><p id="import-date"></p><div id="public-refresh" data-endpoint="data.php?format=json"><span id="refresh-status"></span><button id="refresh-now">Atualizar</button></div></div>')
        html=html.replace('<head>', '<head><script>window.refreshCalls=0;window.reply={revision:"initial",data:null};window.fetch=async()=>{refreshCalls++;return {ok:true,json:async()=>reply}};const realInterval=window.setInterval;window.setInterval=(fn,ms)=>{if(ms===60000)window.refreshTick=fn;return realInterval(fn,ms)};</script>')
        (ROOT/'previews/history.html').write_text(html,encoding='utf-8')
        call('Page.navigate',dict(url=base+'/previews/history.html#evolucao'))
        time.sleep(.6)
        assert js("document.querySelector('#competition-progress').value===31 && document.querySelector('#competition-progress').max===32 && document.querySelector('.competition-progress-heading strong').textContent==='96,9%'")
        assert js("document.querySelector('[data-page=evolucao]').textContent==='Estatísticas'")
        assert js("document.querySelector('#history-player').value==='' && document.querySelector('#history-compare').disabled && !document.querySelector('#history-content').textContent")
        assert js("!document.querySelector('#statistics-player .statistics-card') && document.querySelector('#statistics-copa .statistics-card')!==null")
        js("document.querySelector('#history-player').value='1';document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        assert js("document.querySelector('#statistics-player').textContent.includes('28 partida(s)')")
        assert js("document.querySelector('#statistics-player details').textContent.includes('0 × 0')")
        assert js("document.querySelector('#statistics-copa .statistics-card strong').textContent==='3'")
        assert js("document.querySelector('#statistics-copa').textContent.includes('31 partida(s)')")
        js("location.hash='estatisticas'");time.sleep(.1)
        assert js("!document.querySelector('#evolucao').hidden && document.title.startsWith('Estatísticas')")
        js("var statsPick=document.querySelector('#history-player');statsPick.value='3';statsPick.dispatchEvent(new Event('change'))")
        assert js("document.querySelector('#statistics-player .statistics-card strong').textContent==='1'")
        # Pending and excluded games must not inflate any averages or records.
        js("window.COPA_DATA.excludedGames=[{scoreA:99,scoreB:99,status:'annulled'}];document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        assert js("document.querySelector('#statistics-copa .statistics-card strong').textContent==='3'")
        js("document.querySelector('#history-player').value='1';document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        js("location.hash='jogos'")
        assert js("JSON.stringify([...document.querySelector('#game-date').options].map(o=>o.value))===JSON.stringify(['','2026-10-08','2026-09-20'])")
        js("var d=document.querySelector('#game-date');d.value='2026-10-08';d.dispatchEvent(new Event('change'))")
        assert js("document.querySelector('#games-count').textContent.startsWith('28 jogos') && document.querySelectorAll('.game-card').length===26")
        js("document.querySelector('#games-next').click()")
        assert js("document.querySelectorAll('.game-card').length===2")
        js("let r=document.querySelector('#game-round');r.value='1';r.dispatchEvent(new Event('change'))")
        assert js("document.querySelectorAll('.game-card').length===14 && document.querySelector('#games-prev').disabled")
        js("let p=document.querySelector('#game-player');p.value='Bruno';p.dispatchEvent(new Event('change'));let o=document.querySelector('#game-opponent');o.value='Ana <Teste>';o.dispatchEvent(new Event('change'))")
        assert js("document.querySelectorAll('.game-card').length===14")
        js("var s=document.querySelector('#game-status');s.value='pending';s.dispatchEvent(new Event('change'))")
        assert js("document.querySelector('#games-list .empty')!==null")
        js("document.querySelector('#clear-games').click()")
        assert js("document.querySelector('#game-date').value==='' && document.querySelector('#games-count').textContent==='32 de 32 jogos'")
        js("var d=document.querySelector('#game-date');d.value='2026-09-20';d.dispatchEvent(new Event('change'))")
        assert js("document.querySelector('#games-count').textContent.startsWith('1 jogo realizado')")
        assert js("document.querySelector('#competition-progress').value===31 && document.querySelector('#competition-progress').max===32")
        for width in [320,390,768,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js('document.documentElement.scrollWidth<=innerWidth'),width
        js("document.querySelector('#clear-games').click();location.hash='evolucao'")
        print('OK: date options, calendar validation, cross-round results, pagination, combined filters, zero scores, empty result, clear filters and mobile')
        assert js("document.querySelector('.history-table tbody').rows.length===2")
        assert js("document.querySelector('#history-comparisons').hidden")
        js("document.querySelector('#history-compare').click()")
        assert js("!document.querySelector('#history-comparisons').hidden && !!document.querySelector('.history-table')")
        js("[...document.querySelectorAll('[data-history-peer]')].forEach((s,i)=>{s.value=String(i+2);s.dispatchEvent(new Event('change'))})")
        assert js("document.querySelectorAll('[data-history-series]').length===4")
        assert js("document.querySelectorAll('.history-legend li').length===4 && document.querySelector('.history-legend').textContent.includes('Ana <Teste>')")
        assert js("document.querySelectorAll('#history-content .history-comparison-table thead th').length===5")
        assert js("document.querySelector('#history-content .history-comparison-table tbody td').textContent.includes('0 pontos')")
        assert js("[...document.querySelectorAll('[data-history-peer]')].every(s=>[...s.options].filter(o=>o.value && o.value!==s.value).every(o=>o.disabled))")
        for width in [320,390,768,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js('document.documentElement.scrollWidth<=innerWidth'),(width,js("[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth && e.getBoundingClientRect().width).map(e=>[e.tagName,e.className,e.getBoundingClientRect().right]).slice(0,15)"))
        js("document.querySelector('#history-compare').click()")
        assert js("!!document.querySelector('.history-table') && !document.querySelector('[data-history-series]')")
        js("document.querySelector('#history-compare').click();var s=document.querySelector('#history-player');s.value='2';s.dispatchEvent(new Event('change'))")
        assert js("document.querySelector('[data-history-peer]').value==='' && document.querySelectorAll('[data-history-series]').length===3")
        js("window.COPA_DATA.players[2].history=[];document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        assert js("document.querySelector('#history-content .history-comparison-table').textContent.includes('Sem registro')")
        js("window.COPA_DATA.players.forEach(p=>p.history=p.history.slice(0,1));document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        assert js("document.querySelector('#history-content .history-comparison-table tbody').rows.length===1")
        js("window.COPA_DATA.players.forEach(p=>p.history=[]);document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        assert js("!!document.querySelector('.history-empty')")
        call('Page.reload');time.sleep(.4)
        js("document.querySelector('#history-player').value='1';document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        js("document.querySelector('#history-compare').click();[...document.querySelectorAll('[data-history-peer]')].forEach((s,i)=>{s.value=String(i+2);s.dispatchEvent(new Event('change'))});document.querySelector('#evolucao').scrollIntoView()")
        call('Emulation.setDeviceMetricsOverride',dict(width=390,height=1200,deviceScaleFactor=1,mobile=False))
        (ROOT/'previews/history.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
        print('OK: individual/comparison, four series, duplicates, escaping, exact table values, missing history, single date, empty history and mobile layout')

        js("document.querySelector('#history-player').value='';document.querySelector('#history-player').dispatchEvent(new Event('change'))")
        assert js("!document.querySelector('#history-content').textContent && document.querySelector('.history-comparison-controls').hidden && !document.querySelector('#history-compare').checked")
        js("document.activeElement.blur();window.reply={revision:'placeholder',data:structuredClone(COPA_DATA)};document.querySelector('#refresh-now').click()")
        time.sleep(.2)
        assert js("document.querySelector('#history-player').value==='' && !document.querySelector('#statistics-player .statistics-card')")

        # Reuse the fictitious page to cover empty, pending and completed championships.
        import copy
        for total,played,expected in [(0,0,'0%'),(10,0,'0%'),(10,10,'100%'),(650,85,'13,1%')]:
            scenario=copy.deepcopy(fixture)
            scenario['games']=[dict(id=str(i),game=i,round=1,turn=1,a='Ana',b='Bruno',status='played' if i<played else 'pending',scoreA=0,scoreB=0,playedAt='2026-10-08') for i in range(total)]
            scenario_html=re.sub(r'<script>window.COPA_DATA=.*?;</script>',lambda m:'<script>window.COPA_DATA='+json.dumps(scenario)+';</script>',html)
            (ROOT/'previews/progress-test.html').write_text(scenario_html,encoding='utf-8')
            call('Page.navigate',dict(url=base+f'/previews/progress-test.html?total={total}&played={played}#jogos'));time.sleep(.3)
            assert js("document.querySelector('.competition-progress-heading strong').textContent")==expected
            assert js("document.querySelector('#competition-progress').value")==played
            assert js("document.querySelector('#competition-progress').max")==max(1,total)
        call('Emulation.setDeviceMetricsOverride',dict(width=390,height=900,deviceScaleFactor=1,mobile=False))
        js("document.querySelector('#games-summary').scrollIntoView()")
        assert js('document.documentElement.scrollWidth<=innerWidth')
        (ROOT/'previews/progress.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
        print('OK: progress 0/100/13.1 percent, empty championship, accessible native progress and unaffected by filters')

        scenario=copy.deepcopy(fixture)
        scenario['games']=[dict(id=str(i),game=i,round=1,turn=turn,a=a,b=b,status=status,scoreA=0,scoreB=0,playedAt='2026-10-08') for i,(a,b,turn,status) in enumerate([
            ('Ana <Teste>','Bruno',1,'pending'),('Bruno','Ana <Teste>',2,'pending'),
            ('Carla','Ana <Teste>',2,'pending'),('Ana <Teste>','Daniel',1,'played'),
            ('Daniel','Ana <Teste>',2,'played'),('Bruno','Carla',1,'pending')],1)]
        scenario_html=re.sub(r'<script>window.COPA_DATA=.*?;</script>',lambda m:'<script>window.COPA_DATA='+json.dumps(scenario).replace('<',r'\u003c')+';</script>',html)
        (ROOT/'previews/pending-test.html').write_text(scenario_html,encoding='utf-8')
        call('Page.navigate',dict(url=base+'/previews/pending-test.html#classificacao'));time.sleep(.4)
        js("document.querySelector('[data-player=\"1\"]').click();document.querySelector('#profile-pending').click()")
        assert js("document.querySelector('#pending-summary').textContent.includes('3 partidas contra 2 advers\u00e1rios')")
        assert js("document.querySelector('[data-pending-turn=\"1\"]').textContent==='1\u00ba turno'")
        assert js("document.querySelectorAll('#pending-opponents li').length===2 && document.querySelector('.pending-player').textContent==='Ana <Teste>'")
        assert js("document.querySelector('#pending-opponents li').textContent.includes('1') && document.querySelector('#pending-opponents li').textContent.includes('returno')")
        js("document.querySelector('[data-pending-turn=\"1\"]').click()")
        assert js("document.querySelector('#pending-summary').textContent.includes('1 partida contra 1') && document.querySelectorAll('#pending-opponents li').length===1")
        js("document.querySelector('[data-pending-turn=\"2\"]').click()")
        assert js("document.querySelector('#pending-summary').textContent.includes('2 partidas contra 2')")
        for width in [320,390,768,1280]:
            call('Emulation.setDeviceMetricsOverride',dict(width=width,height=900,deviceScaleFactor=1,mobile=False))
            assert js("document.querySelector('#player-dialog').scrollWidth<=document.querySelector('#player-dialog').clientWidth"),width
        call('Emulation.setDeviceMetricsOverride',dict(width=390,height=900,deviceScaleFactor=1,mobile=False))
        js("document.querySelector('[data-pending-turn=\"\"]').click()")
        (ROOT/'previews/pending.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))
        js("document.querySelector('#pending-back').click()")
        assert js("document.activeElement.id==='profile-pending'")
        js("document.querySelector('#close-dialog').click();document.querySelector('[data-player=\"4\"]').click();document.querySelector('#profile-pending').click()")
        assert js("document.querySelectorAll('#pending-opponents li').length===0 && document.querySelector('#pending-summary').textContent.startsWith('Todos os seus jogos')")
        js("document.querySelector('[data-pending-turn=\"1\"]').click()")
        assert js("document.querySelector('#pending-summary').textContent.includes('Nenhuma partida')")
        print('OK: pending opponents in both orientations, distinct counts, both turns, filters, zero-zero played exclusion, empty state, escaped names, focus and mobile modal')


        js("document.querySelector('#close-dialog').click();document.activeElement.blur();location.hash='jogos';document.querySelector('#clear-games').click();document.querySelector('#game-player').value='Bruno';document.querySelector('#game-player').dispatchEvent(new Event('change'));document.querySelector('#games-next').click();document.querySelector('#refresh-now').focus()")
        js("window.next=structuredClone(COPA_DATA);next.players[1].points=99;next.games[0].scoreA=7;window.reply={revision:'changed',data:next};window.scrollTo(0,200);window.oldScroll=scrollY;window.oldPage=document.querySelector('#games-page').textContent;window.refreshTick()")
        time.sleep(.2)
        assert js("COPA_DATA.players[1].points===99 && document.querySelector('#game-player').value==='Bruno' && document.querySelector('#games-page').textContent===oldPage && location.hash==='#jogos' && Math.abs(scrollY-oldScroll)<2")
        assert js("document.querySelector('#refresh-status').textContent.includes('Atualizado')")
        js("window.oldTable=document.querySelector('#ranking-body').innerHTML;window.reply={revision:'changed',data:null};window.refreshTick()")
        time.sleep(.1)
        assert js("document.querySelector('#ranking-body').innerHTML===oldTable")
        js("window.before=refreshCalls;document.querySelector('#game-player').focus();window.refreshTick()")
        assert js('refreshCalls===before')
        js("document.activeElement.blur();document.querySelector('[data-player]').click();window.refreshTick()")
        assert js('refreshCalls===before')
        js("document.querySelector('#close-dialog').click();document.activeElement.blur();Object.defineProperty(document,'hidden',{value:true,configurable:true});window.refreshTick()")
        assert js('refreshCalls===before')
        js("Object.defineProperty(document,'hidden',{value:false,configurable:true});document.dispatchEvent(new Event('visibilitychange'))")
        time.sleep(.1)
        assert js('refreshCalls===before+1')
        js("window.fetch=async()=>{throw Error('offline')};document.querySelector('#refresh-now').click()")
        time.sleep(.1)
        assert js("document.querySelector('#refresh-status').textContent.includes('Sem atualiza') && COPA_DATA.players[1].points===99 && !document.querySelector('#refresh-now').disabled")
        print('OK: refresh updates data, preserves filters/page/hash/scroll, unchanged data, form/dialog/hidden pause, resume and offline preservation')
        js("window.retired=structuredClone(COPA_DATA);retired.withdrawnPlayers=retired.players.map(p=>({id:p.id,name:p.name}));retired.excludedGames=retired.games.map(g=>({...g,status:g.status==='played'?'annulled':'cancelled'}));retired.players=[];retired.games=[];retired.totals.points=0;window.fetch=async()=>({ok:true,json:async()=>({revision:'all-withdrawn',data:retired})});document.querySelector('#refresh-now').click()")
        time.sleep(.2)
        assert js("!document.querySelector('#withdrawal-note').hidden && document.querySelector('#competition-progress').value===0 && document.querySelectorAll('#ranking-body tr').length===0 && document.querySelector('#history-content').textContent.includes('Nenhum participante')")
        print('OK: withdrawn names, excluded original games, empty active ranking/history and progress after live refresh')
        js("document.querySelector('.section-intro').scrollIntoView();document.querySelector('#refresh-status').textContent='Atualizado 14:32'")
        (ROOT/'previews/public-refresh-mobile.png').write_bytes(base64.b64decode(call('Page.captureScreenshot')['data']))

finally:
    process.terminate()
    try: process.wait(timeout=3)
    except subprocess.TimeoutExpired: process.kill()
    server.shutdown()
