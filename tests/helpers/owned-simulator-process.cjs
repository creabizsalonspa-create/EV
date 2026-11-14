const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
function procIdentity(text) {
    const end = text.lastIndexOf(')');
    assert(/^\d+ \(/.test(text) && end >= 0, 'Process identity requires review.');
    const fields = text.slice(end + 1).trim().split(/\s+/);
    assert(fields.length >= 20 && /^[0-9]+$/.test(fields[19]), 'Process identity requires review.');
    return {state:fields[0],start_ticks:fields[19]};
}
function verifies({command, state, start_ticks}, expected, root, manifest) {
    if (expected != null) assert(start_ticks === expected, 'PID belongs to another process.');
    if (['Z','X','x'].includes(state)) return false;
    // Linux can release argv before publishing zombie state. A captured birth
    // marker still identifies our live/exiting child; keep waiting for exit.
    if (command.length === 0 && expected != null && start_ticks === expected) return true;
    assert(command.includes(path.join(root,'scripts/client-demo-charger.cjs')) && command.includes(manifest), 'PID belongs to another process.');
    return true;
}
function startTicks(pid) {
    if (!fs.existsSync('/proc')) return null;
    try { return procIdentity(fs.readFileSync(`/proc/${pid}/stat`,'utf8')).start_ticks; }
    catch (error) { if (error.code === 'ENOENT' || error.code === 'ESRCH') return null; throw error; }
}
function owned(pid, manifest, root, expected = null) {
    if (!Number.isInteger(pid) || pid <= 1) return false;
    try {
        process.kill(pid,0);
        if (fs.existsSync('/proc')) {
            const before = procIdentity(fs.readFileSync(`/proc/${pid}/stat`,'utf8'));
            const command = fs.readFileSync(`/proc/${pid}/cmdline`,'utf8');
            const after = procIdentity(fs.readFileSync(`/proc/${pid}/stat`,'utf8'));
            assert(before.start_ticks === after.start_ticks, 'PID belongs to another process.');
            return verifies({...after,command},expected,root,manifest);
        }
        const output = execFileSync('ps',['-p',String(pid),'-o','stat=','-o','args='],{encoding:'utf8',stdio:['ignore','pipe','ignore']}).trim();
        const space = output.search(/\s/);
        return verifies({state:output[0],command:space<0?'':output.slice(space+1),start_ticks:null},expected,root,manifest);
    } catch (error) {
        if (error.code === 'ENOENT' || error.code === 'ESRCH' || (error.status === 1 && !String(error.stdout || '').trim())) return false;
        throw error;
    }
}
module.exports = {procIdentity,verifies,startTicks,owned};
