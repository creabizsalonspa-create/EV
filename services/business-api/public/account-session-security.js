"use strict";

window.EvAccountSessions = (() => {
    const root = document.getElementById("account-session-security");
    let generation = 0;
    let request = null;

    function node(tag, value) {
        const item = document.createElement(tag);
        if (value !== undefined) item.textContent = String(value);
        return item;
    }

    function clear() {
        generation++;
        request = null;
        root.replaceChildren();
        root.hidden = true;
    }

    function setActor(actor, api, onRevoked) {
        clear();
        root.hidden = !["customer", "vendor"].includes(actor?.role);
        if (root.hidden) return;

        const epoch = generation;
        let submitting = false;
        request = api;
        const form = node("form");
        form.setAttribute("aria-label", "Revoke all account sessions");
        const label = node("label");
        label.append(node("span", "Confirm current password / تأكيد كلمة المرور"));
        const password = node("input");
        password.type = "password";
        password.required = true;
        password.maxLength = 128;
        password.autocomplete = "current-password";
        password.setAttribute("aria-label", "Current account password");
        label.append(password);

        const revoke = node("button", "Sign out everywhere / تسجيل الخروج من جميع الأجهزة");
        revoke.type = "submit";
        const status = node("p",
            "Revokes your browser sessions, remember-me credentials and mobile tokens. You must sign in again on every device.");
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        form.append(label, revoke);
        root.append(form, status);

        form.onsubmit = async event => {
            event.preventDefault();
            if (epoch !== generation || !request || submitting) return;
            if (!password.value) {
                status.textContent = "Enter your current account password.";
                return;
            }

            submitting = true;
            revoke.disabled = true;
            const credential = password.value;
            password.value = "";
            try {
                await request("account/sessions/revoke-all", "POST", {password: credential});
                if (epoch !== generation) return;
                clear();
                // A server-side revocation invalidates this browser as well.
                // Reload into a fresh anonymous context; never keep cached
                // private account components or create a replacement session.
                onRevoked();
            } catch {
                if (epoch === generation) {
                    status.textContent =
                        "Unable to revoke sessions. Confirm your current password or refresh your account status.";
                }
            } finally {
                submitting = false;
                revoke.disabled = false;
            }
        };
    }

    return {setActor, clear};
})();
