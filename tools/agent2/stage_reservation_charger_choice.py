#!/usr/bin/env python3
"""Stage explicit reservation charger identity without changing station capacity."""
from pathlib import Path
import sys


def once(text,before,after):
    if text.count(before)!=1:
        raise RuntimeError('Reservation charger source anchor changed')
    return text.replace(before,after)


def patch(root):
    root=root.resolve()
    production=Path('/home/hasio/ev-deployment')
    if root==production or production in root.parents:
        raise RuntimeError('Refusing production source')
    service=root/'src/services/userService.js';routes=root/'src/routes/routes.js';model=root/'src/models/BookingModel.js'
    target=root/'src/controllers/reservationChargers.js';helper=root/'src/utils/reservationChargerChoice.mjs'
    if target.exists() or helper.exists() or not (root/'src/services/reservationCalendarGuard.js').is_file():
        raise RuntimeError('Reservation charger staging already applied or calendar prerequisite missing')
    s=service.read_text();r=routes.read_text();m=model.read_text()
    start=s.index('export const CreateBookingService =');end=s.index('// ─── Re-Booking / Reschedule',start)
    seg=s[start:end]
    seg=once(seg,'    const calendar = await guardReservationCalendar(station,{date,slotStart,slotEnd});',
        '''    const selectedCharger = await resolveReservationCharger(station._id,body.reservedChargerId);
    if(!selectedCharger.ok)return {status:"fail",message:selectedCharger.message};
    const calendar = await guardReservationCalendar(station,{date,slotStart,slotEnd});''')
    seg=once(seg,'      stationName:      station.name,','      reservedChargerId:selectedCharger.chargerId,\n      stationName:      station.name,')
    for field,old,replacement in [('connectorType','connectorType    || "Type A"','"Not verified"'),
                                  ('energyKwh','energyKwh        || "110 kw/h"','"Not measured"'),
                                  ('chargingSlot','chargingSlot     || "Slot A"','selectedCharger.chargerId'),
                                  ('chargerType','chargerType      || "CCS - 150 kW"','"Approved charger"')]:
        if seg.count(old)!=1:raise RuntimeError('Reservation charger description anchor changed: '+field)
        seg=seg.replace(old,'selectedCharger.chargerId ? '+replacement+' : ('+old+')')
    seg=once(seg,'        bookingId:        booking._id,','        bookingId:        booking._id,\n        reservedChargerId: booking.reservedChargerId||null,')
    s='import {resolveReservationCharger} from "../controllers/reservationChargers.js";\n'+s[:start]+seg+s[end:]
    marker='const router = express.Router();'
    r=once(r,marker,'import {CustomerReservationChargers} from "../controllers/reservationChargers.js";\n'+marker+'\nrouter.get("/stations/:id/reservation-chargers", AuthVerification, CustomerReservationChargers);\n')
    marker='const BookingModel = mongoose.model("Booking", BookingSchema);'
    m=once(m,marker,'BookingSchema.add({reservedChargerId:{type:String,default:""}});\n\n'+marker)
    base=Path(__file__).resolve().parents[2]/'integrations/hasone-express'
    contents={target:(base/'reservationChargers.js').read_text(),helper:(base/'reservationChargerChoice.mjs').read_text()}
    service.write_text(s);routes.write_text(r);model.write_text(m)
    for p,text in contents.items():p.write_text(text)
    print('STAGED_CUSTOMER_RESERVATION_CHARGER; station-wide capacity/index unchanged')


if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Pass isolated Express source root')
    patch(Path(sys.argv[1]))
