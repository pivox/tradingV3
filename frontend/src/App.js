import React from 'react';
import { BrowserRouter as Router, Routes, Route, Link } from 'react-router-dom';
import DashboardPage from './pages/DashboardPage';
import ChartsPage from './pages/ChartsPage';
import SetupsPage from './pages/SetupsPage';
import TopContractsPage from './pages/TopContractsPage';
import HistoryPage from './pages/HistoryPage';
import SettingsPage from './pages/SettingsPage';

function App() {
    return (
        <Router>
            <nav className="p-4 shadow-lg flex space-x-4 bg-white">
                <Link to="/dashboard">Dashboard</Link>
                <Link to="/charts">Charts</Link>
                <Link to="/setups">Setups</Link>
                <Link to="/top-contracts">Top Contrats</Link>
                <Link to="/history">Historique</Link>
                <Link to="/settings">Paramètres</Link>
            </nav>
            <div className="p-4">
                <Routes>
                    <Route path="/dashboard" element={<DashboardPage />} />
                    <Route path="/charts" element={<ChartsPage />} />
                    <Route path="/setups" element={<SetupsPage />} />
                    <Route path="/top-contracts" element={<TopContractsPage />} />
                    <Route path="/history" element={<HistoryPage />} />
                    <Route path="/settings" element={<SettingsPage />} />
                    <Route path="/" element={<DashboardPage />} />
                </Routes>
            </div>
        </Router>
    );
}

export default App;
