/** 50000 → "50,000 Ks" — whole Kyat, never decimals. */
export function formatKyat(amount: number): string {
  return `${Math.round(amount).toLocaleString("en-US")} Ks`;
}
