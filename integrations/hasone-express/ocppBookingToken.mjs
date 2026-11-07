/**
 * One-time booking credentials fit the OCPP 1.6 idTag wire limit.
 * Ten CSPRNG bytes produce 20 uppercase hexadecimal characters (80 bits).
 * Full SHA-256 digests, scoped expiry and single-use claims remain unchanged.
 */
import {randomBytes} from 'node:crypto';
export function createOcppBookingToken(){
 return randomBytes(10).toString('hex').toUpperCase();
}

