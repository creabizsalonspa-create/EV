"use strict";

// The server is authoritative for Admin authorization. This page stores no
// authenticator secret, code, or user information in browser storage.
(() => {
    const $ = (id) => document.getElementById(id);
    const status = $("factor-status");
    const beginForm = $("factor-begin");
    const secretPanel = $("factor-secret");
    const confirmForm = $("factor-confirm");
    const verifyForm = $("factor-verify");
    const keyText = $("factor-setup-key");
    const signout = $("factor-signout");

    let csrf = document.querySelector('meta[name="csrf-token"]').content;
    let busy = false;

    function message(text) {
        status.textContent = text;
    }

    function clearSecret() {
        keyText.textContent = "";
        secretPanel.hidden = true;
        confirmForm.reset();
    }

    function setBusy(value) {
        busy = value;
        document.querySelectorAll("button,input").forEach((node) => {
            node.disabled = value;
        });
    }

    async function request(path, method = "GET", body) {
        const response = await fetch("/api/" + path, {
            method,
            credentials: "same-origin",
            cache: "no-store",
            referrerPolicy: "no-referrer",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": csrf,
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (response.status === 401 || response.status === 419) {
            clearSecret();
            message("Your session expired. Sign in again.");
            throw new Error("Session expired");
        }
        let result;
        try {
            result = await response.json();
        } catch {
            throw new Error("Unexpected server response");
        }
        if (!response.ok) {
            throw new Error(
                response.status === 429
                    ? "Too many verification attempts. Try again later."
                    : "Could not verify. Check your details and try again.",
            );
        }
        return result;
    }

    async function refresh() {
        try {
            const state = await request("admin/second-factor/status");
            beginForm.hidden = state.enrolled;
            verifyForm.hidden = !state.enrolled;
            if (state.enrolled) clearSecret();
            if (state.verified) {
                window.location.replace("/");
                return;
            }
            message(state.enrolled
                ? "Enter the current code from your authenticator app."
                : "Confirm your password to enroll an authenticator.");
        } catch {
            beginForm.hidden = true;
            verifyForm.hidden = true;
            message("Unable to confirm the Admin session. Sign in again.");
        }
    }

    beginForm.onsubmit = async (event) => {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        clearSecret();
        try {
            const password = beginForm.elements.namedItem("password").value;
            const result = await request("admin/second-factor/begin", "POST", {password});
            beginForm.reset();
            beginForm.hidden = true;
            keyText.textContent = result.secret;
            secretPanel.hidden = false;
            message("Add the key to your authenticator and enter its current six-digit code within ten minutes.");
        } catch (error) {
            message(error.message);
        } finally {
            setBusy(false);
        }
    };

    async function submitCode(event, endpoint) {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        try {
            const code = event.target.elements.namedItem("code").value;
            await request("admin/second-factor/" + endpoint, "POST", {code});
            clearSecret();
            event.target.reset();
            window.location.replace("/");
        } catch (error) {
            message(error.message);
            event.target.elements.namedItem("code").value = "";
        } finally {
            setBusy(false);
        }
    }

    confirmForm.onsubmit = (event) => submitCode(event, "confirm");
    verifyForm.onsubmit = (event) => submitCode(event, "verify");

    signout.onclick = async () => {
        if (busy) return;
        setBusy(true);
        try {
            await request("logout", "POST");
            clearSecret();
            window.location.replace("/");
        } catch {
            message("Could not confirm sign-out. Please retry while connected.");
        } finally {
            setBusy(false);
        }
    };

    window.addEventListener("pagehide", clearSecret);
    refresh();
})();
