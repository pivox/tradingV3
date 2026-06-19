// src/config.js
const config = {
    apiUrl: process.env.REACT_APP_API_URL || 'http://localhost:8080',
    pythonApiUrl: process.env.REACT_APP_PYTHON_API_URL || 'http://localhost:8888',
    // API Python Orchestrator (FastAPI, port interne 8099) : dashboards, sets,
    // déclenchement des runs et lecture du dernier JSON pour le cockpit (UI-001).
    orchestratorApiUrl: process.env.REACT_APP_ORCHESTRATOR_API_URL || 'http://localhost:8099',
    positionsRealtimeBaseUrl: process.env.REACT_APP_POSITIONS_WS_BASE_URL
        || process.env.REACT_APP_PYTHON_API_URL
        || 'http://localhost:9000',
    temporalUiUrl: process.env.REACT_APP_TEMPORAL_UI_URL || 'http://localhost:8233',

    // Endpoints API courants
    endpoints: {
        contracts: '/api/contracts',
        positions: '/api/positions',
        klines: '/api/klines',
        pipeline: '/api/contract-pipeline',
        chart: '/api/chart-data',
        setups: '/api/setups',
        tradingConfigurations: '/api/trading-configurations',
        exchangeAccounts: '/api/user-exchange-accounts'
    }
};

export default config;
