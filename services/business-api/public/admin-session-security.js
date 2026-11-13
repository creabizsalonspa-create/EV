"use strict";

window.EvAdminSessions = (() => {
    const root = document.getElementById("admin-session-security");
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
    }

    function setActor(actor, api) {
        clear();
        if (actor?.role !== "admin") return;

        const epoch = generation;
        request = api;
        const form = node("form");
        form.setAttribute("aria-label", "Revoke all Administrator sessions");
        const label = node("label");
        label.append(node("span", "Confirm current password / تأكيد كلمة المرور"));
        const password = node("input");
        password.type = "password";
        password.required = true;
        password.maxLength = 128;
        password.autocomplete = "current-password";
        password.setAttribute("aria-label", "Current Admin password");
        label.append(password);

        const revoke = node("button", "Sign out everywhere / تسجيل الخروج من جميع الأجهزة");
        revoke.type = "submit";
        const status = node("p",
            "Revokes your Admin browser sessions, remember-me credentials and mobile tokens. You must sign in again on every device.");
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        form.append(label, revoke);
        root.append(form, status);

        form.onsubmit = async event => {
            event.preventDefault();
            if (epoch !== generation || !request) return;
            if (!password.value) {
                status.textContent = "Enter your current account password.";
                return;
            }
            if (!window.confirm("Sign out all your devices and revoke existing Admin credentials?"))
                return;

            revoke.disabled = true;
            const credential = password.value;
            password.value = "";
            try {
                await request("admin/sessions/revoke-all", "POST", {password: credential});
                if (epoch !== generation) return;
                clear();
                // A server-side revocation invalidates this browser as well.
                // Reload into a fresh anonymous context; never keep cached
                // private Admin components or create a replacement session.
                if (typeof signalSessionRevocation === "function") signalSessionRevocation();
                window.location.assign("/?sessions=revoked");
            } catch {
                if (epoch === generation) {
                    status.textContent =
                        "Unable to revoke sessions. Confirm your current password or refresh your account status.";
                }
            } finally {
                revoke.disabled = false;
            }
        };
    }

    return {setActor, clear};
})();
