import { useParams } from 'react-router-dom';
import { LegacyPage } from './LegacyPage';
import { useBootstrap } from '@/providers/BootstrapProvider';

// Campaign / TDS builder hosted in-shell. Runs on the existing PHP campaign
// settings engine (campsettings.php) chrome-free so routing rules, flows,
// safe page, bot protection and postbacks keep full parity.
export function CampaignSettingsPage() {
  const { id } = useParams<{ id: string }>();
  const campId = Number(id);
  const { campaignsList } = useBootstrap();
  const name =
    campaignsList.find((c) => c.id === campId)?.name || `Campaign #${campId}`;

  return (
    <LegacyPage
      title={name}
      file="campsettings.php"
      params={{ campId }}
      backTo="/campaigns"
    />
  );
}
