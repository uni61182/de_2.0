const API_BASE = '/api/v1';

let accessToken = localStorage.getItem('de2_access_token') || '';
let refreshToken = localStorage.getItem('de2_refresh_token') || '';

const loginForm = document.getElementById('loginForm');
const loginError = document.getElementById('loginError');
const loginCard = document.getElementById('loginCard');
const dashboard = document.getElementById('dashboard');
const overviewEl = document.getElementById('overview');
const resourcesEl = document.getElementById('resources');
const fleetsEl = document.getElementById('fleets');
const refreshBtn = document.getElementById('refreshBtn');
const logoutBtn = document.getElementById('logoutBtn');
const moveFleetForm = document.getElementById('moveFleetForm');
const moveResult = document.getElementById('moveResult');

if (accessToken) {
  showDashboard();
  loadData().catch(() => {
    logout();
  });
}

loginForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  loginError.textContent = '';

  const username = document.getElementById('username').value.trim();
  const password = document.getElementById('password').value;

  try {
    const data = await apiPost('/auth/login', { username, password }, false);
    setTokens(data.access_token, data.refresh_token || '');
    showDashboard();
    await loadData();
  } catch (error) {
    loginError.textContent = error.message;
  }
});

refreshBtn.addEventListener('click', () => loadData());
logoutBtn.addEventListener('click', () => logout());

moveFleetForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  moveResult.textContent = 'Sende...';

  const fleet_slot = Number(document.getElementById('fleetSlot').value);
  const target_sector = Number(document.getElementById('targetSector').value);
  const target_system = Number(document.getElementById('targetSystem').value);

  try {
    const data = await apiPost('/fleet/move', { fleet_slot, target_sector, target_system });
    moveResult.textContent = `Flotte ${data.fleet_slot} nach ${data.target_sector}:${data.target_system} geschickt.`;
    await loadData();
  } catch (error) {
    moveResult.textContent = `Fehler: ${error.message}`;
  }
});

async function loadData() {
  overviewEl.textContent = 'Lade...';
  resourcesEl.textContent = 'Lade...';
  fleetsEl.textContent = 'Lade...';

  try {
    const [overview, resources, fleets] = await Promise.all([
      apiGet('/player/overview'),
      apiGet('/player/resources'),
      apiGet('/player/fleets'),
    ]);

    overviewEl.textContent = JSON.stringify(overview, null, 2);
    resourcesEl.textContent = JSON.stringify(resources, null, 2);
    fleetsEl.textContent = JSON.stringify(fleets, null, 2);
  } catch (error) {
    overviewEl.textContent = `Fehler: ${error.message}`;
    throw error;
  }
}

async function apiGet(path) {
  return apiRequest(path, 'GET');
}

async function apiPost(path, payload, auth = true) {
  return apiRequest(path, 'POST', payload, auth);
}

async function apiRequest(path, method, payload = null, auth = true) {
  let response = await fetch(`${API_BASE}${path}`, {
    method,
    headers: {
      ...(auth ? { Authorization: `Bearer ${accessToken}` } : {}),
      ...(payload ? { 'Content-Type': 'application/json' } : {}),
    },
    ...(payload ? { body: JSON.stringify(payload) } : {}),
  });

  if (response.status === 401 && auth && refreshToken) {
    await refreshAccessToken();
    response = await fetch(`${API_BASE}${path}`, {
      method,
      headers: {
        Authorization: `Bearer ${accessToken}`,
        ...(payload ? { 'Content-Type': 'application/json' } : {}),
      },
      ...(payload ? { body: JSON.stringify(payload) } : {}),
    });
  }

  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(body.error || 'API Fehler');
  }

  return body;
}

async function refreshAccessToken() {
  const data = await apiPost('/auth/refresh', { refresh_token: refreshToken }, false);
  setTokens(data.access_token, refreshToken);
}

function setTokens(nextAccessToken, nextRefreshToken) {
  accessToken = nextAccessToken;
  refreshToken = nextRefreshToken;
  localStorage.setItem('de2_access_token', accessToken);
  localStorage.setItem('de2_refresh_token', refreshToken);
}

function showDashboard() {
  loginCard.classList.add('hidden');
  dashboard.classList.remove('hidden');
}

function logout() {
  accessToken = '';
  refreshToken = '';
  localStorage.removeItem('de2_access_token');
  localStorage.removeItem('de2_refresh_token');
  dashboard.classList.add('hidden');
  loginCard.classList.remove('hidden');
  moveResult.textContent = '';
}
