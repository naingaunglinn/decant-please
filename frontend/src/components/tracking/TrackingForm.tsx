"use client";

import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";

interface TrackingFormProps {
  initialCode?: string;
  pending: boolean;
  onSubmit: (code: string, phone: string) => void;
}

export function TrackingForm({ initialCode = "", pending, onSubmit }: TrackingFormProps) {
  const [code, setCode] = useState(initialCode);
  const [phone, setPhone] = useState("");

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    if (code.trim() && phone.trim()) onSubmit(code.trim(), phone.trim());
  };

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-5">
      <label className="block">
        <span className="mb-2 block text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
          Tracking code
        </span>
        <input
          value={code}
          onChange={(event) => setCode(event.target.value.toUpperCase())}
          placeholder="e.g. DP7K2M9QXA"
          required
          autoComplete="off"
          className="w-full rounded-full border border-rule bg-transparent px-5 py-3 font-mono text-sm uppercase tracking-[0.2em] placeholder:font-sans placeholder:normal-case placeholder:tracking-normal placeholder:text-muted/70"
        />
      </label>

      <label className="block">
        <span className="mb-2 block text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
          Phone number you ordered with
        </span>
        <input
          value={phone}
          onChange={(event) => setPhone(event.target.value)}
          placeholder="09-…"
          required
          type="tel"
          autoComplete="tel"
          className="w-full rounded-full border border-rule bg-transparent px-5 py-3 text-sm placeholder:text-muted/70"
        />
      </label>

      <Button type="submit" disabled={pending} className="self-start">
        {pending ? "Looking up…" : "Track this order"}
      </Button>
    </form>
  );
}
