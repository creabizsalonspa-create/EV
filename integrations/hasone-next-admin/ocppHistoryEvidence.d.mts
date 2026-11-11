export function validOcppHistoryRow(row: unknown, expected?: {sessionId?: string|null; bookingId?: string|null}): boolean;
export function validOcppHistoryPage(response: unknown, expected?: {cursor?: string|null; limit?: number}): boolean;
