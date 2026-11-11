export function validOcppWorkflowState(response: unknown, expected: {bookingId: string; stationId?: string|null}): boolean;
export function validOcppAuthorizationReceipt(response: unknown, expected: {bookingId: string; chargerId: string; resourceScope?: unknown; reissued?: boolean}): boolean;
