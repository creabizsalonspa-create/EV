#!/usr/bin/env python3
"""Stage explicit calendar-day and non-overlapping static-slot checks."""
from pathlib import Path
import sys


def patch(root):
    root = root.resolve()
    production = Path('/home/hasio/ev-deployment')
    if root == production or production in root.parents:
        raise RuntimeError('Refusing production source')
    service = root / 'src/services/userService.js'
    target = root / 'src/services/reservationCalendarGuard.js'
    policy = root / 'src/utils/reservationCalendarPolicy.mjs'
    if target.exists() or policy.exists() or not (root / 'src/utils/reservationCapacityAudit.mjs').is_file():
        raise RuntimeError('Calendar guard already staged or capacity audit prerequisite missing')
    text = service.read_text()
    start = text.index('export const CreateBookingService =')
    end = text.index('// ─── Re-Booking / Reschedule', start)
    segment = text[start:end]
    marker = '    // ── Check if the selected slot exists in this station'
    if segment.count(marker) != 1:
        raise RuntimeError('Booking creation calendar anchor changed')
    segment = segment.replace(marker, '''    const calendar = await guardReservationCalendar(station,{date,slotStart,slotEnd});
    if(!calendar.ok)return {status:"fail",message:calendar.message};

''' + marker)
    text = 'import {guardReservationCalendar} from "./reservationCalendarGuard.js";\n' + text[:start] + segment + text[end:]
    base = Path(__file__).resolve().parents[2] / 'integrations/hasone-express'
    guard_text = (base / 'reservationCalendarGuard.js').read_text()
    policy_text = (base / 'reservationCalendarPolicy.mjs').read_text()
    service.write_text(text)
    target.write_text(guard_text)
    policy.write_text(policy_text)
    print('STAGED_EXPLICIT_RESERVATION_CALENDAR; production unchanged')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('Pass isolated Express source root')
    patch(Path(sys.argv[1]))
