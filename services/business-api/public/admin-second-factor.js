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
    const recoveryCodesPanel = $("factor-recovery-codes");
    const recoveryList = $("factor-recovery-list");
    const codesSaved = $("factor-codes-saved");
    const recoverForm = $("factor-recover");
    const resetForm = $("factor-reset");

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

    function clearRecoveryCodes() {
        recoveryList.textContent = "";
        recoveryCodesPanel.hidden = true;
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
        // Laravel logout returns 204 with no body. Treat an empty successful
        // response as success; never imply that a completed sign-out failed.
        if (response.ok && response.status === 204) return null;
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
            const resetting = state.recovery_reset_available === true;
            beginForm.hidden = state.enrolled;
            verifyForm.hidden = !state.enrolled || resetting;
            recoverForm.hidden = !state.enrolled || resetting;
            resetForm.hidden = !resetting;
            if (state.enrolled) clearSecret();
            if (state.verified && !resetting) {
                window.location.replace("/");
                return;
            }
            message(resetting
                ? "Replace the lost authenticator using your password. Old recovery codes will be revoked."
                : state.enrolled
                  ? "Enter a current authenticator code or one saved recovery code."
                  : "Confirm your password to enroll an authenticator.");
        } catch {
            beginForm.hidden = true;
            verifyForm.hidden = true;
            recoverForm.hidden = true;
            resetForm.hidden = true;
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
            const result = await request("admin/second-factor/" + endpoint, "POST", {code});
            clearSecret();
            event.target.reset();
            if (endpoint === "confirm") {
                beginForm.hidden = true;
                verifyForm.hidden = true;
                recoverForm.hidden = true;
                if (!Array.isArray(result.recovery_codes) || result.recovery_codes.length !== 8) {
                    message("Enrollment succeeded. Recovery codes could not be displayed; contact the platform operator for secure recovery.");
                    return;
                }
                recoveryList.textContent = result.recovery_codes.join("\n");
                recoveryCodesPanel.hidden = false;
                message("Save all recovery codes before continuing. They cannot be retrieved later.");
                return;
            }
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

    codesSaved.onclick = () => {
        clearRecoveryCodes();
        window.location.replace("/");
    };

    recoverForm.onsubmit = async (event) => {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        try {
            const recoveryCode = recoverForm.elements.namedItem("recovery_code").value;
            await request("admin/second-factor/recover", "POST", {recovery_code: recoveryCode});
            recoverForm.reset();
            recoverForm.hidden = true;
            verifyForm.hidden = true;
            resetForm.hidden = false;
            message("Recovery code accepted and consumed. Set up a replacement authenticator.");
        } catch (error) {
            message(error.message);
            recoverForm.elements.namedItem("recovery_code").value = "";
        } finally {
            setBusy(false);
        }
    };

    resetForm.onsubmit = async (event) => {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        try {
            const password = resetForm.elements.namedItem("password").value;
            await request("admin/second-factor/reset-after-recovery", "POST", {password});
            resetForm.reset();
            clearSecret();
            clearRecoveryCodes();
            window.location.replace("/admin/second-factor");
        } catch (error) {
            message(error.message);
        } finally {
            setBusy(false);
        }
    };

    signout.onclick = async () => {
        if (busy) return;
        setBusy(true);
        try {
            await request("logout", "POST");
            clearSecret();
            clearRecoveryCodes();
            window.location.replace("/");
        } catch {
            message("Could not confirm sign-out. Please retry while connected.");
        } finally {
            setBusy(false);
        }
    };

    window.addEventListener("pagehide", () => {
        clearSecret();
        clearRecoveryCodes();
    });
    refresh();
})();
