#!/usr/bin/env python3
"""Run PR50's disposable API/OCPP harness with the real Flutter history client.

Usage: run_mobile_history_api.py PR50_RUNNER STAGED_EXPRESS DEPENDENCIES
       STAGED_OCPP_HANDLER FLUTTER_EXECUTABLE CUSTOMER_APP
The original harness/source is never rewritten. No live API is accepted.
"""
from pathlib import Path
import os
import subprocess
import sys
import tempfile


def main(args):
    if len(args) != 6:
        raise SystemExit(__doc__)
    runner, source, dependencies, handler, flutter, app = map(lambda p: Path(p).resolve(), args)
    if not runner.is_file() or not flutter.is_file() or not (app / "test/source_history_api_test.dart").is_file():
        raise SystemExit("Missing PR50 runner, Flutter executable or mobile API test")
    production = Path("/home/hasio/ev-deployment")
    if source == production or production in source.parents or handler == production or production in handler.parents:
        raise SystemExit("Only staged Express and OCPP copies are allowed")
    text = runner.read_text()
    marker = "  const charger16='agent2-ocpp16-test',token16='0123456789ABCDEF0123';"
    if text.count(marker) != 1 or "const historyLink=" not in text:
        raise SystemExit("PR50 harness contract changed; no test executed")
    hook = """
  await check('actual Flutter consumes private disposable source history',async()=>{
    const child=spawn(process.env.AGENT2_HISTORY_FLUTTER,
      ['test','--no-pub','test/source_history_api_test.dart','--reporter','expanded'],{
        cwd:process.env.AGENT2_HISTORY_APP,stdio:'inherit',
        env:{...process.env,AGENT2_HISTORY_API_URL:base,
          AGENT2_HISTORY_TOKEN:bearer,AGENT2_HISTORY_FOREIGN_TOKEN:foreignBearer,
          AGENT2_HISTORY_EXPECTED_SESSION:String(historyLink._id)}
      });
    const result=await new Promise((done,reject)=>{
      child.once('error',reject);child.once('exit',(code,signal)=>done({code,signal}));
    });
    assert.equal(result.code,0,'Actual Flutter history client failed');
    assert.equal(result.signal,null);
  });
"""
    env = dict(os.environ, AGENT2_HISTORY_FLUTTER=str(flutter), AGENT2_HISTORY_APP=str(app),
               AGENT2_REAL_ADMIN_UI_URL="")
    temporary = None
    try:
        # Keep relative JS imports beside the supplied, unchanged harness.
        with tempfile.NamedTemporaryFile(mode="w", prefix=".mobile-history-", suffix=".mjs",
                                         dir=runner.parent, delete=False) as output:
            temporary = Path(output.name)
            output.write(text.replace(marker, hook + marker))
        result = subprocess.run(["node", str(temporary), str(source), str(dependencies), str(handler)], env=env)
        return result.returncode
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
