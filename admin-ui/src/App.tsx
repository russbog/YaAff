import { Navigate, Route, Routes } from 'react-router-dom';
import { useBootstrap } from './providers/BootstrapProvider';
import { CampaignsPage } from './pages/CampaignsPage';
import { DashboardPage } from './pages/DashboardPage';
import { ReportsPage } from './pages/ReportsPage';
import { ConversionsPage } from './pages/ConversionsPage';
import { EntityPage } from './pages/EntityPage';
import { CampaignSettingsPage } from './pages/CampaignSettingsPage';
import { BotProtectionPage } from './pages/BotProtectionPage';
import { DataPage } from './pages/DataPage';
import { ApiDocsPage } from './pages/ApiDocsPage';
import { NotFoundPage } from './pages/NotFoundPage';

const ENTITY_ROUTES = [
  'offers',
  'landings',
  'sources',
  'networks',
  'domains',
  'integrations',
  'rules',
  'channels',
  'users',
  'roles',
] as const;

export default function App() {
  const { nav } = useBootstrap();
  const home = nav[0]?.key ?? 'campaigns';

  return (
    <Routes>
      <Route path="/" element={<Navigate to={`/${home}`} replace />} />
      <Route path="/campaigns" element={<CampaignsPage />} />
      <Route path="/campaign/:id" element={<CampaignSettingsPage />} />
      <Route path="/dashboard" element={<DashboardPage />} />
      <Route path="/reports" element={<ReportsPage />} />
      <Route path="/conversions" element={<ConversionsPage />} />
      {ENTITY_ROUTES.map((type) => (
        <Route key={type} path={`/${type}`} element={<EntityPage type={type} />} />
      ))}
      <Route path="/blacklists" element={<BotProtectionPage />} />
      <Route path="/data" element={<DataPage />} />
      <Route path="/api" element={<ApiDocsPage />} />
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}
