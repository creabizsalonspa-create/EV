#!/usr/bin/env python3
"""Atomic station-slot booking index patch for a disposable Express/Mongo source copy.

No production edits. Checks current source shape before any file is changed.
The index rejects simultaneous bookings for an active slot, while Cancelled
bookings stop occupying that date/time. Existing data must be checked for
duplicates before this index is deployed.
"""
from pathlib import Path
import sys

def patch(root):
    root=root.resolve()
    if str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Refusing production source directory')
    booking=root/'src/models/BookingModel.js'
    service=root/'src/services/userService.js'
    if not booking.is_file() or not service.is_file():
        raise RuntimeError('A complete staged Express source copy is required')
    b=booking.read_text(encoding='utf8')
    s=service.read_text(encoding='utf8')
    marker='const BookingModel = mongoose.model("Booking", BookingSchema);'
    if b.count(marker)!=1 or 'uniq_active_station_date_slot' in b:
        raise RuntimeError('Booking model source changed or index already applied')
    b=b.replace(marker,'''// Atomic uniqueness for active date/time reservations. Cancelled slots are released.
BookingSchema.index(
  { stationId:1, date:1, slotStart:1, slotEnd:1 },
  {
    unique:true,
    name:"uniq_active_station_date_slot",
    partialFilterExpression: {
      status: { $in:["Upcoming","Completed"] },
      slotStart: { $gt:"" },
      slotEnd: { $gt:"" },
    },
  }
);

'''+marker)
    start=s.find('export const CreateBookingService = async (user_id, body) => {')
    end=s.find('// ─── Re-Booking / Reschedule',start)
    if start<0 or end<0 or start>=end:
        raise RuntimeError('Could not isolate booking creation service')
    seg=s[start:end]
    before='''  } catch (e) {
    return { status: "fail", message: e.toString() };
  }
};'''
    after='''  } catch (e) {
    if (e?.code === 11000) {
      return { status: "fail", message: "Selected time slot was just reserved. Choose another." };
    }
    return { status: "fail", message: "Could not create booking." };
  }
};'''
    if seg.count(before)!=1:
        raise RuntimeError('Booking create catch handler has changed')
    s=s[:start]+seg.replace(before,after)+s[end:]
    booking.write_text(b,encoding='utf8')
    service.write_text(s,encoding='utf8')
    print('STAGED_BOOKING_INDEX_PATCHED; production unchanged')

if __name__=='__main__':
    if len(sys.argv)!=2:
        raise SystemExit('Usage: stage_booking_slot_uniqueness.py STAGED_SOURCE_COPY')
    patch(Path(sys.argv[1]))
