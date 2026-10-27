document.getElementById("reset-password").onsubmit = async (e) => {
    e.preventDefault();
    try {
        const r = await fetch("/api/reset-password", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": document.querySelector("meta[name=csrf-token]")
                    .content,
            },
            body: JSON.stringify(Object.fromEntries(new FormData(e.target))),
        });
        const d = await r.json();
        EvLocale.bind(
            document.getElementById("status"),
            r.ok
                ? "Password reset. Sign in with your new password."
                : r.status === 422
                  ? "Please check the entered values."
                  : r.status === 429
                    ? "Too many requests. Please wait before trying again."
                    : "Network error. Please retry.",
        );
        if (r.ok) e.target.reset();
    } catch {
        EvLocale.bind(
            document.getElementById("status"),
            "Network error. Please retry.",
        );
    }
};
