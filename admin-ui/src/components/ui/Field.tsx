import {
  forwardRef,
  type InputHTMLAttributes,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
  type ReactNode,
} from 'react';
import { cn } from '@/lib/cn';

const base =
  'w-full rounded-md bg-surface-2 border border-border px-3 text-sm text-fg placeholder:text-faint ' +
  'transition-colors focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/30 disabled:opacity-50';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
  function Input({ className, ...rest }, ref) {
    return <input ref={ref} className={cn(base, 'h-9', className)} {...rest} />;
  },
);

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(
  function Textarea({ className, ...rest }, ref) {
    return <textarea ref={ref} className={cn(base, 'py-2 min-h-[80px] font-mono text-xs leading-relaxed', className)} {...rest} />;
  },
);

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
  function Select({ className, children, ...rest }, ref) {
    return (
      <select ref={ref} className={cn(base, 'h-9 pr-8 cursor-pointer', className)} {...rest}>
        {children}
      </select>
    );
  },
);

export function FormRow({
  label,
  help,
  required,
  htmlFor,
  children,
}: {
  label: ReactNode;
  help?: ReactNode;
  required?: boolean;
  htmlFor?: string;
  children: ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={htmlFor} className="block text-xs font-medium text-muted">
        {label}
        {required && <span className="text-danger ml-0.5">*</span>}
      </label>
      {children}
      {help && <p className="text-2xs text-faint leading-snug">{help}</p>}
    </div>
  );
}
