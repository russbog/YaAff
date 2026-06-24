import { useParams } from 'react-router-dom';
import { CampaignEditor } from '@/components/campaign/CampaignEditor';
import { useBootstrap } from '@/providers/BootstrapProvider';

// Native campaign / TDS builder. Loads settings via spa.php?r=campaign and
// saves them back through campeditor.php?action=save (same contract the legacy
// form used), so routing rules, flows, safe page, scripts and postbacks keep
// full parity while running entirely in the modern shell.
export function CampaignSettingsPage() {
  const { id } = useParams<{ id: string }>();
  const campId = Number(id);
  const { campaignsList } = useBootstrap();
  const name = campaignsList.find((c) => c.id === campId)?.name || `Campaign #${campId}`;

  return <CampaignEditor campId={campId} name={name} />;
}
