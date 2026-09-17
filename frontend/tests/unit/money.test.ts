import { describe, expect, it } from "vitest";
import { amountToInput, currencyExponent, formatMoney, intervalText, parseAmount } from "@/lib/money";

describe("money", () => {
  it("knows currency exponents", () => {
    expect(currencyExponent("EUR")).toBe(2);
    expect(currencyExponent("JPY")).toBe(0);
    expect(currencyExponent("KWD")).toBe(3);
  });

  it("parses user input into minor units without floats", () => {
    expect(parseAmount("19.99", "EUR")).toBe(1999);
    expect(parseAmount("19,99", "EUR")).toBe(1999);
    expect(parseAmount("20", "EUR")).toBe(2000);
    expect(parseAmount("0.1", "EUR")).toBe(10);
    expect(parseAmount("1000", "JPY")).toBe(1000);
    expect(parseAmount("1.234", "KWD")).toBe(1234);
    expect(parseAmount("19.999", "EUR")).toBeNull();
    expect(parseAmount("abc", "EUR")).toBeNull();
    expect(parseAmount("-5", "EUR")).toBeNull();
  });

  it("round-trips amounts through the input", () => {
    expect(amountToInput(1999, "EUR")).toBe("19.99");
    expect(amountToInput(5, "EUR")).toBe("0.05");
    expect(parseAmount(amountToInput(123456, "KWD"), "KWD")).toBe(123456);
  });

  it("formats with the currency's fraction digits", () => {
    expect(formatMoney(1999, "EUR", "en")).toBe("€19.99");
    expect(formatMoney(1000, "JPY", "en")).toBe("¥1,000");
  });

  it("describes billing intervals in russian", () => {
    expect(intervalText({ billing_interval: "month", interval_count: 1 })).toBe("в месяц");
    expect(intervalText({ billing_interval: "month", interval_count: 3 })).toBe("каждые 3 месяца");
    expect(intervalText({ billing_interval: "year", interval_count: 5 })).toBe("каждые 5 лет");
    expect(intervalText({ billing_interval: "week", interval_count: 2 })).toBe("каждые 2 недели");
  });
});
