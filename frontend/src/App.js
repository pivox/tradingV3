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
import OrderPlanPage from './pages/OrderPlanPage';
import MtfAuditPage from './pages/MtfAuditPage';
import MtfSwitchPage from './pages/MtfSwitchPage';
import IndicatorSnapshotPage from './pages/IndicatorSnapshotPage';
import ValidationCachePage from './pages/ValidationCachePage';
import BlacklistedContractPage from './pages/BlacklistedContractPage';
import MtfLockPage from './pages/MtfLockPage';
import './styles/app.css';

function App() {
    return (
        <BrowserRouter>
            <div className="app-container">
                <nav className="sidebar">
                    <ul>
                        <li><Link to="/">Dashboard</Link></li>
                        <li><Link to="/graph">Graphiques</Link></li>
                        <li><Link to="/contracts">Contrats</Link></li>
                        <li><Link to="/positions">Positions</Link></li>
                        <li><Link to="/pipeline">Pipeline</Link></li>
                        <li><Link to="/signals">Signaux</Link></li>
                        <li><Link to="/klines">Klines</Link></li>
                        <li><Link to="/mtf-state">États MTF</Link></li>
                        <li><Link to="/order-plans">Plans de Commandes</Link></li>
                        <li><Link to="/mtf-audit">Audits MTF</Link></li>
                        <li><Link to="/mtf-switch">Switches MTF</Link></li>
                        <li><Link to="/indicator-snapshots">Snapshots Indicateurs</Link></li>
                        <li><Link to="/validation-cache">Cache Validation</Link></li>
                        <li><Link to="/blacklisted-contracts">Contrats Blacklistés</Link></li>
                        <li><Link to="/mtf-locks">Verrous MTF</Link></li>
                        <li><Link to="/trading-configurations">Configurations</Link></li>
                        <li><Link to="/exchange-accounts">Comptes Exchange</Link></li>
                    </ul>
                </nav>
                <main className="content">
                    <Routes>
                        <Route path="/" element={<DashboardPage />} />
                        <Route path="/graph" element={<ChartsPage />} />
                        <Route path="/contracts/:contractId?" element={<ContractPage />} />
                        <Route path="/positions" element={<PositionsPage />} />
                        <Route path="/pipeline" element={<PipelinePage />} />
                        <Route path="/signals" element={<SignalsPage />} />
                        <Route path="/klines" element={<KlinesPage />} />
                        <Route path="/mtf-state" element={<MtfStatePage />} />
                        <Route path="/order-plans" element={<OrderPlanPage />} />
                        <Route path="/mtf-audit" element={<MtfAuditPage />} />
                        <Route path="/mtf-switch" element={<MtfSwitchPage />} />
                        <Route path="/indicator-snapshots" element={<IndicatorSnapshotPage />} />
                        <Route path="/validation-cache" element={<ValidationCachePage />} />
                        <Route path="/blacklisted-contracts" element={<BlacklistedContractPage />} />
                        <Route path="/mtf-locks" element={<MtfLockPage />} />
                        <Route path="/trading-configurations" element={<TradingConfigurationsPage />} />
                        <Route path="/exchange-accounts" element={<ExchangeAccountsPage />} />
                    </Routes>
                </main>
            </div>
        </BrowserRouter>
    );
}

export default App;
