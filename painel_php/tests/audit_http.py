"""Testa rotas reais numa cópia temporária, sem banco real nem envio de e-mail."""
from pathlib import Path
import http.cookiejar
import json
import io
import zipfile
import xml.etree.ElementTree as ET
from datetime import date
import hashlib
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.request import build_opener, HTTPCookieProcessor
from urllib.parse import urlencode
from urllib.error import HTTPError, URLError

ROOT = Path(__file__).resolve().parents[2]
(ROOT/'previews').mkdir(exist_ok=True)
PHP = shutil.which('php') or r'C:\php\php.exe'
PHP_ARGS = [PHP] + (['-c', str(ROOT/'painel_php/php.ini')] if (ROOT/'painel_php/php.ini').exists() else [])
with tempfile.TemporaryDirectory(prefix='copa-audit-http-') as directory:
    root = Path(directory)
    shutil.copytree(ROOT/'site', root/'site')
    app = root/'painel_php'
    (app/'public').mkdir(parents=True)
    for name in ('complaints.php', 'documents.php', 'export_ui.php', 'spreadsheet.php', 'digital_sheet.php', 'db.php', 'audit.php', 'version.php', 'notifications.php', 'login_security.php'):
        shutil.copyfile(ROOT/'painel_php'/name, app/name)
    for path in (ROOT/'painel_php/public').glob('*'):
        if path.is_file(): shutil.copyfile(path, app/'public'/path.name)
        elif path.is_dir(): shutil.copytree(path, app/'public'/path.name)
    (app/'config.php').write_text("<?php return ['database'=>__DIR__.'/test.sqlite','timezone'=>'America/Sao_Paulo','admin_password'=>'test-only','notification_email'=>'organizer@example.invalid','base_url'=>'http://example.invalid','backup_directory'=>__DIR__.'/backups'];", encoding='utf-8')
    (app/'mailer.php').write_text("<?php function smtpSend(...$args): void {}", encoding='utf-8')
    (root/'seed.php').write_text("""<?php
    require __DIR__.'/painel_php/db.php'; initialiseDatabase();
    for ($i=1;$i<=26;$i++) db()->prepare('INSERT INTO players VALUES (?,?)')->execute([$i,'Jogador '.$i]);
    for ($i=1;$i<=650;$i++) db()->prepare('INSERT INTO games(id,round_number,game_number,turn_number,player_a_id,player_b_id) VALUES (?,1,?,1,1,2)')->execute([$i,$i]);
    foreach ([[1,'Gestor',null],[2,'Atleta',1]] as [$id,$name,$player]) db()->prepare('INSERT INTO users(id,name,username,email,password_hash,player_id,role,is_active,created_at) VALUES (?,?,?,?,?,?,?,1,?)')->execute([$id,$name,$name,$name.'@example.invalid',password_hash('test-only',PASSWORD_DEFAULT),$player,$player === null ? 'admin' : 'player',date('c')]);
    """, encoding='utf-8')
    subprocess.run(PHP_ARGS + [str(root/'seed.php')],check=True,capture_output=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    server=subprocess.Popen(PHP_ARGS + ['-S',f'127.0.0.1:{port}','-t',str(app/'public')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    base=f'http://127.0.0.1:{port}/'
    def client(): return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def get(c,path,data=None):
        try:
            with c.open(base+path,None if data is None else urlencode(data).encode(),timeout=5) as r: return r.status,r.read().decode(),r.url
        except HTTPError as e: return e.code,e.read().decode(),e.url
    def result_token(html, game_id=1):
        form=next(f for f in re.findall(r'<form class="game-form".*?</form>',html) if 'name="game_id" value="'+str(game_id)+'"' in f)
        return re.search(r'name="result_token" value="([^"]+)"',form).group(1)
    def csrf(html): return re.search(r'name="csrf" value="([^"]+)"',html).group(1)
    try:
        guest=client()
        for _ in range(50):
            try: get(guest,'admin.php'); break
            except URLError: time.sleep(.1)
        status,login_html,_=get(guest,'admin.php')
        assert 'Voltar à área pública' in login_html and 'Versão 1.49' in login_html
        assert login_html.count('id="login"')==1
        status,guide_html,_=get(guest,'guia.php')
        assert status==200 and '<svg' in guide_html and 'Versão 1.49' in guide_html
        status,public_html,_=get(guest,'index.php')
        assert status==200 and 'href="guia.php"' in public_html and 'Versão 1.49' in public_html
        for page in (login_html, guide_html, public_html):
            assert page.count('id="copa-credits"') == 1
            assert 'julio@projetos.tec.br' in page and 'data-copa-credits' in page
        def credits_preview(html):
            return html.replace('href="credits.css?', 'href="../painel_php/public/credits.css?').replace('src="credits.js?', 'src="../painel_php/public/credits.js?')
        guide_html = credits_preview(guide_html)
        login_html = credits_preview(login_html)
        (ROOT/'previews/guia.html').write_text(guide_html.replace('href="guia.css?', 'href="../painel_php/public/guia.css?'),encoding='utf-8')
        (ROOT/'previews/login.html').write_text(login_html.replace('href="admin.css?', 'href="../painel_php/public/admin.css?'),encoding='utf-8')
        print('OK: retorno publico, guia sem login e versao 1.49 consistente')
        status,html,url=get(guest,'auditoria.php')
        assert url.endswith('admin.php') and 'Histórico de ações' not in html
        athlete=client();get(athlete,'admin.php',{'login':'Atleta','password':'test-only'})
        assert get(athlete,'auditoria.php')[0]==403
        print('OK: visitante e botonista sem acesso à auditoria')
        normal_admin=client();get(normal_admin,'admin.php',{'login':'Gestor','password':'test-only'})
        assert get(normal_admin,'usuarios.php')[0]==403
        admin=client();status,html,_=get(admin,'admin.php',{'login':'','password':'test-only'})
        status,html,_=get(admin,'admin.php',{'csrf':csrf(html),'result_token':result_token(html),'game_id':1,'score_a':2,'score_b':1,'played_at':'2026-09-20'})
        assert status==200
        status,html,_=get(admin,'usuarios.php')
        status,html,_=get(admin,'usuarios.php',{'csrf':csrf(html),'action':'create','name':'Teste <script>alert(1)</script>','username':'novo','email':'teste@example.invalid','player_id':'','role':'admin'})
        assert status==200 and 'Usuário cadastrado' in html
        status,html,_=get(admin,'usuarios.php',{'csrf':csrf(html),'action':'toggle','user_id':3})
        assert status==200
        db=sqlite3.connect(app/'test.sqlite')
        status,date_form,_=get(admin,'admin.php')
        original=db.execute('SELECT score_a,score_b,played_at FROM games WHERE id=1').fetchone()
        for invalid_date in ['2026-02-30','2026-04-31','2026-02-29','0000-01-01']:
            status,date_form,_=get(admin,'admin.php',{'csrf':csrf(date_form),'game_id':1,'score_a':9,'score_b':9,'played_at':invalid_date})
            assert status==200 and db.execute('SELECT score_a,score_b,played_at FROM games WHERE id=1').fetchone()==original
        assert db.execute("SELECT COUNT(*) FROM email_notifications WHERE status='sent'").fetchone()[0]>0
        print('OK: datas impossiveis recusadas na rota HTTP; avisos enviados apos salvar')
        actions=[row[0] for row in db.execute('SELECT action FROM audit_log')]
        assert all(a in actions for a in ['Resultado salvo','Usuário cadastrado','Convite emitido','Convite enviado','Acesso alterado'])
        status,html,_=get(admin,'auditoria.php?actor=Administrador%20principal')
        assert status==200 and 'Resultado salvo' in html and '<script>alert(1)</script>' not in html and '&lt;script&gt;' in html
        assert 'Não se aplica' in html and 'Gols do jogador A' in html
        print('OK: resultados, usuários e convites auditados; saída HTML escapada')
        status,filtered,_=get(admin,'auditoria.php?'+urlencode({'action':'Resultado salvo','actor':'Administrador principal'}))
        assert filtered.count('<article class="admin-card">')==1
        assert 'Nenhum registro' in get(admin,'auditoria.php?start=2099-01-01')[1]
        assert 'Informe datas válidas' in get(admin,'auditoria.php?start=invalid')[1]
        # A gravação de resultado deve bloquear CSRF inválido sem criar log.
        before=db.execute('SELECT COUNT(*) FROM audit_log').fetchone()[0]
        assert get(admin,'admin.php',{'csrf':'errado','game_id':1,'score_a':9,'score_b':9,'played_at':'2026-09-20'})[0]==400
        assert db.execute('SELECT COUNT(*) FROM audit_log').fetchone()[0]==before
        print('OK: filtros e bloqueio de CSRF')
        invite_token='fixture-invite-only'
        db.execute('UPDATE user_invites SET token_hash=? WHERE user_id=3',(hashlib.sha256(invite_token.encode()).hexdigest(),));db.commit()
        status,_,_=get(client(),'invite.php',{'t':invite_token,'password':'fixture-pass-only','confirmation':'fixture-pass-only'})
        assert status==200
        assert db.execute("SELECT COUNT(*) FROM audit_log WHERE action='Senha definida e conta ativada'").fetchone()[0]==1
        status,form,_=get(admin,'backup.php')
        status,form,_=get(admin,'backup.php',{'csrf':csrf(form),'action':'create'})
        assert status==200
        backup_name=next((app/'backups').glob('*.sqlite')).name
        status,form,_=get(admin,'admin.php')
        get(admin,'admin.php',{'csrf':csrf(form),'result_token':result_token(form),'game_id':1,'score_a':5,'score_b':0,'played_at':'2026-09-21'})
        before=db.execute('SELECT COUNT(*) FROM audit_log').fetchone()[0]
        status,form,_=get(admin,'backup.php')
        status,form,_=get(admin,'backup.php',{'csrf':csrf(form),'action':'restore','backup_name':backup_name})
        assert status==200 and 'Backup restaurado.' in form
        assert db.execute('SELECT score_a FROM games WHERE id=1').fetchone()[0]==2
        assert db.execute('SELECT COUNT(*) FROM audit_log').fetchone()[0]==before+1
        with admin.open(base+'backup.php?'+urlencode({'download':backup_name})) as response:
            assert response.read(16).startswith(b'SQLite format 3')
        assert db.execute("SELECT COUNT(*) FROM audit_log WHERE action='Backup baixado'").fetchone()[0]==1
        safety_name=next((app/'backups').glob('*-antes-da-restauracao.sqlite')).name
        for who in (admin,normal_admin):
            with who.open(base+'backup.php?'+urlencode({'download':safety_name})) as response:
                assert response.read(16).startswith(b'SQLite format 3')
                assert safety_name in response.headers['Content-Disposition']
        assert get(athlete,'backup.php?'+urlencode({'download':safety_name}))[0]==403
        for invalid in ['../'+safety_name, safety_name+'\n',safety_name+'.txt','config.php','copa-lago-de-pedra-20000101-000000-antes-da-restauracao.sqlite']:
            assert get(admin,'backup.php?'+urlencode({'download':invalid}))[0]==404
        print('OK: download de copia anterior a restauracao, permissoes e rejeicao de caminhos invalidos')
        print('OK: ativação e restauração pelas rotas reais preservam auditoria')
        # Guarda somente uma prévia com dados fictícios.
        (ROOT/'previews').mkdir(exist_ok=True)
        preview=html.replace('href="admin.css?', 'href="../painel_php/public/admin.css?')
        (ROOT/'previews/auditoria.html').write_text(preview,encoding='utf-8')
        status,own_form,_=get(athlete,'botonistas.php')
        assert status==200 and 'Corrigir meu nome' in own_form
        assert own_form.count('name="player_id"')==1
        assert get(athlete,'botonistas.php',{'csrf':csrf(own_form),'player_id':2,'old_name':'Jogador 2','name':'Outro'})[0]==403
        assert get(guest,'botonistas.php')[2].endswith('admin.php')
        before_games=db.execute('SELECT * FROM games ORDER BY id').fetchall()
        before_users=db.execute('SELECT * FROM users ORDER BY id').fetchall()
        status,form,_=get(admin,'botonistas.php')
        assert status==200 and 'Corrigir nomes' in form
        assert get(admin,'botonistas.php',{'csrf':'invalido','player_id':1,'old_name':'Jogador 1','name':'Mateus Adorno'})[0]==400
        status,form,_=get(admin,'botonistas.php',{'csrf':csrf(form),'player_id':1,'old_name':'Jogador 1','name':'Mateus Adorno'})
        assert status==200 and 'Nome salvo.' in form
        assert db.execute('SELECT name FROM players WHERE id=1').fetchone()[0]=='Mateus Adorno'
        assert db.execute('SELECT * FROM games ORDER BY id').fetchall()==before_games
        assert db.execute('SELECT * FROM users ORDER BY id').fetchall()==before_users
        event=db.execute("SELECT before_json,after_json FROM audit_log WHERE action='Nome de botonista corrigido'").fetchone()
        assert 'Jogador 1' in event[0] and 'Mateus Adorno' in event[1]
        for name,old in [('','Mateus Adorno'),('jogador 2','Mateus Adorno'),('Outro nome','Jogador 1')]:
            status,form,_=get(admin,'botonistas.php',{'csrf':csrf(form),'player_id':1,'old_name':old,'name':name})
            assert 'role="alert"' in form
            assert db.execute('SELECT name FROM players WHERE id=1').fetchone()[0]=='Mateus Adorno'
        assert 'Mateus Adorno' in get(guest,'data.php')[1]
        assert db.execute("SELECT COUNT(*) FROM audit_log WHERE action='Nome de botonista corrigido'").fetchone()[0]==1
        print('OK: correção de nome autorizada e auditada, dados preservados, validações e atualização pública')
        db.execute("CREATE TRIGGER fail_name_log BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT, 'teste'); END");db.commit()
        status,form,_=get(admin,'botonistas.php',{'csrf':csrf(form),'player_id':1,'old_name':'Mateus Adorno','name':'Nome diferente'})
        assert 'Nenhuma alteração foi confirmada' in form
        assert db.execute('SELECT name FROM players WHERE id=1').fetchone()[0]=='Mateus Adorno'
        print('OK: falha de auditoria desfaz a correção de nome')
        db.execute('DROP TRIGGER fail_name_log');db.commit()
        status,own_form,_=get(athlete,'botonistas.php')
        status,own_form,_=get(athlete,'botonistas.php',{'csrf':csrf(own_form),'player_id':1,'old_name':'Mateus Adorno','name':'Mateus Corrigido'})
        assert status==200 and 'Nome salvo.' in own_form
        assert db.execute('SELECT name FROM players WHERE id=1').fetchone()[0]=='Mateus Corrigido'
        event=db.execute("SELECT actor FROM audit_log WHERE action='Nome de botonista corrigido' ORDER BY rowid DESC LIMIT 1").fetchone()[0]
        assert 'Atleta' in event
        print('OK: botonista corrige somente o proprio nome, com autoria na auditoria')
        status,access_form,_=get(admin,'usuarios.php')
        assert 'Salvar privilégios' in access_form
        assert get(athlete,'usuarios.php',{'action':'privilege','user_id':2,'role':'admin'})[0]==403
        assert get(normal_admin,'usuarios.php',{'action':'privilege','user_id':2,'role':'admin'})[0]==403
        assert get(admin,'usuarios.php',{'csrf':'invalid','action':'privilege','user_id':2,'role':'admin','previous_player':'1','previous_role':'player'})[0]==400
        status,access_form,_=get(admin,'usuarios.php',{'csrf':csrf(access_form),'action':'privilege','user_id':2,'role':'admin','previous_player':'1','previous_role':'player'})
        assert db.execute('SELECT player_id FROM users WHERE id=2').fetchone()[0] is None
        assert get(athlete,'auditoria.php')[0]==200
        assert get(athlete,'usuarios.php')[0]==403
        status,access_form,_=get(admin,'usuarios.php',{'csrf':csrf(access_form),'action':'privilege','user_id':2,'role':'player','player_id':2,'previous_player':'','previous_role':'admin'})
        assert db.execute('SELECT player_id FROM users WHERE id=2').fetchone()[0]==2
        assert get(athlete,'auditoria.php')[0]==403
        assert get(athlete,'backup.php')[0]==403
        status,own_form,_=get(athlete,'botonistas.php')
        assert 'value="Jogador 2"' in own_form
        assert get(athlete,'botonistas.php',{'csrf':csrf(own_form),'player_id':1,'old_name':'Mateus Corrigido','name':'Outro'})[0]==403
        for params in [{'role':'player','player_id':9999,'previous_player':'2','previous_role':'player'}, {'role':'master','previous_player':'2','previous_role':'player'}, {'role':'admin','previous_player':'1','previous_role':'player'}]:
            status,access_form,_=get(admin,'usuarios.php',dict(csrf=csrf(access_form),action='privilege',user_id=2,**params))
            assert db.execute('SELECT player_id FROM users WHERE id=2').fetchone()[0]==2
        assert db.execute("SELECT COUNT(*) FROM audit_log WHERE action='Privilégios alterados'").fetchone()[0]==2
        status,backup_form,_=get(normal_admin,'backup.php')
        assert status==200
        assert get(normal_admin,'backup.php',{'csrf':csrf(backup_form),'action':'restore','backup_name':backup_name})[0]==403
        db.execute("CREATE TRIGGER fail_privilege_log BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT, 'teste'); END");db.commit()
        status,access_form,_=get(admin,'usuarios.php',{'csrf':csrf(access_form),'action':'privilege','user_id':2,'role':'admin','previous_player':'2','previous_role':'player'})
        assert db.execute('SELECT player_id FROM users WHERE id=2').fetchone()[0]==2
        assert 'Nenhuma alteração foi confirmada' in access_form
        print('OK: apenas acesso principal muda niveis; sessoes abertas respeitam promocao e rebaixamento')
        notice=db.execute('SELECT event_id,attempts FROM email_notifications ORDER BY rowid DESC LIMIT 1').fetchone()
        assert notice is not None
        db.execute("UPDATE email_notifications SET status='failed' WHERE event_id=?",(notice[0],));db.commit()
        status,notice_form,_=get(admin,'auditoria.php')
        assert status==200 and 'Tentar enviar aviso novamente' in notice_form
        assert get(normal_admin,'auditoria.php',{'csrf':csrf(notice_form),'notification':notice[0]})[0]==403
        assert get(admin,'auditoria.php',{'csrf':'invalid','notification':notice[0]})[0]==400
        status,notice_form,_=get(admin,'auditoria.php',{'csrf':csrf(notice_form),'notification':notice[0]})
        assert db.execute('SELECT status,attempts FROM email_notifications WHERE event_id=?',(notice[0],)).fetchone()==('sent',notice[1]+1)
        print('OK: falha de e-mail visivel e reenvio restrito ao acesso principal')
        db.execute('DROP TRIGGER fail_privilege_log');db.commit()
        status,details_form,_=get(admin,'usuarios.php')
        params={'csrf':csrf(details_form),'action':'details','user_id':1,'name':'Gestor Atualizado','email':'gestor.novo@example.invalid','previous_name':'Gestor','previous_email':'Gestor@example.invalid'}
        assert get(normal_admin,'usuarios.php',params)[0]==403
        assert get(athlete,'usuarios.php',params)[0]==403
        assert get(admin,'usuarios.php',dict(params,csrf='invalid'))[0]==400
        details_status,details_reply,_=get(admin,'usuarios.php',params)
        assert details_status==200
        assert db.execute('SELECT name,email FROM users WHERE id=1').fetchone()==('Gestor Atualizado','gestor.novo@example.invalid'), re.findall(r'<p class="flash"[^>]*>(.*?)</p>',details_reply)
        event=db.execute("SELECT event_id FROM audit_log WHERE action='Dados de usuário alterados' ORDER BY rowid DESC LIMIT 1").fetchone()[0]
        assert set(db.execute('SELECT recipient,status FROM email_notifications WHERE event_id=?',(event,)).fetchall())=={('gestor@example.invalid','sent'),('gestor.novo@example.invalid','sent')}
        password_notice=db.execute("SELECT COUNT(*) FROM email_notifications n JOIN audit_log a ON a.event_id=n.event_id WHERE a.action='Senha definida e conta ativada' AND n.audience='account' AND n.recipient='teste@example.invalid' AND n.status='sent'").fetchone()[0]
        assert password_notice>=1
        print('OK: edicao HTTP restrita ao principal, CSRF e avisos de nome/email/senha')

        revoke_tokens=['cancelar-convite-a','cancelar-convite-b']
        for token in revoke_tokens:
            db.execute("INSERT INTO user_invites(user_id,token_hash,expires_at,created_at) VALUES (3,?,'2099-01-01T00:00:00+00:00','2026-09-27T00:00:00+00:00')",(hashlib.sha256(token.encode()).hexdigest(),))
        other_hash=hashlib.sha256(b'convite-outro-usuario').hexdigest()
        db.execute("INSERT INTO user_invites(user_id,token_hash,expires_at,created_at) VALUES (2,?,'2099-01-01T00:00:00+00:00','2026-09-27T00:00:00+00:00')",(other_hash,));db.commit()
        opened=client()
        assert 'Nova senha' in get(opened,'invite.php?t='+revoke_tokens[0])[1]
        password_before=db.execute('SELECT password_hash FROM users WHERE id=3').fetchone()[0]
        used_before=db.execute('SELECT id,used_at FROM user_invites WHERE user_id=3 AND used_at IS NOT NULL').fetchall()
        status,user_form,_=get(admin,'usuarios.php')
        assert get(admin,'usuarios.php',{'csrf':'invalid','action':'toggle','user_id':3})[0]==400
        assert get(normal_admin,'usuarios.php',{'csrf':csrf(user_form),'action':'toggle','user_id':3})[0]==403
        db.execute("CREATE TRIGGER fail_cancel BEFORE UPDATE ON user_invites WHEN OLD.user_id=3 AND OLD.used_at IS NULL BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");db.commit()
        assert 'Nenhuma alteração foi confirmada' in get(admin,'usuarios.php',{'csrf':csrf(user_form),'action':'toggle','user_id':3})[1]
        assert db.execute('SELECT is_active FROM users WHERE id=3').fetchone()[0]==1
        assert db.execute('SELECT COUNT(*) FROM user_invites WHERE user_id=3 AND used_at IS NULL').fetchone()[0]==2
        db.execute('DROP TRIGGER fail_cancel');db.commit()
        status,user_form,_=get(admin,'usuarios.php',{'csrf':csrf(user_form),'action':'toggle','user_id':3})
        assert status==200 and db.execute('SELECT is_active FROM users WHERE id=3').fetchone()[0]==0
        assert db.execute('SELECT COUNT(*) FROM user_invites WHERE user_id=3 AND used_at IS NULL').fetchone()[0]==0
        assert db.execute('SELECT used_at FROM user_invites WHERE token_hash=?',(other_hash,)).fetchone()[0] is None
        for ident,used_at in used_before:
            assert db.execute('SELECT used_at FROM user_invites WHERE id=?',(ident,)).fetchone()[0]==used_at
        for token in revoke_tokens:
            assert 'Convite indisponível' in get(opened,'invite.php?t='+token)[1]
            assert 'Convite indisponível' in get(opened,'invite.php',{'t':token,'password':'new-test-pass','confirmation':'new-test-pass'})[1]
        assert db.execute('SELECT is_active,password_hash FROM users WHERE id=3').fetchone()==(0,password_before)
        # Um NOVO convite emitido explicitamente pelo acesso principal continua permitido.
        status,user_form,_=get(admin,'usuarios.php',{'csrf':csrf(user_form),'action':'resend','user_id':3})
        fresh='novo-convite-autorizado'
        db.execute('UPDATE user_invites SET token_hash=? WHERE user_id=3 AND used_at IS NULL',(hashlib.sha256(fresh.encode()).hexdigest(),));db.commit()
        assert get(client(),'invite.php',{'t':fresh,'password':'new-test-pass','confirmation':'new-test-pass'})[0]==200
        assert db.execute('SELECT is_active FROM users WHERE id=3').fetchone()[0]==1
        assert 'Convite indisponível' in get(opened,'invite.php?t='+revoke_tokens[0])[1]
        print('OK: desativação atômica cancela convites, bloqueia formulário antigo e preserva outros usuários; novo convite autorizado funciona')
        for attempt in range(4):
            status,body,_=get(client(),'admin.php',{'login':'gestor','password':'senha-errada-secreta'})
            assert status==200 and 'Login, e-mail ou senha inválidos' in body
        status,body,_=get(client(),'sumula-login.php',{'login':'gestor.novo@example.invalid','password':'senha-errada-secreta','game_id':10})
        assert status==429 and 'Aguarde 15' in body
        assert get(client(),'admin.php',{'login':'Gestor','password':'test-only'})[0]==429
        assert get(guest,'index.php')[0]==200
        assert get(normal_admin,'admin.php')[0]==200
        other=client()
        assert get(other,'admin.php',{'login':'Atleta','password':'test-only'})[0]==200
        master_audit=get(admin,'auditoria.php')[1]
        regular_audit=get(normal_admin,'auditoria.php')[1]
        assert 'Bloqueio temporário iniciado' in master_audit and 'Origem (IP)' in master_audit
        assert 'Bloqueio temporário iniciado' not in regular_audit and 'Autenticação recusada' not in regular_audit
        assert 'senha-errada-secreta' not in str(db.execute('SELECT * FROM audit_log').fetchall())
        db.execute('UPDATE login_limits SET blocked_until=1');db.commit()
        assert get(client(),'admin.php',{'login':'Gestor','password':'test-only'})[0]==200
        for attempt in range(5):
            status,body,_=get(client(),'admin.php',{'login':'','password':'senha-errada-secreta'})
            assert status==(429 if attempt==4 else 200)
        assert get(client(),'admin.php',{'login':'','password':'test-only'})[0]==429
        assert get(admin,'usuarios.php')[0]==200
        assert get(client(),'sumula-login.php',{'login':'Gestor','password':'test-only','game_id':10})[0]==200
        print('OK: bloqueio HTTP compartilhado, acesso principal protegido, consulta pública e sessões mantidas, logs restritos')
        status,form,_=get(admin,'sumula.php?game=650')
        assert status==200
        status,sheet,_=get(admin,'sumula.php?game=650',{'csrf':csrf(form),'match_date':date.today().isoformat()})
        assert status==200 and 'id="sumula-qr"' in sheet and 'api.qrserver.com' not in sheet
        notice_id=db.execute("SELECT event_id FROM audit_log WHERE action='Súmula gerada' AND target='Jogo 650' ORDER BY rowid DESC LIMIT 1").fetchone()[0]
        expected_emails={row[0].lower() for row in db.execute('SELECT email FROM users WHERE player_id IN (1,2)')} | {'organizer@example.invalid'}
        delivered={row[0] for row in db.execute("SELECT recipient FROM email_notifications WHERE event_id=? AND status='sent'",(notice_id,))}
        assert expected_emails and expected_emails.issubset(delivered)

        assert 'vendor/qrcode-generator/qrcode.js' in sheet and 'id="print-sumula" disabled' in sheet
        assert get(guest,'vendor/qrcode-generator/qrcode.js')[0]==200
        fixture=sheet.replace('href="sumula.css?', 'href="../painel_php/public/sumula.css?').replace('src="vendor/', 'src="../painel_php/public/vendor/').replace('src="sumula-qr.js?', 'src="../painel_php/public/sumula-qr.js?')
        fixture=fixture.replace('src="asset.php?file=', 'src="../site/')
        (ROOT/'previews/sumula-local.html').write_text(fixture,encoding='utf-8')
        assert get(admin,'sumula.php?game=1')[0]==409
        print('OK: sumula com QR local, recursos locais e bloqueio para jogo concluido')

        (ROOT/'previews/usuarios.html').write_text(get(admin,'usuarios.php')[1].replace('href="admin.css?', 'href="../painel_php/public/admin.css?'),encoding='utf-8')
        # Link an existing administrator without restricting access or guessing names.
        status,roles_form,_=get(admin,'usuarios.php')
        status,roles_form,_=get(admin,'usuarios.php',{'csrf':csrf(roles_form),'action':'privilege','user_id':1,'role':'admin','player_id':1,'previous_player':'','previous_role':'admin'})
        assert db.execute('SELECT role,player_id FROM users WHERE id=1').fetchone()==('admin',1)
        assert get(normal_admin,'auditoria.php')[0]==200
        assert get(normal_admin,'usuarios.php')[0]==403
        db.execute('UPDATE games SET player_a_id=2,player_b_id=3 WHERE id=649');db.commit()
        assert get(normal_admin,'sumula.php?game=649')[0]==200
        assert get(client(),'sumula-login.php',{'login':'Gestor','password':'test-only','game_id':649})[0]==200
        status,form,_=get(normal_admin,'sumula.php?game=650')
        assert get(normal_admin,'sumula.php?game=650',{'csrf':csrf(form),'match_date':date.today().isoformat()})[0]==200
        event=db.execute("SELECT event_id FROM audit_log WHERE target='Jogo 650' ORDER BY rowid DESC LIMIT 1").fetchone()[0]
        assert db.execute("SELECT status FROM email_notifications WHERE event_id=? AND recipient='gestor.novo@example.invalid'",(event,)).fetchone()==('sent',)
        status,audit_html,_=get(normal_admin,'auditoria.php')
        assert 'Ver destinatários dos avisos' in audit_html and 'gestor.novo@example.invalid' in audit_html and 'organizer@example.invalid' in audit_html
        print('OK: administrador vinculado acessa outros jogos, recebe aviso pessoal e auditoria mostra destinatarios')

        # Two sessions open the same result; only the first save may succeed.
        first=get(admin,'admin.php')[1]
        second=get(normal_admin,'admin.php')[1]
        old=result_token(second)
        get(admin,'admin.php',{'csrf':csrf(first),'result_token':result_token(first),'game_id':1,'score_a':7,'score_b':4,'played_at':'2026-09-20'})
        counts=db.execute('SELECT COUNT(*) FROM audit_log').fetchone()[0],db.execute('SELECT COUNT(*) FROM email_notifications').fetchone()[0]
        status,conflict,url=get(normal_admin,'admin.php',{'csrf':csrf(second),'result_token':old,'game_id':1,'score_a':9,'score_b':9,'played_at':'2026-09-21'})
        assert status==200 and 'Sua alteração não foi salva' in conflict and 'round=1' in url
        assert db.execute('SELECT score_a,score_b FROM games WHERE id=1').fetchone()==(7,4)
        assert counts==(db.execute('SELECT COUNT(*) FROM audit_log').fetchone()[0],db.execute('SELECT COUNT(*) FROM email_notifications').fetchone()[0])
        get(normal_admin,'admin.php',{'csrf':csrf(conflict),'result_token':result_token(conflict),'game_id':1,'score_a':8,'score_b':4,'played_at':'2026-09-20'})
        assert db.execute('SELECT score_a FROM games WHERE id=1').fetchone()[0]==8
        print('OK: duas sessoes preservam resultado mais recente e permitem salvar apos conferir')

        # Both sheet formats remain available; digital submission uses the same game.
        status,choice,_=get(admin,'sumula.php?game=648')
        assert status==200 and 'name="mode" value="print"' in choice and 'name="mode" value="digital"' in choice
        status,digital,url=get(admin,'sumula.php?game=648',{'csrf':csrf(choice),'match_date':date.today().isoformat(),'mode':'digital'})
        assert status==200 and 'digital-form' in digital and 'sumula-digital.php' in url
        assert get(guest,'sumula-digital.php?game=648&date='+date.today().isoformat())[0]==403
        link=db.execute('SELECT token FROM referee_links WHERE game_id=648').fetchone()[0]
        path='sumula-digital.php?t='+link
        status,digital,_=get(guest,path)
        assert status==200
        def hidden(html,name): return re.search('name="'+name+'" value="([^\"]*)"',html).group(1)
        inputs=dict(venue='Local ficticio',time='10:00',table='2',referee='Arbitro de teste',notes='Teste de assinatura',first_a='1',first_b='0',second_a='2',second_b='1')
        assert get(guest,path,dict(inputs,csrf='invalid',mode='draft',revision=0,result_token=hidden(digital,'result_token')))[0]==422
        status,draft,_=get(guest,path,dict(inputs,csrf=csrf(digital),mode='draft',revision=0,result_token=hidden(digital,'result_token')))
        assert status==200 and db.execute('SELECT score_a FROM games WHERE id=648').fetchone()[0] is None
        fixture=draft.replace('href="admin.css"','href="../painel_php/public/admin.css"').replace('href="digital.css?', 'href="../painel_php/public/digital.css?').replace('src="digital.js?', 'src="../painel_php/public/digital.js?')
        (ROOT/'previews/digital-form.html').write_text(fixture,encoding='utf-8')
        sig=json.dumps([[[10+i*20,50+i%2*30] for i in range(12)]])
        status,receipt,_=get(guest,path,dict(inputs,csrf=csrf(draft),mode='final',revision=hidden(draft,'revision'),result_token=hidden(draft,'result_token'),consent=1,signature_a=sig,signature_b=sig,signature_referee=sig))
        assert status==200 and '<polyline' in receipt
        assert db.execute('SELECT score_a,score_b FROM games WHERE id=648').fetchone()==(3,1)
        doc=db.execute('SELECT id FROM digital_sheets WHERE game_id=648').fetchone()[0]
        assert get(client(),'sumula-digital.php?document='+str(doc))[0]==403
        assert get(client(),path)[0]==403
        assert get(normal_admin,'sumula-digital.php?document='+str(doc))[0]==200
        assert get(admin,'sumulas-digitais.php')[0]==200
        fixture=receipt.replace('href="admin.css"','href="../painel_php/public/admin.css"').replace('href="digital.css?', 'href="../painel_php/public/digital.css?').replace('src="digital.js?', 'src="../painel_php/public/digital.js?')
        (ROOT/'previews/digital-final.html').write_text(fixture,encoding='utf-8')
        assert 'Resultado já enviado' in get(client(),'arbitro.php?t='+link)[1]
        print('OK: escolha papel/digital, CSRF, rascunho, assinaturas, finalizacao e consulta privada HTTP')

        # Public workbook exports every row, ignoring page filters, without private data.
        db.execute('UPDATE players SET name=? WHERE id=1',('João & <Teste>',))
        db.execute('UPDATE players SET name=? WHERE id=2',('Antônio de Oliveira e Albuquerque Júnior',))
        db.execute('UPDATE games SET score_a=0,score_b=0,played_at=? WHERE id=2',('2024-02-29',))
        db.execute('UPDATE games SET player_a_id=1,player_b_id=3,round_number=2,turn_number=1,score_a=0,score_b=0 WHERE id=648')
        db.execute('UPDATE games SET player_a_id=3,player_b_id=1,round_number=27,turn_number=2 WHERE id=649')
        db.execute('UPDATE games SET player_a_id=2,player_b_id=3 WHERE id=650')
        db.execute("UPDATE users SET role='player',player_id=1,is_active=1 WHERE id=2")
        db.commit()
        def ids_on_page(html): return [int(i) for i in re.findall(r'<article class="admin-card" id="game-(\d+)"',html)]
        for who in (admin,normal_admin):
            assert ids_on_page(get(who,'admin.php?player=1&opponent=3')[1])==[648,649]
            assert ids_on_page(get(who,'admin.php?player=3&opponent=1')[1])==[648,649]
            assert ids_on_page(get(who,'admin.php?opponent=3')[1])==[650,648,649]
            assert ids_on_page(get(who,'admin.php?player=1&opponent=3&round=27&status=pending')[1])==[649]
            assert ids_on_page(get(who,'admin.php?player=1&opponent=3&status=played')[1])==[648]
            assert ids_on_page(get(who,'admin.php?player=1&opponent=1')[1])==[]
            assert ids_on_page(get(who,'admin.php?player=9999&opponent=3')[1])==[]
        assert ids_on_page(get(athlete,'admin.php?player=2&opponent=3')[1])==[648,649]
        assert ids_on_page(get(athlete,'admin.php?player=2&opponent=1')[1])==[]
        paginated=get(admin,'admin.php?player=1&opponent=2&status=pending')[1]
        assert 'page=2&amp;status=pending&amp;player=1&amp;opponent=2' in paginated
        panel_preview=get(admin,'admin.php?player=1&opponent=3')[1]
        for name in ['admin.css','game-filters.js','credits.css','credits.js']:
            panel_preview=panel_preview.replace('="'+name,'="../painel_php/public/'+name)
        (ROOT/'previews/filter-panel.html').write_text(panel_preview,encoding='utf-8')
        print('OK: filtro por confronto nas duas ordens, rodada/situacao, paginacao e restricao do botonista mesmo com URL manipulada')
        with guest.open(base+'exportar.php?q=nonexistent&status=pending') as response:
            assert response.headers['Content-Type']=='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            assert '.xlsx' in response.headers['Content-Disposition']
            workbook=response.read()
        with zipfile.ZipFile(io.BytesIO(workbook)) as archive:
            assert archive.testzip() is None
            ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
            sheets=ET.fromstring(archive.read('xl/workbook.xml')).findall('s:sheets/s:sheet',ns)
            assert [s.attrib['name'] for s in sheets]==['Classificação','Jogos e resultados']
            for name,count in [('sheet1.xml',30),('sheet2.xml',654)]:
                xml=ET.fromstring(archive.read('xl/worksheets/'+name))
                assert len(xml.findall('s:sheetData/s:row',ns))==count
                assert xml.find('s:autoFilter',ns) is not None
                assert xml.find('s:sheetViews/s:sheetView/s:pane',ns).attrib['ySplit']=='4'
            assert not any('externalLink' in name for name in archive.namelist())
            assert not any(b'@example.invalid' in archive.read(name) for name in archive.namelist())
        (ROOT/'previews/export-full.xlsx').write_bytes(workbook)
        payload=get(guest,'data.php')[1]
        (ROOT/'previews/export-full.json').write_text(payload.removeprefix('window.COPA_DATA = ').removesuffix(';'),encoding='utf-8')
        assert 'href="baixar.php"' in get(guest,'index.php')[1]
        print('OK: Excel publico com 26 classificados e 650 jogos, duas abas, filtros e sem dados privados')

        for selection, counts in [('all',[26,650]),('ranking',[26]),('games',[650])]:
            with guest.open(base+'exportar.php?format=xlsx&content='+selection) as response:
                with zipfile.ZipFile(io.BytesIO(response.read())) as archive:
                    sheet_names=[n for n in archive.namelist() if n.startswith('xl/worksheets/')]
                    assert [len(ET.fromstring(archive.read(n)).findall('s:sheetData/s:row',ns))-4 for n in sheet_names]==counts
            report=json.loads(get(guest,'exportar.php?format=pdf&content='+selection)[1])
            assert [len(s['rows']) for s in report['sections']]==counts
            assert all(logo.startswith('data:image/png;base64,') for logo in report['logos'])
            assert '@example.invalid' not in json.dumps(report)
            with guest.open(base+'exportar.php?format=docx&content='+selection) as response:
                assert response.headers['Content-Type']=='application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                document=response.read()
            with zipfile.ZipFile(io.BytesIO(document)) as archive:
                assert archive.testzip() is None
                for name in archive.namelist():
                    if name.endswith(('.xml','.rels')): ET.fromstring(archive.read(name))
                wn={'w':'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
                xml=ET.fromstring(archive.read('word/document.xml'))
                tables=xml.findall('w:body/w:tbl',wn)
                assert [len(t.findall('w:tr',wn))-1 for t in tables]==counts
                for table,section in zip(tables,report['sections']):
                    assert table.find('w:tr/w:trPr/w:tblHeader',wn) is not None
                    rows=[[ ''.join(c.itertext()) for c in row.findall('w:tc',wn)] for row in table.findall('w:tr',wn)]
                    assert rows==[section['headers']]+section['rows']
                assert xml.find('w:body/w:sectPr/w:pgSz',wn).attrib['{'+wn['w']+'}orient']=='landscape'
                assert archive.read('word/media/logo1.png')==(ROOT/'site/lago_de_pedra_256.png').read_bytes()
                assert archive.read('word/media/logo2.png')==(ROOT/'site/liga_mogiana_256.png').read_bytes()
                assert b'NUMPAGES' in archive.read('word/footer1.xml')
                assert not any(b'@example.invalid' in archive.read(n) for n in archive.namelist())
            (ROOT/f'previews/export-{selection}.docx').write_bytes(document)
            (ROOT/f'previews/report-{selection}.json').write_text(json.dumps(report,ensure_ascii=False),encoding='utf-8')
        for query in ['format=html','content=private','format[]=pdf','content[]=all']:
            assert get(guest,'exportar.php?'+query)[0]==400
        assert 'export-form' in get(guest,'baixar.php')[1]
        assert 'export-dialog' in get(guest,'index.php')[1]
        # Static, fictitious page for browser tests (no real DB or configuration).
        preview=get(guest,'index.php')[1]
        preview=re.sub(r'asset.php\?file=([^&"]+)(?:&v=\d+)?',r'../site/\1',preview)
        preview=preview.replace('src="data.php"','src="export-data.js"')
        for name in ['export.js','export.css','credits.js','credits.css']:
            preview=preview.replace('="'+name,'="../painel_php/public/'+name)
        (ROOT/'previews/export-page.html').write_text(preview,encoding='utf-8')
        (ROOT/'previews/export-data.js').write_text(payload,encoding='utf-8')
        print('OK: Word/PDF publicos, selecao de conteudo nos tres formatos, logos, cabecalhos e campos publicos')

        # Reserved complaints: real routes, isolated database, SMTP stub only.
        reporter=client(); stranger=client(); defender=client()
        status,report_form,_=get(reporter,'denuncia.php')
        assert status==200 and 'name="telefone"' in report_form
        form_data={'csrf':csrf(report_form),'nome':'Pessoa Fictícia','email':'relator@example.invalid','telefone':'(11) 99999-1111','envolvidos':'Jogador fictício, partida de teste','relato':'Relato fictício suficientemente detalhado para verificar o canal reservado. <script>alert(1)</script>','consentimento':'1','website':''}
        assert 'telefone com DDD' in get(reporter,'denuncia.php',dict(form_data,telefone='123'))[1]
        assert db.execute('SELECT COUNT(*) FROM complaints').fetchone()[0]==0
        assert get(athlete,'denuncias.php')[0]==403
        def multipart_report(filename,content):
            from urllib.request import Request
            boundary='copa-fixture-boundary'
            parts=[]
            for key,value in form_data.items(): parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n').encode())
            parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="anexos[]"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+content+b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            with reporter.open(Request(base+'denuncia.php',b''.join(parts),{'Content-Type':'multipart/form-data; boundary='+boundary}),timeout=10) as response:return response.read().decode()
        assert 'Use apenas PDF' in multipart_report('unsafe.php',b'<?php echo "fixture";')
        assert db.execute('SELECT COUNT(*) FROM complaints').fetchone()[0]==0
        multipart_report('evidencia.png',(ROOT/'site/lago_de_pedra_256.png').read_bytes())
        assert db.execute('SELECT COUNT(*) FROM complaint_files').fetchone()[0]==1
        case=db.execute('SELECT * FROM complaints').fetchone()
        columns=[r[1] for r in db.execute('PRAGMA table_info(complaints)')]; case=dict(zip(columns,case)); cid=case['id']
        assert case['status']=='unconfirmed'
        assert db.execute('SELECT COUNT(*) FROM complaint_mail').fetchone()[0]==1
        assert 'Relato fictício' not in get(admin,'denuncias.php?case='+cid)[1]
        mail=db.execute('SELECT body FROM complaint_mail WHERE complaint_id=?',(cid,)).fetchone()[0]
        token=re.search(r'token=([a-f0-9]{64})',mail)[1]
        status,confirm_form,_=get(reporter,'denuncia.php?mode=confirm&token='+token)
        assert db.execute('SELECT status FROM complaints WHERE id=?',(cid,)).fetchone()[0]=='unconfirmed'
        get(reporter,'denuncia.php?confirm=1',{'csrf':'wrong','action':'confirm'})
        assert db.execute('SELECT status FROM complaints WHERE id=?',(cid,)).fetchone()[0]=='unconfirmed'
        status,own_case,_=get(reporter,'denuncia.php?confirm=1',{'csrf':csrf(confirm_form),'action':'confirm'})
        assert 'Protocolo' in own_case and '&lt;script&gt;' in own_case and '<script>alert(1)</script>' not in own_case
        assert db.execute('SELECT status FROM complaints WHERE id=?',(cid,)).fetchone()[0]=='analysis'
        assert get(stranger,'denuncia.php?case='+cid)[0]==403
        assert get(stranger,'denuncia.php?mode=author&token='+'0'*64)[0]==403
        assert get(athlete,'denuncias.php?case='+cid)[0]==403
        author_mail=db.execute("SELECT body FROM complaint_mail WHERE complaint_id=? AND subject LIKE 'Protocolo%'",(cid,)).fetchone()[0]
        author_token=re.search(r'token=([a-f0-9]{64})',author_mail)[1]
        assert 'Protocolo' in get(client(),'denuncia.php?mode=author&token='+author_token)[1]
        assert db.execute("SELECT COUNT(*) FROM complaint_mail WHERE recipient='organizer@example.invalid'").fetchone()[0]==1
        def case_action(action,**extra):
            html=get(admin,'denuncias.php?case='+cid)[1]
            revision=re.search(r'name="revision" value="(\d+)"',html)[1]
            return get(admin,'denuncias.php?case='+cid,dict(csrf=csrf(html),revision=revision,action=action,**extra))
        assert 'aguarde a defesa' in case_action('close',justificativa='Justificativa fictícia suficiente para testar.')[1]
        case_action('invite',email_defesa='defesa@example.invalid',resumo_defesa='Explique os equipamentos utilizados na partida fictícia citada no relato.',prazo=date.today().isoformat())
        mail=db.execute("SELECT body FROM complaint_mail WHERE recipient='defesa@example.invalid' ORDER BY rowid DESC").fetchone()[0]
        defense_token=re.search(r'token=([a-f0-9]{64})',mail)[1]
        status,defense_form,_=get(defender,'denuncia.php?mode=defense&token='+defense_token)
        assert 'relator@example.invalid' not in defense_form and '99999-1111' not in defense_form and 'Relato fictício' not in defense_form
        assert 'Explique os equipamentos' in defense_form
        assert 'Solicitação inválida' in get(admin,'denuncias.php?case='+cid,{'csrf':'invalid','revision':'1','action':'archive'})[1]
        assert 'Informe uma data válida' in case_action('invite',email_defesa='defesa@example.invalid',resumo_defesa='Um resumo fictício suficientemente longo para convidar novamente.',prazo='2026-02-30')[1]
        for label,html in [('form',report_form),('defense',defense_form),('admin',get(admin,'denuncias.php?case='+cid)[1])]:
            for asset in ['admin.css','complaints.css','credits.css','credits.js']:
                html=html.replace('="'+asset,'="../painel_php/public/'+asset)
            (ROOT/f'previews/complaint-{label}.html').write_text(html,encoding='utf-8')
        original_scores=db.execute('SELECT id,score_a,score_b FROM games').fetchall()
        get(defender,'denuncia.php?case='+cid,{'csrf':csrf(defense_form),'mensagem':'Minha defesa fictícia explica o ocorrido e os equipamentos utilizados.'})
        assert db.execute('SELECT status FROM complaints WHERE id=?',(cid,)).fetchone()[0]=='answered'
        assert 'Minha defesa fictícia' not in get(reporter,'denuncia.php?case='+cid)[1]
        assert 'Minha defesa fictícia' in get(admin,'denuncias.php?case='+cid)[1]
        # Private attachments are delivered only to the owner/admin until explicitly shared.
        fid='e'*32
        db.execute('INSERT INTO complaint_files VALUES (?,?,?,?,?,?)',(fid,cid,'author','evidencia.pdf','application/pdf',b'%PDF-1.4 fixture'))
        db.commit()
        assert get(defender,'denuncia.php?case='+cid+'&file='+fid)[0]==404
        assert get(stranger,'denuncia.php?case='+cid+'&file='+fid)[0]==403
        case_action('share',file=fid)
        with defender.open(base+'denuncia.php?case='+cid+'&file='+fid) as response:
            assert response.headers['Content-Disposition'].startswith('attachment;') and response.read()==b'%PDF-1.4 fixture'
        # SMTP failure keeps the decision and can be retried without resending accepted notices.
        (app/'mailer.php').write_text("<?php function smtpSend(...$args): void { throw new RuntimeException('fixture SMTP failure'); }",encoding='utf-8')
        case_action('close',justificativa='Após conferir a defesa, a organização registra esta conclusão fictícia.')
        assert db.execute('SELECT status FROM complaints WHERE id=?',(cid,)).fetchone()[0]=='closed'
        assert db.execute("SELECT COUNT(*) FROM complaint_mail WHERE status='failed'").fetchone()[0]==2
        sent_attempts=db.execute("SELECT id,attempts FROM complaint_mail WHERE status='sent'").fetchall()
        (app/'mailer.php').write_text("<?php function smtpSend(...$args): void {}",encoding='utf-8')
        case_action('retry')
        assert db.execute("SELECT COUNT(*) FROM complaint_mail WHERE status='failed'").fetchone()[0]==0
        assert all(db.execute('SELECT attempts FROM complaint_mail WHERE id=?',(i,)).fetchone()[0]==n for i,n in sent_attempts)
        assert db.execute('SELECT id,score_a,score_b FROM games').fetchall()==original_scores
        assert 'não recebe novas mensagens' in get(reporter,'denuncia.php?case='+cid,{'csrf':csrf(own_case),'mensagem':'Nova mensagem fictícia depois do encerramento.'})[1]
        case_action('renew')
        assert get(reporter,'denuncia.php?case='+cid)[0]==403
        assert get(client(),'denuncia.php?mode=author&token='+author_token)[0]==403
        db.execute('UPDATE complaints SET defense_until=? WHERE id=?',(int(time.time())-1,cid));db.commit()
        assert get(defender,'denuncia.php?case='+cid)[0]==403
        assert get(client(),'denuncia.php?mode=defense&token='+defense_token)[0]==403
        assert token not in str(db.execute('SELECT * FROM audit_log').fetchall())
        for n in range(2): get(reporter,'denuncia.php',dict(form_data,email=f'other{n}@example.invalid'))
        assert 'Limite de três' in get(reporter,'denuncia.php',dict(form_data,email='fourth@example.invalid'))[1]
        # Old backup restore keeps current complaints and their original evidence.
        status,backup_form,_=get(admin,'backup.php')
        existing_backup=db.execute('SELECT COUNT(*) FROM complaints').fetchone()[0]
        get(admin,'backup.php',{'csrf':csrf(backup_form),'action':'restore','backup_name':backup_name})
        assert db.execute('SELECT COUNT(*) FROM complaints').fetchone()[0]==existing_backup
        assert db.execute('SELECT bytes FROM complaint_files WHERE id=?',(fid,)).fetchone()[0]==b'%PDF-1.4 fixture'
        # Restore an absent case, including its messages, attachments and notices.
        case_backup='copa-lago-de-pedra-20990101-010101.sqlite'
        db.execute('VACUUM INTO ?', (str(app/'backups'/case_backup),))
        for table in ['complaint_events','complaint_files','complaint_mail']: db.execute('DELETE FROM '+table+' WHERE complaint_id=?',(cid,))
        db.execute('DELETE FROM complaints WHERE id=?',(cid,));db.commit()
        # Safety backup names have one-second precision; these two restores are intentionally sequential.
        time.sleep(1.05)
        backup_form=get(admin,'backup.php')[1]
        _,restored_case_html,_=get(admin,'backup.php',{'csrf':csrf(backup_form),'action':'restore','backup_name':case_backup})
        assert db.execute('SELECT status FROM complaints WHERE id=?',(cid,)).fetchone(),restored_case_html[-5000:]
        assert db.execute('SELECT status FROM complaints WHERE id=?',(cid,)).fetchone()[0]=='closed'
        assert db.execute('SELECT bytes FROM complaint_files WHERE id=?',(fid,)).fetchone()[0]==b'%PDF-1.4 fixture'
        assert db.execute('SELECT COUNT(*) FROM complaint_mail WHERE complaint_id=?',(cid,)).fetchone()[0]>0
        print('OK: canal reservado, confirmacao POST, privacidade, defesa, prazo, anexos, SMTP/reenvio, revogacao, limites e backup; nenhum placar alterado')
        db.close()
    finally:
        if 'db' in locals(): db.close()
        server.terminate(); server.wait(timeout=10)
