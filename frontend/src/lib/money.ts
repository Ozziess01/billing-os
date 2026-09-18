// Деньги приходят с бэкенда целыми минорными единицами; здесь только отображение и разбор ввода.

const exponents = new Map<string, number>();

export function currencyExponent(currency: string): number {
  const cached = exponents.get(currency);
  if (cached !== undefined) return cached;
  let exponent = 2;
  try {
    exponent = new Intl.NumberFormat("en", { style: "currency", currency }).resolvedOptions().maximumFractionDigits ?? 2;
  } catch {
    // неизвестная Intl валюта - считаем два знака
  }
  exponents.set(currency, exponent);
  return exponent;
}

export function formatMoney(amount: number, currency: string, locale = "ru-RU"): string {
  const exponent = currencyExponent(currency);
  const value = amount / 10 ** exponent;
  try {
    return new Intl.NumberFormat(locale, { style: "currency", currency, minimumFractionDigits: exponent, maximumFractionDigits: exponent }).format(value);
  } catch {
    return `${value.toFixed(exponent)} ${currency}`;
  }
}

/** "19.99" / "19,99" / "20" -> 1999 для валюты с двумя знаками; null, если строка не число. */
export function parseAmount(input: string, currency: string): number | null {
  const normalized = input.trim().replace(/\s/g, "").replace(",", ".");
  if (!/^\d+(\.\d+)?$/.test(normalized)) return null;
  const exponent = currencyExponent(currency);
  const [whole, fraction = ""] = normalized.split(".");
  if (fraction.length > exponent) return null;
  return Number(whole) * 10 ** exponent + Number((fraction + "0".repeat(exponent)).slice(0, exponent) || 0);
}

/** 1999 -> "19.99": значение для инпута. */
export function amountToInput(amount: number, currency: string): string {
  const exponent = currencyExponent(currency);
  return (amount / 10 ** exponent).toFixed(exponent);
}

export const intervalLabel: Record<string, string> = {
  day: "день",
  week: "неделю",
  month: "месяц",
  year: "год",
};

/** "в месяц" / "каждые 3 месяца" */
export function intervalText(price: { billing_interval: string; interval_count: number }): string {
  return price.interval_count === 1
    ? `в ${intervalLabel[price.billing_interval] ?? price.billing_interval}`
    : `каждые ${price.interval_count} ${intervalPlural(price.billing_interval, price.interval_count)}`;
}

export function priceLabel(price: { unit_amount: number; currency: string; billing_interval: string; interval_count: number }): string {
  return `${formatMoney(price.unit_amount, price.currency)} ${intervalText(price)}`;
}

function intervalPlural(interval: string, count: number): string {
  const forms: Record<string, [string, string, string]> = {
    day: ["день", "дня", "дней"],
    week: ["неделю", "недели", "недель"],
    month: ["месяц", "месяца", "месяцев"],
    year: ["год", "года", "лет"],
  };
  const [one, few, many] = forms[interval] ?? [interval, interval, interval];
  const mod10 = count % 10;
  const mod100 = count % 100;
  if (mod10 === 1 && mod100 !== 11) return one;
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return few;
  return many;
}

/** "Pro plan (Extra seat) × 3" для списков подписок. */
export function itemLabel(item: { quantity: number; price?: { nickname: string | null; usage_type?: string; product?: { name: string } } }): string {
  const name = item.price?.product?.name ?? "—";
  const nickname = item.price?.nickname ? ` (${item.price.nickname})` : "";
  return item.price?.usage_type === "metered" ? `${name}${nickname} — по использованию` : `${name}${nickname} × ${item.quantity}`;
}
