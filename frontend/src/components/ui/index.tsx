"use client";

import Link from "next/link";
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TdHTMLAttributes, TextareaHTMLAttributes, ThHTMLAttributes } from "react";
import { useEffect } from "react";

type Variant = "primary" | "secondary" | "ghost" | "danger";
type Size = "sm" | "md";

export function Button({
  variant = "primary",
  size = "md",
  className = "",
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: Size }) {
  const base = "inline-flex items-center justify-center gap-2 rounded-md font-medium transition disabled:opacity-50 disabled:pointer-events-none";
  const sizes: Record<Size, string> = { sm: "h-8 px-3 text-xs", md: "h-9 px-4 text-sm" };
  const styles: Record<Variant, string> = {
    primary: "bg-accent text-white hover:bg-accent-2 shadow-sm",
    secondary: "bg-panel border border-line text-fg hover:bg-panel-2 shadow-sm",
    ghost: "text-muted hover:bg-panel-2 hover:text-fg",
    danger: "border border-err/40 text-err hover:bg-err/10",
  };
  return <button className={`${base} ${sizes[size]} ${styles[variant]} ${className}`} {...props} />;
}

const fieldClass = (error?: string) =>
  `h-9 w-full rounded-md border bg-panel px-3 text-sm outline-none transition placeholder:text-muted/70 focus:border-accent focus:ring-2 focus:ring-accent/15 ${
    error ? "border-err" : "border-line"
  }`;

export function Field({ label, error, hint, children }: { label?: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <label className="block">
      {label && <span className="mb-1.5 block text-xs font-medium text-muted">{label}</span>}
      {children}
      {error ? <span className="mt-1 block text-xs text-err">{error}</span> : hint ? <span className="mt-1 block text-xs text-muted">{hint}</span> : null}
    </label>
  );
}

export function Input({ label, error, hint, className = "", ...props }: InputHTMLAttributes<HTMLInputElement> & { label?: string; error?: string; hint?: string }) {
  return (
    <Field label={label} error={error} hint={hint}>
      <input className={`${fieldClass(error)} ${className}`} {...props} />
    </Field>
  );
}

export function Select({ label, error, hint, className = "", children, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { label?: string; error?: string; hint?: string }) {
  return (
    <Field label={label} error={error} hint={hint}>
      <select className={`${fieldClass(error)} ${className}`} {...props}>
        {children}
      </select>
    </Field>
  );
}

export function Textarea({ label, error, className = "", ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label?: string; error?: string }) {
  return (
    <Field label={label} error={error}>
      <textarea className={`${fieldClass(error)} h-auto min-h-20 py-2 ${className}`} {...props} />
    </Field>
  );
}

export function Card({ children, className = "", title, actions }: { children: ReactNode; className?: string; title?: string; actions?: ReactNode }) {
  return (
    <section className={`rounded-lg border border-line bg-panel shadow-xs ${className}`}>
      {(title || actions) && (
        <header className="flex items-center justify-between gap-3 border-b border-line px-5 py-3">
          {title && <h2 className="text-sm font-semibold">{title}</h2>}
          {actions}
        </header>
      )}
      {children}
    </section>
  );
}

export type Tone = "muted" | "ok" | "warn" | "err" | "accent";

export function Badge({ children, tone = "muted" }: { children: ReactNode; tone?: Tone }) {
  const tones: Record<Tone, string> = {
    muted: "bg-panel-2 text-muted border-line",
    ok: "bg-ok/10 text-ok border-ok/30",
    warn: "bg-warn/10 text-warn border-warn/30",
    err: "bg-err/10 text-err border-err/30",
    accent: "bg-accent/10 text-accent border-accent/30",
  };
  return <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium ${tones[tone]}`}>{children}</span>;
}

export function PageTitle({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  );
}

export function Table({ children }: { children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">{children}</table>
    </div>
  );
}

export function Th({ children, className = "", ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th className={`border-b border-line px-5 py-2.5 text-left text-[11px] font-medium uppercase tracking-wider text-muted ${className}`} {...props}>
      {children}
    </th>
  );
}

export function Td({ children, className = "", ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
  return (
    <td className={`border-b border-line/70 px-5 py-3 align-middle ${className}`} {...props}>
      {children}
    </td>
  );
}

export function Empty({ children }: { children: ReactNode }) {
  return <div className="px-5 py-12 text-center text-sm text-muted">{children}</div>;
}

export function Money({ amount, formatted, className = "" }: { amount?: number; formatted: string; className?: string }) {
  return <span className={`font-mono tabular-nums ${amount !== undefined && amount < 0 ? "text-err" : ""} ${className}`}>{formatted}</span>;
}

export function Pagination({ page, lastPage, onChange }: { page: number; lastPage: number; onChange: (page: number) => void }) {
  if (lastPage <= 1) return null;
  return (
    <div className="flex items-center justify-end gap-2 px-5 py-3 text-xs text-muted">
      <span>
        {page} / {lastPage}
      </span>
      <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onChange(page - 1)}>
        Назад
      </Button>
      <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => onChange(page + 1)}>
        Дальше
      </Button>
    </div>
  );
}

export function Dialog({ open, onClose, title, children, width = "max-w-lg" }: { open: boolean; onClose: () => void; title: string; children: ReactNode; width?: string }) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-ink/30 p-4 backdrop-blur-[2px]" onMouseDown={onClose}>
      <div role="dialog" aria-modal className={`w-full ${width} rounded-xl border border-line bg-panel shadow-xl`} onMouseDown={(e) => e.stopPropagation()}>
        <header className="flex items-center justify-between border-b border-line px-5 py-3">
          <h2 className="text-sm font-semibold">{title}</h2>
          <button type="button" onClick={onClose} className="rounded p-1 text-muted hover:bg-panel-2 hover:text-fg" aria-label="Закрыть">
            ×
          </button>
        </header>
        <div className="px-5 py-4">{children}</div>
      </div>
    </div>
  );
}

export function ErrorNote({ error }: { error: unknown }) {
  if (!error) return null;
  const message = error instanceof Error ? error.message : "Что-то пошло не так.";
  return <div className="rounded-md border border-err/30 bg-err/5 px-3 py-2 text-sm text-err">{message}</div>;
}

export function BackLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="mb-3 inline-flex items-center gap-1 text-xs text-muted hover:text-fg">
      ← {children}
    </Link>
  );
}

export function Stat({ label, value, hint }: { label: string; value: ReactNode; hint?: ReactNode }) {
  return (
    <Card className="px-5 py-4">
      <div className="text-xs font-medium text-muted">{label}</div>
      <div className="mt-2 font-mono text-2xl tabular-nums">{value}</div>
      {hint && <div className="mt-1 text-xs text-muted">{hint}</div>}
    </Card>
  );
}
