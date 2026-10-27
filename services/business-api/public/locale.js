(() => {
    const messages = window.EV_MESSAGES;
    let language = "en";
    try {
        if (localStorage.getItem("creabiz.locale") === "ar") language = "ar";
    } catch {}
    function text(key, values = {}) {
        let result = Object.hasOwn(messages, key)
            ? messages[key][language]
            : key;
        for (const [name, raw] of Object.entries(values)) {
            const value =
                raw &&
                typeof raw === "object" &&
                Object.hasOwn(raw, "translate")
                    ? text(raw.translate)
                    : String(raw);
            result = result.replaceAll("{" + name + "}", value);
        }
        return result;
    }
    function update(node) {
        if (node.dataset.i18n) {
            let values = {};
            try {
                values = JSON.parse(node.dataset.i18nValues || "{}");
            } catch {}
            const value = text(node.dataset.i18n, values);
            if (node.textContent !== value) node.textContent = value;
        } else if (node.dataset.en !== undefined) {
            const value = node.dataset[language] || node.dataset.en;
            if (node.textContent !== value) node.textContent = value;
        }
        for (const [suffix, attr] of [
            ["i18nAria", "aria-label"],
            ["i18nPlaceholder", "placeholder"],
        ])
            if (node.dataset[suffix])
                node.setAttribute(attr, text(node.dataset[suffix]));
    }
    const selector =
        "[data-i18n],[data-en],[data-i18n-aria],[data-i18n-placeholder]";
    function apply(root = document) {
        if (root instanceof Element && root.matches(selector)) update(root);
        root.querySelectorAll?.(selector).forEach(update);
    }
    function bind(node, key, values = {}) {
        node.dataset.i18n = key;
        node.dataset.i18nValues = JSON.stringify(values);
        update(node);
        return node;
    }
    function attribute(node, attr, key) {
        node.dataset[attr === "aria-label" ? "i18nAria" : "i18nPlaceholder"] =
            key;
        update(node);
        return node;
    }
    function set(value) {
        language = value === "ar" ? "ar" : "en";
        try {
            localStorage.setItem("creabiz.locale", language);
        } catch {}
        document.documentElement.lang = language;
        document.documentElement.dir = language === "ar" ? "rtl" : "ltr";
        apply();
        const toggle = document.getElementById("language");
        if (toggle)
            toggle.textContent = language === "ar" ? "English" : "العربية";
        window.dispatchEvent(new Event("ev:locale"));
    }
    window.EvLocale = {
        text,
        bind,
        attribute,
        set,
        get language() {
            return language;
        },
    };
    document.documentElement.lang = language;
    document.documentElement.dir = language === "ar" ? "rtl" : "ltr";
    document.addEventListener("DOMContentLoaded", () => {
        set(language);
        document
            .getElementById("language")
            ?.addEventListener("click", () =>
                set(language === "ar" ? "en" : "ar"),
            );
        new MutationObserver((changes) => {
            for (const change of changes)
                for (const node of change.addedNodes)
                    if (node instanceof Element) apply(node);
        }).observe(document.body, { subtree: true, childList: true });
    });
})();
