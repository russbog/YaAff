import { useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { UploadCloud } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input, FormRow } from '@/components/ui/Field';
import { useToast } from '@/providers/ToastProvider';
import { folderApi, type FolderType } from '@/lib/api';

const FOLDER_RE = /^[a-zA-Z0-9_\-.]+$/;

// Native landing ZIP upload (zipupload.php). The archive must contain
// index.php/index.html at its root or inside a single top folder. PHP files
// are preserved and executed when the landing is served.
export function ZipUploadModal({
  open,
  onClose,
  onUploaded,
  type = 'landing',
}: {
  open: boolean;
  onClose: () => void;
  onUploaded: (folder: string) => void;
  type?: FolderType;
}) {
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [folder, setFolder] = useState('');
  const [file, setFile] = useState<File | null>(null);

  const reset = () => {
    setFolder('');
    setFile(null);
  };

  const mut = useMutation({
    mutationFn: () => folderApi.uploadZip(folder.trim(), file as File, type),
    onSuccess: () => {
      toast.success(`Landing “${folder.trim()}” uploaded`);
      const f = folder.trim();
      reset();
      onUploaded(f);
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const submit = () => {
    const f = folder.trim();
    if (!FOLDER_RE.test(f)) return toast.error('Folder: letters, numbers, hyphen, underscore, dot only');
    if (!file) return toast.error('Choose a .zip archive');
    mut.mutate();
  };

  return (
    <Modal
      open={open}
      onClose={() => {
        reset();
        onClose();
      }}
      title="Upload landing (ZIP)"
      description="The archive becomes a local landing folder. PHP/HTML/assets are kept as-is."
      footer={
        <>
          <Button
            variant="ghost"
            onClick={() => {
              reset();
              onClose();
            }}
          >
            Cancel
          </Button>
          <Button variant="primary" loading={mut.isPending} onClick={submit}>
            <UploadCloud size={15} /> Upload
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <FormRow label="Folder name" required>
          <Input
            autoFocus
            value={folder}
            onChange={(e) => setFolder(e.target.value)}
            placeholder="e.g. nutra-de-1"
          />
        </FormRow>
        <FormRow label="ZIP archive" required>
          <input
            ref={fileRef}
            type="file"
            accept=".zip,application/zip"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            className="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-brand file:px-3 file:py-1.5 file:text-brand-fg file:font-medium hover:file:opacity-90"
          />
          {file && <p className="mt-1.5 text-xs text-muted">{file.name} · {(file.size / 1024 / 1024).toFixed(1)} MB</p>}
        </FormRow>
        <p className="text-xs text-faint">
          Must contain <code>index.php</code> or <code>index.html</code> at the root or inside a
          single top-level folder.
        </p>
      </div>
    </Modal>
  );
}
