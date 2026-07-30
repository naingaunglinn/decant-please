"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { getMeta } from "@/lib/api";
import { formatKyat } from "@/lib/format";
import type { PaymentInfo } from "@/lib/types";

export type PaymentMethod = "cod" | "online";

export interface ContactFields {
  customer_name: string;
  phone: string;
  address: string;
  note?: string;
  payment_method: PaymentMethod;
}

interface CheckoutFormProps {
  onSubmit: (contact: ContactFields, honeypot: string, proof: File | null) => void;
  submitting: boolean;
  fieldErrors: Partial<Record<keyof ContactFields, string>>;
  subtotal: number;
}

export function CheckoutForm({ onSubmit, submitting, fieldErrors, subtotal }: CheckoutFormProps) {
  const [honeypot, setHoneypot] = useState("");
  const [method, setMethod] = useState<PaymentMethod>("cod");
  const [payment, setPayment] = useState<PaymentInfo | null>(null);
  const [paymentLoaded, setPaymentLoaded] = useState(false);
  const [proofFile, setProofFile] = useState<File | null>(null);

  useEffect(() => {
    let active = true;
    getMeta()
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
  }, []);

  const onlineReady = payment !== null;
  const needsSlip = method === "online";
  // Online can't submit until the shop is configured AND a slip is attached.
  const blockedOnline = needsSlip && (!onlineReady || !proofFile);
  const canSubmit = !submitting && !blockedOnline;

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    onSubmit(
      {
        customer_name: String(data.get("customer_name") ?? "").trim(),
        phone: String(data.get("phone") ?? "").trim(),
        address: String(data.get("address") ?? "").trim(),
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

      <Field label="Delivery address" error={fieldErrors.address}>
        <textarea
          name="address"
          required
          rows={3}
          autoComplete="street-address"
          className="w-full rounded-2xl border border-rule bg-transparent px-5 py-3 text-base"
        />
      </Field>

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
          <OnlinePay
            payment={payment}
            paymentLoaded={paymentLoaded}
            amount={subtotal}
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
