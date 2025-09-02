// src/App.js
import React from 'react';
import { BrowserRouter, Routes, Route, Link } from 'react-router-dom';
import DashboardPage from './pages/DashboardPage';
import ContractPage from './pages/ContractPage';
import PositionsPage from './pages/PositionsPage';
import PipelinePage from './pages/PipelinePage';
import './styles/app.css';

function App() {
    return (
        <BrowserRouter>
            <div className="app-container">
                <nav className="sidebar">
                    <ul>
                        <li><Link to="/">Dashboard</Link></li>
                        <li><Link to="/contracts">Contrats</Link></li>
                        <li><Link to="/positions">Positions</Link></li>
                        <li><Link to="/pipeline">Pipeline</Link></li>
                    </ul>
                </nav>
                <main className="content">
                    <Routes>
                        <Route path="/" element={<DashboardPage />} />
                        <Route path="/contracts/:contractId?" element={<ContractPage />} />
                        <Route path="/positions" element={<PositionsPage />} />
                        <Route path="/pipeline" element={<PipelinePage />} />
                    </Routes>
                </main>
            </div>
        </BrowserRouter>
    );
}

export default App;