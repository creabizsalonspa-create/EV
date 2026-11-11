#!/usr/bin/env python3
"""Stage an authenticated OCPP report page into a copy of the V-CHARGE Next Admin.

Does not modify /home/hasio/ev-deployment or change production Next processes.
The page uses the existing admin token-bearing API client; never embeds secrets.
"""
from pathlib import Path
import sys

def patch(root):
    root=root.resolve()
    if str(root)=='/home/hasio/ev-deployment' or str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Refusing production Next application')
    sidebar=root/'components/Sidebar.tsx'
    layout=root/'app/(admin)/layout.tsx'
    api=root/'lib/api.ts'
    page=root/'app/(admin)/ocpp-sessions/page.tsx'
    src=Path(__file__).resolve().parents[2]/'integrations/hasone-next-admin/ocpp-sessions-page.tsx'
    if not all(p.is_file() for p in (sidebar,layout,api,src)) or page.exists():
        raise RuntimeError('Staged Next admin incomplete or report route already exists')
    text=sidebar.read_text(encoding='utf8')
    anchor='  { href: "/bookings",       label: "Bookings",        icon: BookOpen },'
    link='  { href: "/ocpp-sessions",   label: "OCPP Sessions",   icon: Zap },'
    if text.count(anchor)!=1 or 'href: "/ocpp-sessions"' in text:
        raise RuntimeError('Sidebar source changed or link already installed')
    text=text.replace(anchor,anchor+'\n'+link)
    page.parent.mkdir(parents=True,exist_ok=False)
    page.write_text(src.read_text(encoding='utf8'),encoding='utf8')
    sidebar.write_text(text,encoding='utf8')
    print('STAGED_NEXT_OCPP_SESSION_DASHBOARD_ADDED; production unaffected')

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_next_ocpp_report_page.py STAGED_NEXT_ADMIN_DIRECTORY')
    patch(Path(sys.argv[1]))
