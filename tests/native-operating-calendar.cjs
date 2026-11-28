process.env.EV_OPERATING_CALENDAR_NATIVE = '1';
require('./operating-calendar.cjs').main().catch(error=>{console.error(error);process.exitCode=1;});
