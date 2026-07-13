"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { CheckoutForm, type ContactFields } from "./CheckoutForm";
import { OrderSummaryCard } from "./OrderSummaryCard";
import { Button } from "@/components/ui/Button";
import { useCart } from "@/hooks/useCart";
import { createOrder, ApiValidationError } from "@/lib/api";

export interface CheckoutErrors {
  fields: Partial<Record<keyof ContactFields, string>>;
  lines: Record<number, string>;
  general: string | null;
}

const NO_ERRORS: CheckoutErrors = { fields: {}, lines: {}, general: null };

export function CheckoutClient() {
  const { lines, hydrated, clear } = useCart();
  const router = useRouter();
  const [errors, setErrors] = useState<CheckoutErrors>(NO_ERRORS);
  const [submitting, setSubmitting] = useState(false);

  if (!hydrated) return null;

  if (lines.length === 0) {
    return (
      <div className="flex flex-col items-start gap-6 rounded-2xl border border-rule px-6 py-16 sm:items-center sm:text-center">
        <p className="text-sm text-muted">
          Nothing in your cart yet — find something worth decanting first.
        </p>
        <Button href="/shop" variant="outline">
          Browse fragrances
        </Button>
      </div>
    );
  }

  const placeOrder = async (contact: ContactFields, honeypot: string) => {
    setSubmitting(true);
    setErrors(NO_ERRORS);

    try {
      const order = await createOrder({
        ...contact,
        website: honeypot,
        items: lines.map((line) => ({
          fragrance_id: line.fragranceId,
          size_ml: line.sizeMl,
          quantity: line.quantity,
        })),
      });

      try {
        // just the lookup pair — the complete page fetches the real receipt
        window.sessionStorage.setItem(
          "decant-please.receipt",
          JSON.stringify({ code: order.tracking_code, phone: contact.phone }),
        );
      } catch {
        // sessionStorage unavailable — complete page asks for the phone instead
      }

      clear();
      router.push(`/order/complete?code=${encodeURIComponent(order.tracking_code)}`);
    } catch (error) {
      if (error instanceof ApiValidationError) {
        const next: CheckoutErrors = { fields: {}, lines: {}, general: null };
        for (const [key, messages] of Object.entries(error.errors)) {
          const message = messages[0];
          const itemMatch = key.match(/^items\.(\d+)/);
          if (itemMatch) next.lines[Number(itemMatch[1])] = message;
          else if (key === "customer_name" || key === "phone" || key === "address" || key === "note")
            next.fields[key] = message;
          else next.general = message;
        }
        if (!next.general && Object.keys(next.lines).length > 0) {
          next.general = "One of your items just changed availability — see below.";
        }
        setErrors(next);
      } else {
        setErrors({
          ...NO_ERRORS,
          general: "We couldn't reach the shop just now — your cart is safe, try again in a moment.",
        });
      }
      setSubmitting(false);
    }
  };

  return (
    <div className="grid gap-12 md:grid-cols-[1fr_minmax(280px,380px)]">
      <div className="order-2 md:order-1">
        {errors.general && (
          <p role="alert" className="mb-6 rounded-2xl border border-status-danger/30 px-5 py-4 text-sm text-status-danger">
            {errors.general}
          </p>
        )}
        <CheckoutForm onSubmit={placeOrder} submitting={submitting} fieldErrors={errors.fields} />
      </div>

      <div className="order-1 md:order-2">
        <OrderSummaryCard lineErrors={errors.lines} />
      </div>
    </div>
  );
}
