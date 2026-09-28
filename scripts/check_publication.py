"""Confere o índice Git sem imprimir valores sensíveis encontrados."""
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
GIT = ['git', '-c', 'safe.directory=' + ROOT.as_posix()]
paths = subprocess.check_output(GIT + ['diff', '--cached', '--name-only', '--diff-filter=ACM', '-z'], cwd=ROOT).decode().split('\0')
patterns = [
    rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
    rb'gh[pousr]_[A-Za-z0-9]{30,}',
    rb'github_pat_[A-Za-z0-9_]{30,}',
    rb'AKIA[A-Z0-9]{16}',
    rb'xox[baprs]-[A-Za-z0-9-]{20,}',
]
errors = []
checked = 0
for name in filter(None, paths):
    path = Path(name)
    if (path.name in {'config.php', 'data.js', 'seed.php'} or
            path.suffix.lower() in {'.sqlite', '.db', '.zip', '.xlsx', '.docx', '.csv', '.pdf', '.log'} or
            any(part in {'backups', 'previews', '.venv'} for part in path.parts) or
            path.name.startswith('.env') or '.sqlite-' in path.name):
        errors.append(name + ': arquivo privado ou gerado')
    content = subprocess.check_output(GIT + ['show', ':' + name], cwd=ROOT)
    if any(re.search(pattern, content) for pattern in patterns):
        errors.append(name + ': possível credencial; revisar localmente')
    checked += 1
if errors:
    print('\n'.join(errors))
    sys.exit(1)
print(f'OK: {checked} arquivos preparados; nenhum arquivo privado ou padrão de credencial detectado.')
print('Faça também a revisão manual. Esta conferência não garante ausência de todos os tipos de segredo.')
