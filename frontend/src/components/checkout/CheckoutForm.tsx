"use client";

import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";

export interface ContactFields {
  customer_name: string;
  phone: string;
  address: string;
  note?: string;
}

interface CheckoutFormProps {
  onSubmit: (contact: ContactFields, honeypot: string) => void;
  submitting: boolean;
  fieldErrors: Partial<Record<keyof ContactFields, string>>;
}

export function CheckoutForm({ onSubmit, submitting, fieldErrors }: CheckoutFormProps) {
  const [honeypot, setHoneypot] = useState("");

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    onSubmit(
      {
        customer_name: String(data.get("customer_name") ?? "").trim(),
        phone: String(data.get("phone") ?? "").trim(),
        address: String(data.get("address") ?? "").trim(),
        note: String(data.get("note") ?? "").trim() || undefined,
      },
      honeypot,
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

      <p className="text-xs leading-relaxed text-muted">
        No payment now — we review your order first, then arrange bank transfer, mobile
        banking or cash on delivery.
      </p>

      <Button type="submit" disabled={submitting} className="self-start">
        {submitting ? "Placing order…" : "Place order"}
      </Button>
    </form>
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
