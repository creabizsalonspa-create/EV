(() => {
    const key = "creabiz.theme";
    const valid = (value) => ["light", "dark", "system"].includes(value);
    const media = window.matchMedia("(prefers-color-scheme: dark)");
    let preference = "system";
    try {
        const saved = localStorage.getItem(key);
        if (valid(saved)) preference = saved;
    } catch {
        // Theme remains usable when browser storage is unavailable.
    }
    function apply() {
        document.documentElement.dataset.theme =
            preference === "system"
                ? media.matches
                    ? "dark"
                    : "light"
                : preference;
        const control = document.getElementById("theme-choice");
        if (control) control.value = preference;
    }
    apply();
    media.addEventListener("change", apply);
    window.addEventListener("storage", (event) => {
        if (event.key === key || event.key === null) {
            preference = valid(event.newValue) ? event.newValue : "system";
            apply();
        }
    });
    document.addEventListener("DOMContentLoaded", () => {
        apply();
        document
            .getElementById("theme-choice")
            ?.addEventListener("change", (event) => {
                preference = valid(event.target.value)
                    ? event.target.value
                    : "system";
                try {
                    localStorage.setItem(key, preference);
                } catch {
                    // Apply for the current page even without persistent storage.
                }
                apply();
            });
    });
})();
