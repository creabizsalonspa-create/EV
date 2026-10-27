/** @typedef {{id:number,name:string,role:'customer'|'vendor'|'admin',email_verified_at:?string}} Account */
/** @typedef {{id:number,name:string,source:string,evidence:string,availability:string,reservable:boolean,connector_type:string,power_kw:number,country:string,currency:string,latitude:string,longitude:string}} Station */
/** @template T @typedef {{data:T[],current_page:number,last_page:number,total:number,next_page_url:?string,prev_page_url:?string}} Page */
class ApiError extends Error {
    constructor(message, status = 0, errors = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}
class EvApi {
    constructor(token) {
        this.token = token;
    }
    async request(path, method = "GET", data, signal) {
        const timeout = AbortSignal.timeout(20000);
        let response;
        try {
            response = await fetch("/api/" + path, {
                method,
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": this.token,
                },
                body: data ? JSON.stringify(data) : undefined,
                signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
            });
        } catch (error) {
            if (signal?.aborted) throw error;
            throw new ApiError(
                navigator.onLine
                    ? "Connection interrupted. Refresh to check the outcome before trying an action again."
                    : "You are offline. Reconnect to refresh your account.",
            );
        }
        if (response.status === 204) return null;
        let result;
        try {
            result = await response.json();
        } catch {
            throw new ApiError(
                "The service returned an unreadable response. Please try again later.",
                response.status,
            );
        }
        if (!response.ok) {
            const message =
                response.status === 401
                    ? "Your session has ended. Please sign in again."
                    : response.status === 419
                      ? "Your security session expired. Reload this page before trying again."
                      : response.status === 429
                        ? "Too many requests. Please wait before trying again."
                        : result.message || "Request failed. Please retry.";
            throw new ApiError(message, response.status, result.errors);
        }
        return result;
    }
}
