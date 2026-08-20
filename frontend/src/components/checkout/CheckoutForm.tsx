"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/Select";
import { getMeta } from "@/lib/api";
import { useTenant } from "@/lib/tenant-context";
import { formatKyat } from "@/lib/format";
import type { DeliveryTownshipOption, DeliveryZones, PaymentInfo } from "@/lib/types";

export type PaymentMethod = "cod" | "online";

export interface ContactFields {
  customer_name: string;
  phone: string;
  /** Non-null once the form can submit — the server re-derives the fee from it. */
  delivery_township_id: number | null;
  address_line: string;
  address_extra?: string;
  note?: string;
  payment_method: PaymentMethod;
}

interface CheckoutFormProps {
  onSubmit: (contact: ContactFields, honeypot: string, proof: File | null) => void;
  submitting: boolean;
  fieldErrors: Partial<Record<keyof ContactFields, string>>;
  subtotal: number;
  /** The previewed promo discount — nets the online "amount to pay". */
  discount: number;
  /** The serviceable destination tree; null while loading. */
  zones: DeliveryZones | null;
  /** The zones fetch failed — the form fails visibly, never falls back to free text. */
  zonesFailed: boolean;
  /** Reports the picked township up, so the summary card can show its fee. */
  onTownshipChange: (township: DeliveryTownshipOption | null) => void;
}

export function CheckoutForm({
  onSubmit,
  submitting,
  fieldErrors,
  subtotal,
  discount,
  zones,
  zonesFailed,
  onTownshipChange,
}: CheckoutFormProps) {
  const { slug: shop } = useTenant();
  const [honeypot, setHoneypot] = useState("");
  const [method, setMethod] = useState<PaymentMethod>("cod");
  const [payment, setPayment] = useState<PaymentInfo | null>(null);
  const [paymentLoaded, setPaymentLoaded] = useState(false);
  const [proofFile, setProofFile] = useState<File | null>(null);
  const [region, setRegion] = useState("");
  const [township, setTownship] = useState<DeliveryTownshipOption | null>(null);

  useEffect(() => {
    let active = true;
    getMeta(shop)
      .then((meta) => {
        if (active) {
          setPayment(meta.payment);
          setPaymentLoaded(true);
        }
      })
      .catch(() => {
        if (active) setPaymentLoaded(true);
      });
    return () => {
      active = false;
    };
  }, [shop]);

  const regionTownships =
    zones?.regions.find((candidate) => candidate.value === region)?.townships ?? [];

  const pickRegion = (value: string) => {
    setRegion(value);
    setTownship(null);
    onTownshipChange(null);
  };

  const pickTownship = (id: string) => {
    const picked = regionTownships.find((candidate) => String(candidate.id) === id) ?? null;
    setTownship(picked);
    onTownshipChange(picked);
  };

  const onlineReady = payment !== null;
  const needsSlip = method === "online";
  // Online can't submit until the shop is configured AND a slip is attached.
  const blockedOnline = needsSlip && (!onlineReady || !proofFile);
  const canSubmit = !submitting && !blockedOnline && township !== null;

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    onSubmit(
      {
        customer_name: String(data.get("customer_name") ?? "").trim(),
        phone: String(data.get("phone") ?? "").trim(),
        delivery_township_id: township?.id ?? null,
        address_line: String(data.get("address_line") ?? "").trim(),
        address_extra: String(data.get("address_extra") ?? "").trim() || undefined,
        note: String(data.get("note") ?? "").trim() || undefined,
        payment_method: method,
      },
      honeypot,
      needsSlip ? proofFile : null,
    );
  };

  return (
    <form onSubmit={handleSubmit} noValidate={false} className="flex flex-col gap-6">
      <Field label="Your name" error={fieldErrors.customer_name}>
        <input
          name="customer_name"
          required
          autoComplete="name"
          className="w-full rounded-full border border-rule bg-transparent px-5 py-3 text-base"
        />
      </Field>

      <Field
        label="Phone"
        hint="We confirm every order by phone — and you'll track with this number."
        error={fieldErrors.phone}
      >
        <input
          name="phone"
          required
          type="tel"
          autoComplete="tel"
          placeholder="09-…"
          className="w-full rounded-full border border-rule bg-transparent px-5 py-3 text-base placeholder:text-muted/70"
        />
      </Field>

      {zonesFailed ? (
        // No free-text fallback, deliberately: an order must never land with
        // no zone and no fee. Failing loudly beats failing into bad data.
        <p role="alert" className="rounded-2xl border border-status-danger/30 px-5 py-4 text-sm leading-relaxed text-status-danger">
          We couldn&apos;t load our delivery areas just now, so checkout can&apos;t continue —
          your cart is safe; please reload the page to try again.
        </p>
      ) : zones !== null && zones.regions.length === 0 ? (
        <p role="alert" className="rounded-2xl border border-status-pending/40 px-5 py-4 text-sm leading-relaxed text-status-pending">
          Delivery areas aren&apos;t set up yet — please message the shop to order for now.
        </p>
      ) : (
        <>
          <Field label="State / Region" error={fieldErrors.delivery_township_id}>
            <Select value={region} onValueChange={pickRegion} disabled={zones === null}>
              <SelectTrigger aria-label="State / Region">
                <SelectValue
                  placeholder={zones === null ? "Loading delivery areas…" : "Choose your state or region…"}
                />
              </SelectTrigger>
              <SelectContent>
                {zones?.regions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>

          <Field
            label="Township"
            hint={township ? undefined : "Your delivery fee comes from this."}
            error={fieldErrors.delivery_township_id}
          >
            <Select
              value={township ? String(township.id) : ""}
              onValueChange={pickTownship}
              disabled={region === ""}
            >
              <SelectTrigger aria-label="Township">
                <SelectValue
                  placeholder={region === "" ? "Pick a region first…" : "Choose your township…"}
                />
              </SelectTrigger>
              <SelectContent>
                {regionTownships.map((option) => (
                  <SelectItem key={option.id} value={String(option.id)}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>

          <Field
            label="Address"
            hint="Street, ward, house number."
            error={fieldErrors.address_line}
          >
            <textarea
              name="address_line"
              required
              rows={2}
              autoComplete="street-address"
              className="w-full rounded-2xl border border-rule bg-transparent px-5 py-3 text-base"
            />
          </Field>

          <Field
            label="Anything else about the address (optional)"
            hint="Prints with the address on your parcel — e.g. building entrance, landmark."
            error={fieldErrors.address_extra}
          >
            <textarea
              name="address_extra"
              rows={2}
              className="w-full rounded-2xl border border-rule bg-transparent px-5 py-3 text-base"
            />
          </Field>
        </>
      )}

      <Field label="Note (optional)" error={fieldErrors.note}>
        <textarea
          name="note"
          rows={2}
          placeholder="Anything we should know — e.g. call before delivery"
          className="w-full rounded-2xl border border-rule bg-transparent px-5 py-3 text-base placeholder:text-muted/70"
        />
      </Field>

      {/* honeypot: off-canvas, skipped by keyboard and screen readers; bots fill it */}
      <div aria-hidden="true" className="absolute -left-[9999px] top-auto h-px w-px overflow-hidden">
        <label>
          Website
          <input
            type="text"
            name="website"
            tabIndex={-1}
            autoComplete="off"
            value={honeypot}
            onChange={(event) => setHoneypot(event.target.value)}
          />
        </label>
      </div>

      <div>
        <span className="mb-2 block text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
          How you&apos;ll pay
        </span>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <MethodOption
            value="cod"
            current={method}
            onSelect={setMethod}
            title="Cash on delivery"
            desc="Pay the delivery person when it arrives."
          />
          <MethodOption
            value="online"
            current={method}
            onSelect={setMethod}
            title="Online transfer"
            desc="Scan our QR to pay, then upload your slip."
          />
        </div>

        {method === "cod" && (
          <p className="mt-3 text-xs leading-relaxed text-muted">
            No payment now — we confirm your order first, and you pay cash when it&apos;s delivered.
          </p>
        )}

        {method === "online" && (
          // the amount asked online is the *discounted* item subtotal — matching the
          // receipt (#67); the delivery fee stays cash-to-courier, never in this figure
          <OnlinePay
            payment={payment}
            paymentLoaded={paymentLoaded}
            amount={Math.max(0, subtotal - discount)}
            onProof={setProofFile}
          />
        )}
      </div>

      <Button type="submit" disabled={!canSubmit} className="self-start">
        {submitting ? "Placing order…" : method === "online" ? "Pay & place order" : "Place order"}
      </Button>
    </form>
  );
}

function OnlinePay({
  payment,
  paymentLoaded,
  amount,
  onProof,
}: {
  payment: PaymentInfo | null;
  paymentLoaded: boolean;
  amount: number;
  onProof: (file: File | null) => void;
}) {
  if (!paymentLoaded) {
    return <p className="mt-3 text-xs text-muted">Loading payment details…</p>;
  }

  if (!payment) {
    return (
      <p className="mt-3 rounded-2xl border border-status-pending/40 px-4 py-3 text-xs leading-relaxed text-status-pending">
        Online payment isn&apos;t set up yet — please choose Cash on delivery.
      </p>
    );
  }

  return (
    <div className="mt-4 rounded-2xl border border-rule px-5 py-4">
      <div className="flex items-baseline justify-between gap-4">
        <span className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
          Amount to pay
        </span>
        <span className="text-base font-bold text-pine">{formatKyat(amount)}</span>
      </div>
      <p className="mt-2 text-xs leading-relaxed text-muted">
        Scan the QR from any wallet (KBZPay, Wave, AYA…), pay the amount, then upload your slip.
        Delivery is paid separately in cash.
      </p>

      {payment.qr_url && (
        // seller-configured QR on an arbitrary host — plain img, not next/image
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={payment.qr_url}
          alt="Payment QR code"
          className="mx-auto mt-3 h-44 w-44 rounded-xl border border-rule object-contain"
        />
      )}

      <div className="mt-3 flex flex-col gap-2">
        {payment.kbzpay_number && (
          <TransferLine label="KBZPay" name={payment.kbzpay_name} number={payment.kbzpay_number} />
        )}
        {payment.wave_number && (
          <TransferLine label="Wave" name={payment.wave_name} number={payment.wave_number} />
        )}
      </div>

      {payment.instructions && (
        <p className="mt-2 text-xs leading-relaxed text-muted">{payment.instructions}</p>
      )}

      <label className="mt-4 flex flex-col gap-2 border-t border-rule pt-4 text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
        Transfer slip (required)
        <input
          type="file"
          accept="image/*"
          onChange={(event) => onProof(event.target.files?.[0] ?? null)}
          className="block w-full text-sm text-ink file:mr-4 file:min-h-11 file:cursor-pointer file:rounded-full file:border file:border-pine file:bg-transparent file:px-5 file:text-xs file:font-medium file:uppercase file:tracking-[0.15em] file:text-pine hover:file:bg-pine-soft"
        />
      </label>
    </div>
  );
}

function TransferLine({ label, name, number }: { label: string; name?: string; number?: string }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <span className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">{label}</span>
      <span className="text-right text-sm">
        <span className="font-mono tracking-[0.1em] text-ink-strong">{number}</span>
        {name && <span className="block text-muted">{name}</span>}
      </span>
    </div>
  );
}

function MethodOption({
  value,
  current,
  onSelect,
  title,
  desc,
}: {
  value: PaymentMethod;
  current: PaymentMethod;
  onSelect: (value: PaymentMethod) => void;
  title: string;
  desc: string;
}) {
  const selected = current === value;
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      onClick={() => onSelect(value)}
      className={`rounded-2xl border px-5 py-4 text-left transition-colors ${
        selected ? "border-pine bg-pine-soft" : "border-rule hover:bg-pine-soft/40"
      }`}
    >
      <span className="block text-sm font-medium text-ink-strong">{title}</span>
      <span className="mt-1 block text-xs leading-relaxed text-muted">{desc}</span>
    </button>
  );
}

function Field({
  label,
  hint,
  error,
  children,
}: {
  label: string;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-2 block text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
        {label}
      </span>
      {children}
      {hint && !error && <span className="mt-1.5 block text-xs text-muted">{hint}</span>}
      {error && (
        <span role="alert" className="mt-1.5 block text-xs text-status-danger">
          {error}
        </span>
      )}
    </label>
  );
}
