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
class SessionChangedError extends Error {
    constructor() {
        super("The account context changed.");
        this.name = "SessionChangedError";
    }
}
class EvApi {
    constructor(token) {
        this.token = token;
        this.sessionEpoch = 0;
    }
    invalidateSession() {
        return ++this.sessionEpoch;
    }
    async request(path, method = "GET", data, signal) {
        const epoch = this.sessionEpoch;
        const publicRead =
            method === "GET" &&
            ["stations", "regions", "roaming/locations"].includes(
                path.split("?")[0],
            );
        const current = () => {
            if (!publicRead && epoch !== this.sessionEpoch)
                throw new SessionChangedError();
        };
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
            current();
            if (signal?.aborted) throw error;
            throw new ApiError(
                navigator.onLine
                    ? "Connection interrupted. Refresh to check the outcome before trying an action again."
                    : "You are offline. Reconnect to refresh your account.",
            );
        }
        current();
        if (response.status === 204) return null;
        let result;
        try {
            result = await response.json();
        } catch {
            current();
            throw new ApiError(
                "The service returned an unreadable response. Please try again later.",
                response.status,
            );
        }
        current();
        if (!response.ok) {
            const message =
                response.status === 401
                    ? "Your session has ended. Please sign in again."
                    : response.status === 419
                      ? "Your security session expired. Reload this page before trying again."
                      : response.status === 429
                        ? "Too many requests. Please wait before trying again."
                        : result?.message || "Request failed. Please retry.";
            throw new ApiError(message, response.status, result?.errors);
        }
        return result;
    }
}
