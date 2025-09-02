// src/services/api.js
import config from '../config';

const handleResponse = async (response) => {
    if (!response.ok) {
        const error = await response.json().catch(() => ({ message: 'Erreur réseau' }));
        throw new Error(error.message || `Erreur ${response.status}`);
    }
    return await response.json();
};

const api = {
    // Contrats
    async getContracts(search = '') {
        const url = `${config.apiUrl}${config.endpoints.contracts}${search ? `?search=${search}` : ''}`;
        const response = await fetch(url);
        return handleResponse(response);
    },

    async getContract(id) {
        const response = await fetch(`${config.apiUrl}${config.endpoints.contracts}/${id}`);
        return handleResponse(response);
    },

    // Positions
    async getPositions(filters = {}) {
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => {
            if (value !== 'all' && value !== '' && value !== undefined) {
                params.append(key, value);
            }
        });
        const queryString = params.toString() ? `?${params.toString()}` : '';
        const response = await fetch(`${config.apiUrl}${config.endpoints.positions}${queryString}`);
        return handleResponse(response);
    },

    // Données de graphiques
    async getChartData(contractId, timeframe = '1h') {
        const response = await fetch(`${config.apiUrl}${config.endpoints.chart}/${contractId}?timeframe=${timeframe}`);
        return handleResponse(response);
    },

    // Klines
    async getKlines(contractId, interval = '1h', limit = 100) {
        const response = await fetch(`${config.apiUrl}${config.endpoints.klines}/${contractId}?interval=${interval}&limit=${limit}`);
        return handleResponse(response);
    },

    // Pipeline
    async getPipeline(status = 'all') {
        const response = await fetch(`${config.apiUrl}${config.endpoints.pipeline}${status !== 'all' ? `?status=${status}` : ''}`);
        return handleResponse(response);
    },

    // Setups
    async getSetups(contractId) {
        const response = await fetch(`${config.apiUrl}${config.endpoints.setups}/${contractId}`);
        return handleResponse(response);
    },

    // Indicateurs (API Python)
    async calculateIndicators(symbol, interval, indicators = []) {
        const response = await fetch(`${config.pythonApiUrl}/indicators/calculate`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ symbol, interval, indicators })
        });
        return handleResponse(response);
    }
};

export default api;