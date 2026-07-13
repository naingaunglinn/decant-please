"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import type { CartLine } from "./types";

const STORAGE_KEY = "decant-please.cart.v1";

export function cartLineKey(line: Pick<CartLine, "fragranceId" | "sizeMl">): string {
  return `${line.fragranceId}:${line.sizeMl}`;
}

interface CartContextValue {
  lines: CartLine[];
  count: number;
  subtotal: number; // preview only — server re-derives the real total
  hydrated: boolean;
  isOpen: boolean;
  add: (line: Omit<CartLine, "quantity">, quantity: number) => void;
  updateQuantity: (key: string, quantity: number) => void;
  remove: (key: string) => void;
  clear: () => void;
  openCart: () => void;
  closeCart: () => void;
}

const CartContext = createContext<CartContextValue | null>(null);

export function CartProvider({ children }: { children: ReactNode }) {
  const [lines, setLines] = useState<CartLine[]>([]);
  const [hydrated, setHydrated] = useState(false);
  const [isOpen, setIsOpen] = useState(false);
  const skipPersist = useRef(true);

  useEffect(() => {
    try {
      const stored = window.localStorage.getItem(STORAGE_KEY);
      if (stored) setLines(JSON.parse(stored));
    } catch {
      // corrupted storage — start fresh
    }
    setHydrated(true);
  }, []);

  useEffect(() => {
    if (!hydrated) return;
    if (skipPersist.current) {
      skipPersist.current = false;
      return;
    }
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(lines));
    } catch {
      // storage full/unavailable — cart still works for the session
    }
  }, [lines, hydrated]);

  const add = useCallback((line: Omit<CartLine, "quantity">, quantity: number) => {
    setLines((current) => {
      const key = cartLineKey(line);
      const existing = current.find((l) => cartLineKey(l) === key);
      if (existing) {
        return current.map((l) =>
          cartLineKey(l) === key ? { ...l, quantity: l.quantity + quantity } : l,
        );
      }
      return [...current, { ...line, quantity }];
    });
    setIsOpen(true);
  }, []);

  const updateQuantity = useCallback((key: string, quantity: number) => {
    setLines((current) =>
      quantity < 1
        ? current.filter((l) => cartLineKey(l) !== key)
        : current.map((l) => (cartLineKey(l) === key ? { ...l, quantity } : l)),
    );
  }, []);

  const remove = useCallback((key: string) => {
    setLines((current) => current.filter((l) => cartLineKey(l) !== key));
  }, []);

  const clear = useCallback(() => setLines([]), []);
  const openCart = useCallback(() => setIsOpen(true), []);
  const closeCart = useCallback(() => setIsOpen(false), []);

  const value = useMemo<CartContextValue>(() => {
    const count = lines.reduce((sum, l) => sum + l.quantity, 0);
    const subtotal = lines.reduce((sum, l) => sum + l.priceMmk * l.quantity, 0);
    return {
      lines,
      count,
      subtotal,
      hydrated,
      isOpen,
      add,
      updateQuantity,
      remove,
      clear,
      openCart,
      closeCart,
    };
  }, [lines, hydrated, isOpen, add, updateQuantity, remove, clear, openCart, closeCart]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCartContext(): CartContextValue {
  const context = useContext(CartContext);
  if (!context) throw new Error("useCart must be used inside <CartProvider>.");
  return context;
}
