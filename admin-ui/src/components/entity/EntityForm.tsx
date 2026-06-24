import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Input, Textarea, Select, FormRow } from '@/components/ui/Field';
import { entityApi } from '@/lib/api';
import type { EntityRecord, SchemaField } from '@/lib/types';

export type FieldValues = Record<string, string | boolean>;

function initialValue(field: SchemaField, record: EntityRecord | null): string | boolean {
  const settings = (record?.settings ?? {}) as Record<string, unknown>;
  if (field.key === 'name') return record?.name ?? '';
  if (field.key === 'group') return record?.group ?? '';
  const v = settings[field.key];
  switch (field.type) {
    case 'checkbox':
      return Boolean(v);
    case 'csv':
      return Array.isArray(v) ? v.join(', ') : typeof v === 'string' ? v : '';
    case 'kvlines':
      return v && typeof v === 'object'
        ? Object.entries(v as Record<string, unknown>)
            .map(([k, val]) => `${k}=${val}`)
            .join('\n')
        : '';
    case 'json':
      return v && typeof v === 'object' ? JSON.stringify(v, null, 2) : typeof v === 'string' ? v : '';
    case 'password':
      return '';
    case 'entityref':
      return v === null || v === undefined ? '' : String(v);
    default:
      return v === null || v === undefined ? String(field.default ?? '') : String(v);
  }
}

// eslint-disable-next-line react-refresh/only-export-components
export function buildInitialValues(fields: SchemaField[], record: EntityRecord | null): FieldValues {
  const out: FieldValues = {};
  for (const f of fields) out[f.key] = initialValue(f, record);
  return out;
}

function EntityRefSelect({
  field,
  value,
  onChange,
}: {
  field: SchemaField;
  value: string;
  onChange: (v: string) => void;
}) {
  const { data } = useQuery({
    queryKey: ['entity', field.entity, 'list'],
    queryFn: () => entityApi.list(field.entity!),
    enabled: !!field.entity,
  });
  return (
    <Select value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">— none —</option>
      {(data?.items ?? []).map((it) => (
        <option key={it.id} value={it.id}>
          {it.name}
        </option>
      ))}
    </Select>
  );
}

export function EntityForm({
  fields,
  record,
  onValuesChange,
}: {
  fields: SchemaField[];
  record: EntityRecord | null;
  onValuesChange: (v: FieldValues) => void;
}) {
  const [values, setValues] = useState<FieldValues>(() => buildInitialValues(fields, record));

  useEffect(() => {
    const init = buildInitialValues(fields, record);
    setValues(init);
    onValuesChange(init);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [record]);

  const set = (key: string, value: string | boolean) => {
    setValues((prev) => {
      const next = { ...prev, [key]: value };
      onValuesChange(next);
      return next;
    });
  };

  return (
    <div className="space-y-4">
      {fields.map((f) => {
        const val = values[f.key];
        if (f.type === 'checkbox') {
          return (
            <label key={f.key} className="flex items-center gap-2.5 cursor-pointer select-none">
              <input
                type="checkbox"
                checked={Boolean(val)}
                onChange={(e) => set(f.key, e.target.checked)}
                className="h-4 w-4 rounded border-border-strong accent-[rgb(var(--brand))]"
              />
              <span className="text-sm">{f.label}</span>
              {f.help && <span className="text-2xs text-faint">— {f.help}</span>}
            </label>
          );
        }
        return (
          <FormRow key={f.key} label={f.label} help={f.help} required={f.required} htmlFor={`f-${f.key}`}>
            {f.type === 'textarea' && (
              <Textarea id={`f-${f.key}`} value={String(val)} onChange={(e) => set(f.key, e.target.value)} />
            )}
            {(f.type === 'json' || f.type === 'kvlines') && (
              <Textarea id={`f-${f.key}`} value={String(val)} onChange={(e) => set(f.key, e.target.value)} rows={5} />
            )}
            {f.type === 'select' && (
              <Select id={`f-${f.key}`} value={String(val)} onChange={(e) => set(f.key, e.target.value)}>
                {Object.entries(f.options ?? {}).map(([k, label]) => (
                  <option key={k} value={k}>
                    {label}
                  </option>
                ))}
              </Select>
            )}
            {f.type === 'entityref' && (
              <EntityRefSelect field={f} value={String(val)} onChange={(v) => set(f.key, v)} />
            )}
            {f.type === 'password' && (
              <Input
                id={`f-${f.key}`}
                type="password"
                autoComplete="new-password"
                placeholder={record ? '•••••••• (leave blank to keep)' : ''}
                value={String(val)}
                onChange={(e) => set(f.key, e.target.value)}
              />
            )}
            {f.type === 'number' && (
              <Input
                id={`f-${f.key}`}
                type="number"
                step="any"
                value={String(val)}
                onChange={(e) => set(f.key, e.target.value)}
              />
            )}
            {(f.type === 'text' || f.type === 'csv' || !f.type) && (
              <Input id={`f-${f.key}`} value={String(val)} onChange={(e) => set(f.key, e.target.value)} />
            )}
          </FormRow>
        );
      })}
    </div>
  );
}
