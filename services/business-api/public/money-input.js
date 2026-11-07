/* Decimal input uses integer arithmetic; never round a user's price silently. */
window.EvMoney = {
    parse(value, digits, maximum = 1000000) {
        if (!Number.isInteger(digits) || digits < 0 || digits > 3) return null;
        const text = String(value)
            .trim()
            .replace(/[٠-٩۰-۹]/g, (char) =>
                String(
                    char.charCodeAt(0) >= 0x6f0
                        ? char.charCodeAt(0) - 0x6f0
                        : char.charCodeAt(0) - 0x660,
                ),
            )
            .replaceAll("٫", ".");
        if (
            text.length > 20 ||
            !(
                digits === 0
                    ? /^\d+$/
                    : new RegExp("^\\d+(?:\\.\\d{1," + digits + "})?$")
            ).test(text)
        )
            return null;
        const [whole, fraction = ""] = text.split(".");
        const amount = Number(whole + fraction.padEnd(digits, "0"));
        return Number.isSafeInteger(amount) && amount >= 0 && amount <= maximum
            ? amount
            : null;
    },
    format(amount, digits) {
        if (
            !Number.isSafeInteger(amount) ||
            amount < 0 ||
            !Number.isInteger(digits) ||
            digits < 0 ||
            digits > 3
        )
            throw new Error("Invalid monetary precision.");
        const text = String(amount).padStart(digits + 1, "0");
        return digits
            ? text.slice(0, -digits) + "." + text.slice(-digits)
            : text;
    },
};
