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
        document.getElementById("status").textContent = d.message;
        if (r.ok) e.target.reset();
    } catch {
        document.getElementById("status").textContent =
            "Network error. Please retry.";
    }
};
