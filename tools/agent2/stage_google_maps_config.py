#!/usr/bin/env python3
"""Stage removal of an inherited hardcoded Google Maps key in Express source.

Read a restricted GOOGLE_MAPS_API_KEY from process environment only.
No key values are printed, logged, stored in GitHub, or changed in production.
"""
from pathlib import Path
import re,sys

def patch(root):
    root=root.resolve()
    if str(root)=='/home/hasio/ev-deployment' or str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Refusing live deployed source')
    service=root/'src/services/userService.js'
    if not service.is_file():raise RuntimeError('Expected staged Express service missing')
    src=service.read_text(encoding='utf8')
    pattern=r'const GOOGLE_API_KEY = "[^"\n]*";'
    found=re.findall(pattern,src)
    if len(found)!=1 or 'process.env.GOOGLE_MAPS_API_KEY' in src:
        raise RuntimeError('Unexpected Maps configuration; inspect source manually')
    updated=re.sub(pattern,'const GOOGLE_API_KEY = process.env.GOOGLE_MAPS_API_KEY || "";',src,count=1)
    before='''const getGoogleDistance = async (originLat, originLng, destLat, destLng) => {
  try {'''
    after='''const getGoogleDistance = async (originLat, originLng, destLat, destLng) => {
  if (!GOOGLE_API_KEY) return null;
  try {'''
    if updated.count(before)!=1:raise RuntimeError('Maps distance helper changed')
    updated=updated.replace(before,after)
    service.write_text(updated,encoding='utf8')
    print('STAGED_GOOGLE_MAPS_KEY_ENV_ONLY; production unchanged')
if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_google_maps_config.py STAGED_SOURCE_COPY')
    patch(Path(sys.argv[1]))
