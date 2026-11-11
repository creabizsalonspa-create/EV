#!/usr/bin/env python3
"""Stage atomic customer cancellation/reschedule guards on an isolated copy."""
from pathlib import Path
import sys


def patch(root):
    root = root.resolve()
    production = Path('/home/hasio/ev-deployment')
    if root == production or production in root.parents:
        raise RuntimeError('Refusing production source')
    service = root / 'src/services/userService.js'
    target = root / 'src/services/bookingMutationService.js'
    helper = root / 'src/utils/bookingMutationPolicy.mjs'
    if target.exists() or helper.exists():
        raise RuntimeError('Booking mutation safety already staged')
    text = service.read_text()
    if 'uniq_active_station_date_slot' not in (root / 'src/models/BookingModel.js').read_text():
        raise RuntimeError('Atomic booking-slot uniqueness must be staged first')
    for name, replacement in [('ReBookingService', 'safeRescheduleBooking'),
                              ('CancelBookingService', 'safeCancelBooking')]:
        marker = 'export const ' + name + ' = async '
        if text.count(marker) != 1:
            raise RuntimeError('Booking service source changed: ' + name)
        start = text.index(marker)
        end = text.index('\n};', start) + 4
        args = 'user_id, bookingId, body' if name == 'ReBookingService' else 'user_id, bookingId'
        text = text[:start] + f'export const {name} = async ({args}) => {replacement}({args});' + text[end:]
    text = 'import {safeRescheduleBooking,safeCancelBooking} from "./bookingMutationService.js";\n' + text
    base = Path(__file__).resolve().parents[2] / 'integrations/hasone-express'
    controller = (base / 'bookingMutationService.js').read_text()
    policy = (base / 'bookingMutationPolicy.mjs').read_text()
    # All anchors and inputs are checked before the first write.
    service.write_text(text)
    target.write_text(controller)
    helper.write_text(policy)
    print('STAGED_ATOMIC_BOOKING_MUTATIONS; production unchanged')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('Pass isolated Express source root')
    patch(Path(sys.argv[1]))
