// src/services/api.js
import config from '../config';

const handleResponse = async (response) => {
    if (!response.ok) {
        const error = await response.json().catch(() => ({ message: 'Erreur réseau' }));
        // FastAPI (API Python Orchestrator) renvoie le message d'erreur dans `detail`.
        throw new Error(error.message || error.detail || `Erreur ${response.status}`);
    }
    return await response.json();
};

const api = {
    // Contrats
    async getContracts(search = '', opts = {}) {
        const query = search ? `?symbol=${encodeURIComponent(search)}` : '';
        const url = `${config.apiUrl}${config.endpoints.contracts}${query}`;
        const response = await fetch(url, { signal: opts.signal });
        const data = await handleResponse(response);
        const items = Array.isArray(data)
            ? data
            : (data?.['hydra:member'] || data?.items || data?.data || []);
        return items;
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

    // Données de graphiques (legacy, non utilisé pour Dashboard)
    async getChartData(contractId, timeframe = '1h') {
        const response = await fetch(`${config.apiUrl}${config.endpoints.chart}/${contractId}?timeframe=${timeframe}`);
        return handleResponse(response);
    },

    // Klines (endpoint /api/klines : tableau trié du plus récent au plus ancien)
    async fetchKlines(symbol, interval, limit) {
        const params = new URLSearchParams({
            symbol: String(symbol).toUpperCase(),
            interval,
            limit: String(limit),
        });
        const response = await fetch(`${config.apiUrl}/api/klines?${params.toString()}`);
        const data = await handleResponse(response);
        return (Array.isArray(data) ? data : [])
            .map((k) => ({ ...k, timestamp: k.openTime }))
            .sort((x, y) => x.timestamp - y.timestamp);
    },

    async getKlines(symbol, interval = '1h', limit = 100) {
        return this.fetchKlines(symbol, interval, limit);
    },

    async getKlinesRange(symbol, interval = '1h', start, end) {
        const startMs = start ? new Date(start).getTime() : null;
        const endMs = end ? new Date(end).getTime() : null;
        const klines = await this.fetchKlines(symbol, interval, 500);
        return klines.filter((k) => (
            (startMs === null || Number.isNaN(startMs) || k.timestamp >= startMs)
            && (endMs === null || Number.isNaN(endMs) || k.timestamp <= endMs)
        ));
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

    // Trading configuration
    async getTradingConfigurations() {
        const response = await fetch(`${config.apiUrl}${config.endpoints.tradingConfigurations}`);
        return handleResponse(response);
    },

    async createTradingConfiguration(payload) {
        const response = await fetch(`${config.apiUrl}${config.endpoints.tradingConfigurations}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return handleResponse(response);
    },

    async updateTradingConfiguration(id, payload) {
        const response = await fetch(`${config.apiUrl}${config.endpoints.tradingConfigurations}/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return handleResponse(response);
    },

    // Exchange accounts
    async getExchangeAccounts() {
        const response = await fetch(`${config.apiUrl}${config.endpoints.exchangeAccounts}`);
        return handleResponse(response);
    },

    async createExchangeAccount(payload) {
        const response = await fetch(`${config.apiUrl}${config.endpoints.exchangeAccounts}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return handleResponse(response);
    },

    async updateExchangeAccount(id, payload) {
        const response = await fetch(`${config.apiUrl}${config.endpoints.exchangeAccounts}/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
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
    },

    // MTF Switches
    async getMtfSwitches(params = {}) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
        const response = await fetch(`${config.apiUrl}/api/mtf-switches${queryString}`);
        return handleResponse(response);
    },

    async toggleMtfSwitch(switchId) {
        const response = await fetch(`${config.apiUrl}/api/mtf-switches/${switchId}/toggle`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        return handleResponse(response);
    },

    async addMtfSwitch(payload) {
        const response = await fetch(`${config.apiUrl}/api/mtf-switches`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return handleResponse(response);
    },

    // Missing Klines
    async getMissingKlines(params) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const response = await fetch(`${config.apiUrl}/api/klines/missing?${queryParams.toString()}`);
        return handleResponse(response);
    },

    async triggerBackfill(payload) {
        const response = await fetch(`${config.apiUrl}/api/klines/backfill`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return handleResponse(response);
    },

    // MTF States
    async getMtfStates(params = {}) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
        const response = await fetch(`${config.apiUrl}/api/mtf-states${queryString}`);
        return handleResponse(response);
    },

    // MTF Audits
    async getMtfAudits(params = {}) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
        const response = await fetch(`${config.apiUrl}/api/mtf-audits${queryString}`);
        return handleResponse(response);
    },

    // MTF Locks
    async getMtfLocks(params = {}) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
        const response = await fetch(`${config.apiUrl}/api/mtf-locks${queryString}`);
        return handleResponse(response);
    },

    // Indicator Snapshots
    async getIndicatorSnapshots(params = {}) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
        const response = await fetch(`${config.apiUrl}/api/indicator-snapshots${queryString}`);
        return handleResponse(response);
    },

    // Validation Cache
    async getValidationCacheEntries(params = {}) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
        const response = await fetch(`${config.apiUrl}/api/validation-cache${queryString}`);
        return handleResponse(response);
    },

    // Blacklisted Contracts
    async getBlacklistedContracts(params = {}) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
        const response = await fetch(`${config.apiUrl}/api/blacklisted-contracts${queryString}`);
        return handleResponse(response);
    },

    // Signals
    async getSignals(params = {}) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
        const response = await fetch(`${config.apiUrl}/api/signals${queryString}`);
        return handleResponse(response);
    },

    // Runtime History
    async getRuntimeHistory(params = {}) {
        const queryParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                queryParams.append(key, value);
            }
        });
        const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
        const response = await fetch(`${config.apiUrl}/api/runtime-history${queryString}`);
        return handleResponse(response);
    },

    // --- Orchestration cockpit (API Python Orchestrator, UI-001) -------------

    // Liste des dashboards d'orchestration.
    async getOrchestrationDashboards() {
        const response = await fetch(`${config.orchestratorApiUrl}/dashboards`);
        return handleResponse(response);
    },

    // Sets configurés d'un dashboard (option `enabledOnly` pour ne garder que les actifs).
    async getOrchestrationSets(dashboardId, { enabledOnly = false } = {}) {
        const query = enabledOnly ? '?enabled_only=true' : '';
        const response = await fetch(`${config.orchestratorApiUrl}/dashboards/${dashboardId}/sets${query}`);
        return handleResponse(response);
    },

    // Refresh explicite des contrats des sets `mtf_run` actifs (PY-003).
    async refreshOrchestrationContracts(dashboardId) {
        const response = await fetch(
            `${config.orchestratorApiUrl}/dashboards/${dashboardId}/refresh-contracts`,
            { method: 'POST', headers: { 'Content-Type': 'application/json' } }
        );
        return handleResponse(response);
    },

    // Déclenche un run d'orchestration (PY-005). `forceDryRun` force le dry-run au niveau run.
    async triggerOrchestrationRun(dashboardId, { forceDryRun = false } = {}) {
        const body = { dashboard_id: String(dashboardId) };
        if (forceDryRun) {
            body.dry_run = true;
        }
        const response = await fetch(`${config.orchestratorApiUrl}/orchestrator/run`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        return handleResponse(response);
    },

    // Dernier run d'un dashboard : dernier JSON global + détail par set (PY-006).
    // Retourne `null` si aucun run n'a encore été persisté (404).
    async getOrchestrationLatestRun(dashboardId) {
        const response = await fetch(`${config.orchestratorApiUrl}/dashboards/${dashboardId}/runs/latest`);
        if (response.status === 404) {
            return null;
        }
        return handleResponse(response);
    },

    // Historique des runs d'un dashboard (PY-006), du plus récent au plus ancien.
    // Vue allégée `RunSummaryRead` (sans `last_json` ni détail par set). La page est
    // bornée côté API (`_MAX_RUNS_PAGE_SIZE=100`).
    async getOrchestrationRuns(dashboardId, { limit = 20, offset = 0 } = {}) {
        const params = new URLSearchParams({ limit: String(limit), offset: String(offset) });
        const response = await fetch(
            `${config.orchestratorApiUrl}/dashboards/${dashboardId}/runs?${params.toString()}`
        );
        return handleResponse(response);
    },

    // Détail complet d'un run par son identifiant (PY-006) : dernier JSON global +
    // détail / erreurs par set (`RunDetailRead`), même forme que le dernier run.
    async getOrchestrationRun(runId) {
        const response = await fetch(`${config.orchestratorApiUrl}/runs/${runId}`);
        return handleResponse(response);
    }
};

export default api;
