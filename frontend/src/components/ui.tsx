import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react';
import { ApiError } from '@/lib/api';
import { t } from '@/lib/i18n';

/**
 * Shared presentational primitives.
 *
 * Deliberately small. Components move here when a third consumer appears — two
 * is a coincidence, three is a pattern, and extracting early produces
 * abstractions shaped by the first two use cases.
 */

export function Panel({
  title,
  children,
  actions,
}: {
  title?: string;
  children: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <section className="rounded-lg border border-ash-700 bg-ash-900/70 p-4 sm:p-5">
      {(title ?? actions) && (
        <header className="mb-4 flex items-center justify-between gap-3">
          {title && <h2 className="text-sm font-semibold tracking-wide text-ash-200 uppercase">{title}</h2>}
          {actions}
        </header>
      )}
      {children}
    </section>
  );
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'ghost';
  busy?: boolean;
};

export function Button({ variant = 'secondary', busy = false, children, className = '', ...props }: ButtonProps) {
  const base =
    'inline-flex items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50';

  const variants = {
    primary: 'bg-ember-500 text-ash-950 hover:bg-ember-400',
    secondary: 'bg-ash-700 text-ash-50 hover:bg-ash-600',
    ghost: 'text-ash-200 hover:bg-ash-800',
  };

  return (
    <button
      type="button"
      className={`${base} ${variants[variant]} ${className}`}
      // Disabled while in flight so a double-tap cannot fire the action twice
      // before the idempotency key reaches the server.
      disabled={props.disabled || busy}
      aria-busy={busy}
      {...props}
    >
      {children}
    </button>
  );
}

export function Field({
  label,
  id,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string; id: string }) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-xs font-medium text-ash-200">
        {label}
      </label>
      <input
        id={id}
        className="rounded-md border border-ash-700 bg-ash-950 px-3 py-2 text-sm text-ash-50 placeholder:text-ash-400"
        {...props}
      />
    </div>
  );
}

/**
 * Renders an error from its stable code, with structured detail where the
 * server provided it. "You need 6 more Vigor" is actionable; "Forbidden" is a
 * dead end.
 */
export function ErrorNotice({ error }: { error: unknown }) {
  if (!error) return null;

  let message = t('error.INTERNAL_ERROR');
  let detail: string | null = null;

  if (error instanceof ApiError) {
    message = t(`error.${error.code}`);

    const required = error.details.required;
    const available = error.details.available;

    if (typeof required === 'number' && typeof available === 'number') {
      detail = `${required - available} more needed.`;
    }

    const requiredLevel = error.details.required_level;

    if (typeof requiredLevel === 'number') {
      detail = t('encounter.locked', { level: requiredLevel });
    }
  }

  return (
    <p role="alert" className="rounded-md border border-danger-500/40 bg-danger-500/10 px-3 py-2 text-sm text-ash-50">
      {message}
      {detail && <span className="block text-ash-200">{detail}</span>}
    </p>
  );
}

export function Bar({
  value,
  max,
  tone = 'ember',
  label,
}: {
  value: number;
  max: number;
  tone?: 'ember' | 'blight' | 'danger';
  label?: string;
}) {
  const percent = max > 0 ? Math.max(0, Math.min(100, (value / max) * 100)) : 0;

  const tones = {
    ember: 'bg-ember-500',
    blight: 'bg-blight-500',
    danger: 'bg-danger-500',
  };

  return (
    <div
      role="meter"
      aria-valuenow={value}
      aria-valuemin={0}
      aria-valuemax={max}
      aria-label={label}
      className="h-2 w-full overflow-hidden rounded-full bg-ash-800"
    >
      <div className={`h-full ${tones[tone]} transition-[width] duration-200`} style={{ width: `${percent}%` }} />
    </div>
  );
}

export function Stat({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-3 border-b border-ash-800 py-1.5 last:border-0">
      <span className="text-xs text-ash-400">{label}</span>
      <span className="text-sm font-medium tabular-nums">{value}</span>
    </div>
  );
}
