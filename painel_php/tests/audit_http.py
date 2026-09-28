"""Testa rotas reais numa cópia temporária, sem banco real nem envio de e-mail."""
from pathlib import Path
import http.cookiejar
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
    for name in ('db.php', 'audit.php', 'version.php', 'notifications.php', 'login_security.php'):
        shutil.copyfile(ROOT/'painel_php'/name, app/name)
    for path in (ROOT/'painel_php/public').glob('*'):
        if path.is_file(): shutil.copyfile(path, app/'public'/path.name)
    (app/'config.php').write_text("<?php return ['database'=>__DIR__.'/test.sqlite','timezone'=>'America/Sao_Paulo','admin_password'=>'test-only','notification_email'=>'organizer@example.invalid','base_url'=>'http://example.invalid','backup_directory'=>__DIR__.'/backups'];", encoding='utf-8')
    (app/'mailer.php').write_text("<?php function smtpSend(...$args): void {}", encoding='utf-8')
    (root/'seed.php').write_text("""<?php
    require __DIR__.'/painel_php/db.php'; initialiseDatabase();
    for ($i=1;$i<=26;$i++) db()->prepare('INSERT INTO players VALUES (?,?)')->execute([$i,'Jogador '.$i]);
    for ($i=1;$i<=650;$i++) db()->prepare('INSERT INTO games(id,round_number,game_number,turn_number,player_a_id,player_b_id) VALUES (?,1,?,1,1,2)')->execute([$i,$i]);
    foreach ([[1,'Gestor',null],[2,'Atleta',1]] as [$id,$name,$player]) db()->prepare('INSERT INTO users(id,name,username,email,password_hash,player_id,is_active,created_at) VALUES (?,?,?,?,?,?,1,?)')->execute([$id,$name,$name,$name.'@example.invalid',password_hash('test-only',PASSWORD_DEFAULT),$player,date('c')]);
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
    def csrf(html): return re.search(r'name="csrf" value="([^"]+)"',html).group(1)
    try:
        guest=client()
        for _ in range(50):
            try: get(guest,'admin.php'); break
            except URLError: time.sleep(.1)
        status,login_html,_=get(guest,'admin.php')
        assert 'Voltar à área pública' in login_html and 'Versão 1.34' in login_html
        assert login_html.count('id="login"')==1
        status,guide_html,_=get(guest,'guia.php')
        assert status==200 and '<svg' in guide_html and 'Versão 1.34' in guide_html
        status,public_html,_=get(guest,'index.php')
        assert status==200 and 'href="guia.php"' in public_html and 'Versão 1.34' in public_html
        for page in (login_html, guide_html, public_html):
            assert page.count('id="copa-credits"') == 1
            assert 'julio@projetos.tec.br' in page and 'data-copa-credits' in page
        def credits_preview(html):
            return html.replace('href="credits.css?', 'href="../painel_php/public/credits.css?').replace('src="credits.js?', 'src="../painel_php/public/credits.js?')
        guide_html = credits_preview(guide_html)
        login_html = credits_preview(login_html)
        (ROOT/'previews/guia.html').write_text(guide_html.replace('href="guia.css?', 'href="../painel_php/public/guia.css?'),encoding='utf-8')
        (ROOT/'previews/login.html').write_text(login_html.replace('href="admin.css?', 'href="../painel_php/public/admin.css?'),encoding='utf-8')
        print('OK: retorno publico, guia sem login e versao 1.34 consistente')
        status,html,url=get(guest,'auditoria.php')
        assert url.endswith('admin.php') and 'Histórico de ações' not in html
        athlete=client();get(athlete,'admin.php',{'login':'Atleta','password':'test-only'})
        assert get(athlete,'auditoria.php')[0]==403
        print('OK: visitante e botonista sem acesso à auditoria')
        normal_admin=client();get(normal_admin,'admin.php',{'login':'Gestor','password':'test-only'})
        assert get(normal_admin,'usuarios.php')[0]==403
        admin=client();status,html,_=get(admin,'admin.php',{'login':'','password':'test-only'})
        status,html,_=get(admin,'admin.php',{'csrf':csrf(html),'game_id':1,'score_a':2,'score_b':1,'played_at':'2026-09-20'})
        assert status==200
        status,html,_=get(admin,'usuarios.php')
        status,html,_=get(admin,'usuarios.php',{'csrf':csrf(html),'action':'create','name':'Teste <script>alert(1)</script>','username':'novo','email':'teste@example.invalid','player_id':''})
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
        get(admin,'admin.php',{'csrf':csrf(form),'game_id':1,'score_a':5,'score_b':0,'played_at':'2026-09-21'})
        before=db.execute('SELECT COUNT(*) FROM audit_log').fetchone()[0]
        status,form,_=get(admin,'backup.php')
        status,form,_=get(admin,'backup.php',{'csrf':csrf(form),'action':'restore','backup_name':backup_name})
        assert status==200 and 'Backup restaurado.' in form
        assert db.execute('SELECT score_a FROM games WHERE id=1').fetchone()[0]==2
        assert db.execute('SELECT COUNT(*) FROM audit_log').fetchone()[0]==before+1
        with admin.open(base+'backup.php?'+urlencode({'download':backup_name})) as response:
            assert response.read(16).startswith(b'SQLite format 3')
        assert db.execute("SELECT COUNT(*) FROM audit_log WHERE action='Backup baixado'").fetchone()[0]==1
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
        assert get(admin,'usuarios.php',{'csrf':'invalid','action':'privilege','user_id':2,'role':'admin','previous_player':'1'})[0]==400
        status,access_form,_=get(admin,'usuarios.php',{'csrf':csrf(access_form),'action':'privilege','user_id':2,'role':'admin','previous_player':'1'})
        assert db.execute('SELECT player_id FROM users WHERE id=2').fetchone()[0] is None
        assert get(athlete,'auditoria.php')[0]==200
        assert get(athlete,'usuarios.php')[0]==403
        status,access_form,_=get(admin,'usuarios.php',{'csrf':csrf(access_form),'action':'privilege','user_id':2,'role':'player','player_id':2,'previous_player':''})
        assert db.execute('SELECT player_id FROM users WHERE id=2').fetchone()[0]==2
        assert get(athlete,'auditoria.php')[0]==403
        assert get(athlete,'backup.php')[0]==403
        status,own_form,_=get(athlete,'botonistas.php')
        assert 'value="Jogador 2"' in own_form
        assert get(athlete,'botonistas.php',{'csrf':csrf(own_form),'player_id':1,'old_name':'Mateus Corrigido','name':'Outro'})[0]==403
        for params in [{'role':'player','player_id':9999,'previous_player':'2'}, {'role':'master','previous_player':'2'}, {'role':'admin','previous_player':'1'}]:
            status,access_form,_=get(admin,'usuarios.php',dict(csrf=csrf(access_form),action='privilege',user_id=2,**params))
            assert db.execute('SELECT player_id FROM users WHERE id=2').fetchone()[0]==2
        assert db.execute("SELECT COUNT(*) FROM audit_log WHERE action='Privilégios alterados'").fetchone()[0]==2
        status,backup_form,_=get(normal_admin,'backup.php')
        assert status==200
        assert get(normal_admin,'backup.php',{'csrf':csrf(backup_form),'action':'restore','backup_name':backup_name})[0]==403
        db.execute("CREATE TRIGGER fail_privilege_log BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT, 'teste'); END");db.commit()
        status,access_form,_=get(admin,'usuarios.php',{'csrf':csrf(access_form),'action':'privilege','user_id':2,'role':'admin','previous_player':'2'})
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
        db.execute('DROP TRIGGER fail_privilege_log')
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
        status,body,_=get(client(),'sumula-login.php',{'login':'Gestor@example.invalid','password':'senha-errada-secreta','game_id':10})
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
        db.close()
    finally:
        if 'db' in locals(): db.close()
        server.terminate(); server.wait(timeout=10)
