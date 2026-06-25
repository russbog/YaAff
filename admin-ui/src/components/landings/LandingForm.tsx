import { useEffect, useMemo, useRef, useState } from 'react';
import { UploadCloud } from 'lucide-react';
import { EntityForm, buildInitialValues, type FieldValues } from '@/components/entity/EntityForm';
import { FormRow } from '@/components/ui/Field';
import type { EntityRecord, SchemaField } from '@/lib/types';

// Landing form. Local landings no longer ask for a folder name: the visitor
// uploads a ZIP right here and the extraction folder is named after the
// landing's numeric id (assigned on save). The schema's manual `path` field is
// therefore hidden — EntityPage performs the create → upload(folder=id) →
// path=id orchestration.
export function LandingForm({
  fields,
  record,
  onValuesChange,
  onFileChange,
}: {
  fields: SchemaField[];
  record: EntityRecord | null;
  onValuesChange: (v: FieldValues) => void;
  onFileChange: (f: File | null) => void;
}) {
  // The folder is derived from the landing id, so drop the manual `path` field.
  const visibleFields = useMemo(() => fields.filter((f) => f.key !== 'path'), [fields]);

  const [type, setType] = useState<string>(
    () => String(buildInitialValues(fields, record).type ?? 'local'),
  );
  const [file, setFile] = useState<File | null>(null);

  const currentFolder = useMemo(() => {
    const s = (record?.settings ?? {}) as { type?: string; path?: string };
    return s.type !== 'remote' && s.path ? s.path : '';
  }, [record]);

  useEffect(() => {
    setFile(null);
    onFileChange(null);
    setType(String(buildInitialValues(fields, record).type ?? 'local'));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [record]);

  const fileRef = useRef<HTMLInputElement>(null);

  return (
    <div className="space-y-4">
      <EntityForm
        fields={visibleFields}
        record={record}
        onValuesChange={(v) => {
          setType(String(v.type ?? 'local'));
          onValuesChange(v);
        }}
      />

      {type !== 'remote' && (
        <FormRow
          label={currentFolder ? 'Replace landing files (ZIP)' : 'Landing files (ZIP)'}
          required={!currentFolder}
          help={
            currentFolder
              ? `Current folder: ${currentFolder}. Choose a ZIP to replace its contents (optional).`
              : 'Upload the landing as a .zip. It is extracted into a folder named after this landing’s id. Must contain index.php or index.html at the root or inside a single top-level folder.'
          }
        >
          <input
            ref={fileRef}
            type="file"
            accept=".zip,application/zip"
            onChange={(e) => {
              const f = e.target.files?.[0] ?? null;
              setFile(f);
              onFileChange(f);
            }}
            className="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-brand file:px-3 file:py-1.5 file:text-brand-fg file:font-medium hover:file:opacity-90"
          />
          {file && (
            <p className="mt-1.5 flex items-center gap-1.5 text-xs text-muted">
              <UploadCloud size={13} /> {file.name} · {(file.size / 1024 / 1024).toFixed(1)} MB
            </p>
          )}
        </FormRow>
      )}
    </div>
  );
}
