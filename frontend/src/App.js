// src/App.js
import React from 'react';
import { BrowserRouter, Routes, Route, Link } from 'react-router-dom';
import DashboardPage from './pages/DashboardPage';
import ContractPage from './pages/ContractPage';
import PositionsPage from './pages/PositionsPage';
import PipelinePage from './pages/PipelinePage';
import TradingConfigurationsPage from './pages/TradingConfigurationsPage';
import ExchangeAccountsPage from './pages/ExchangeAccountsPage';
import ChartsPage from './pages/ChartsPage';
import SignalsPage from './pages/SignalsPage';
import KlinesPage from './pages/KlinesPage';
import MtfStatePage from './pages/MtfStatePage';
import MtfDashboardPage from './pages/MtfDashboardPage';
import MtfAuditPage from './pages/MtfAuditPage';
import MtfSwitchPage from './pages/MtfSwitchPage';
import IndicatorSnapshotPage from './pages/IndicatorSnapshotPage';
import ValidationCachePage from './pages/ValidationCachePage';
import BlacklistedContractPage from './pages/BlacklistedContractPage';
import MtfLockPage from './pages/MtfLockPage';
import MissingKlinesPage from './pages/MissingKlinesPage';
import GlobalSearchPage from './pages/GlobalSearchPage';
import HealthMonitoringPage from './pages/HealthMonitoringPage';
import RuntimeHistoryPage from './pages/RuntimeHistoryPage';
import './styles/app.css';

function App() {
    return (
        <BrowserRouter>
            <div className="app-container">
                <nav className="sidebar">
                    <ul>
                        <li><Link to="/">Dashboard</Link></li>
                        <li><Link to="/mtf-dashboard">Dashboard MTF</Link></li>
                        <li><Link to="/search">Recherche Globale</Link></li>
                        <li><Link to="/graph">Graphiques</Link></li>
                        <li><Link to="/contracts">Contrats</Link></li>
                        <li><Link to="/positions">Positions</Link></li>
                        <li><Link to="/pipeline">Pipeline</Link></li>
                        <li><Link to="/signals">Signaux</Link></li>
                        <li><Link to="/klines">Klines</Link></li>
                        <li><Link to="/missing-klines">Bougies Manquantes</Link></li>
                        <li><Link to="/mtf-state">États MTF</Link></li>
                        <li><Link to="/mtf-audit">Audits MTF</Link></li>
                        <li><Link to="/mtf-switch">Switches MTF</Link></li>
                        <li><Link to="/indicator-snapshots">Snapshots Indicateurs</Link></li>
                        <li><Link to="/validation-cache">Cache Validation</Link></li>
                        <li><Link to="/blacklisted-contracts">Contrats Blacklistés</Link></li>
                        <li><Link to="/mtf-locks">Verrous MTF</Link></li>
                        <li><Link to="/trading-configurations">Configurations</Link></li>
                        <li><Link to="/exchange-accounts">Comptes Exchange</Link></li>
                        <li><Link to="/health">Santé & Monitoring</Link></li>
                        <li><Link to="/runtime-history">Historique Runtime</Link></li>
                    </ul>
                </nav>
                <main className="content">
                    <Routes>
                        <Route path="/" element={<DashboardPage />} />
                        <Route path="/mtf-dashboard" element={<MtfDashboardPage />} />
                        <Route path="/search" element={<GlobalSearchPage />} />
                        <Route path="/graph" element={<ChartsPage />} />
                        <Route path="/contracts/:contractId?" element={<ContractPage />} />
                        <Route path="/positions" element={<PositionsPage />} />
                        <Route path="/pipeline" element={<PipelinePage />} />
                        <Route path="/signals" element={<SignalsPage />} />
                        <Route path="/klines" element={<KlinesPage />} />
                        <Route path="/missing-klines" element={<MissingKlinesPage />} />
                        <Route path="/mtf-state" element={<MtfStatePage />} />
                        <Route path="/mtf-audit" element={<MtfAuditPage />} />
                        <Route path="/mtf-switch" element={<MtfSwitchPage />} />
                        <Route path="/indicator-snapshots" element={<IndicatorSnapshotPage />} />
                        <Route path="/validation-cache" element={<ValidationCachePage />} />
                        <Route path="/blacklisted-contracts" element={<BlacklistedContractPage />} />
                        <Route path="/mtf-locks" element={<MtfLockPage />} />
                        <Route path="/trading-configurations" element={<TradingConfigurationsPage />} />
                        <Route path="/exchange-accounts" element={<ExchangeAccountsPage />} />
                        <Route path="/health" element={<HealthMonitoringPage />} />
                        <Route path="/runtime-history" element={<RuntimeHistoryPage />} />
                    </Routes>
                </main>
            </div>
        </BrowserRouter>
    );
}

export default App;
