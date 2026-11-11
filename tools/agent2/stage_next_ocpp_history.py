#!/usr/bin/env python3
"""Stage the read-only source history UI into a copied Next Admin."""
from pathlib import Path
import sys
def patch(root):
    root=root.resolve()
    if str(root)=="/home/hasio/ev-deployment" or str(root).startswith("/home/hasio/ev-deployment/"):
        raise RuntimeError("Refusing deployed Admin")
    sidebar=root/"components/Sidebar.tsx"
    target=root/"app/(admin)/ocpp-history/page.tsx"
    base=Path(__file__).resolve().parents[2]/"integrations/hasone-next-admin"
    src=base/"ocpp-history-page.tsx"
    dependencies={name:(base/name).read_bytes() for name in ["ocppHistoryEvidence.mjs","ocppHistoryEvidence.d.mts"]}
    text=sidebar.read_text()
    anchor='  { href: "/ocpp-sessions",   label: "OCPP Sessions",   icon: Zap },'
    if text.count(anchor)!=1 or target.exists() or any((target.parent/name).exists() for name in dependencies):
        raise RuntimeError("History UI already staged or anchors changed")
    source=src.read_bytes()
    text=text.replace(anchor,anchor+'\n  { href: "/ocpp-history",    label: "Charging History", icon: Zap },')
    target.parent.mkdir(parents=True)
    target.write_bytes(source)
    for name,content in dependencies.items(): (target.parent/name).write_bytes(content)
    sidebar.write_text(text)
    print("STAGED_SOURCE_HISTORY_UI; production unchanged")
if __name__=="__main__":
    if len(sys.argv)!=2: raise SystemExit("Pass isolated Next root")
    patch(Path(sys.argv[1]))
