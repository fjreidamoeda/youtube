const searchInput = document.getElementById('searchInput');
const searchBtn = document.getElementById('searchBtn');
const channelsSection = document.getElementById('channelsSection');
const channelsGrid = document.getElementById('channelsGrid');
const videosSection = document.getElementById('videosSection');
const videosGrid = document.getElementById('videosGrid');
const exportBtn = document.getElementById('exportBtn');
const exportSqlBtn = document.getElementById('exportSqlBtn');
const streamflowUrl = document.getElementById('streamflowUrl');
const sqlMode = document.getElementById('sqlMode');
const errorDiv = document.getElementById('error');
const loader = document.getElementById('loader');
const channelInput = document.getElementById('channelInput');
const addChannelBtn = document.getElementById('addChannelBtn');
const playlistUrl = document.getElementById('playlistUrl');
const copyUrlBtn = document.getElementById('copyUrlBtn');
const savedChannels = document.getElementById('savedChannels');

let currentVideos = [];
let currentCategory = 'CANAIS';

playlistUrl.value = `${location.origin}/playlist.m3u8`;
loadSavedChannels();

document.querySelectorAll('input[name="category"]').forEach(radio => {
    radio.addEventListener('change', () => {
        currentCategory = document.querySelector('input[name="category"]:checked').value;
    });
});

searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') search(); });
searchBtn.addEventListener('click', search);
exportBtn.addEventListener('click', exportM3U);
exportSqlBtn.addEventListener('click', exportSQL);
channelInput.addEventListener('keydown', e => { if (e.key === 'Enter') addChannel(); });
addChannelBtn.addEventListener('click', addChannel);
copyUrlBtn.addEventListener('click', () => {
    playlistUrl.select();
    document.execCommand('copy');
    copyUrlBtn.textContent = 'Copiado!';
    setTimeout(() => { copyUrlBtn.textContent = 'Copiar'; }, 1500);
});

function showError(msg) {
    if (!msg) { errorDiv.classList.add('hidden'); return; }
    errorDiv.textContent = msg;
    errorDiv.classList.remove('hidden');
}

function showLoader(on) {
    loader.classList.toggle('hidden', !on);
}

async function search() {
    const q = searchInput.value.trim();
    if (!q) return;
    showError();
    showLoader(true);
    channelsSection.classList.add('hidden');
    videosSection.classList.add('hidden');
    try {
        const res = await fetch(`/api/search_channels?q=${encodeURIComponent(q)}&max_results=10`);
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro na busca');
        renderChannels(data.channels);
    } catch (e) {
        showError(e.message);
    } finally {
        showLoader(false);
    }
}

function renderChannels(channels) {
    channelsGrid.innerHTML = '';
    if (!channels.length) {
        showError('Nenhum canal encontrado.');
        return;
    }
    channels.forEach(ch => {
        const card = document.createElement('div');
        card.className = 'channel-card rounded p-3';
        card.innerHTML = `
            <img src="${ch.thumbnail}" alt="${ch.title}" class="w-full aspect-video object-cover rounded mb-2"
                onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22320%22 height=%22180%22><rect fill=%2212121a%22 width=%22320%22 height=%22180%22/></svg>'">
            <h3 class="text-sm font-bold text-gray-200 truncate uppercase">${ch.title}</h3>
            <p class="text-xs text-gray-500 truncate mt-1">${ch.description || 'Sem descrição'}</p>
        `;
        card.addEventListener('click', () => loadVideos(ch.id, ch.title));
        channelsGrid.appendChild(card);
    });
    channelsSection.classList.remove('hidden');
}

async function loadVideos(channelId, channelTitle) {
    showError();
    showLoader(true);
    videosSection.classList.add('hidden');
    try {
        const res = await fetch(`/api/get_videos?channel_id=${channelId}&max_results=50`);
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro ao carregar vídeos');
        renderVideos(data.videos, channelTitle);
    } catch (e) {
        showError(e.message);
    } finally {
        showLoader(false);
    }
}

function renderVideos(videos, channelTitle) {
    videosGrid.innerHTML = '';
    currentVideos = videos;
    if (!videos.length) {
        showError('Nenhum vídeo encontrado para este canal.');
        exportBtn.classList.add('hidden');
        exportSqlBtn.classList.add('hidden');
        streamflowUrl.classList.add('hidden');
        sqlMode.classList.add('hidden');
        return;
    }
    videos.forEach(v => {
        const card = document.createElement('div');
        card.className = 'video-card rounded p-2';
        card.innerHTML = `
            <img src="${v.thumbnail}" alt="${v.title}" class="w-full aspect-video object-cover rounded mb-2"
                onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22320%22 height=%22180%22><rect fill=%2212121a%22 width=%22320%22 height=%22180%22/></svg>'">
            <h3 class="text-xs font-bold text-gray-200 truncate uppercase">${v.title}</h3>
            <p class="text-xs text-gray-600 mt-1">${new Date(v.published_at).toLocaleDateString('pt-BR')}</p>
        `;
        videosGrid.appendChild(card);
    });
    videosSection.classList.remove('hidden');
    exportBtn.classList.remove('hidden');
    exportSqlBtn.classList.remove('hidden');
    streamflowUrl.classList.remove('hidden');
    sqlMode.classList.remove('hidden');
}

function exportM3U() {
    if (!currentVideos.length) return;
    const ids = currentVideos.map(v => v.id).join(',');
    const titles = currentVideos.map(v => v.title).join(',');
    const thumbs = currentVideos.map(v => v.thumbnail).join(',');
    const url = `/api/export_m3u?ids=${encodeURIComponent(ids)}&titles=${encodeURIComponent(titles)}&thumbnails=${encodeURIComponent(thumbs)}&category=${currentCategory}`;
    window.location.href = url;
}

function exportSQL() {
    if (!currentVideos.length) return;
    const sfUrl = streamflowUrl.value.trim() || 'http://localhost:8000';
    const mode = sqlMode.value;
    const ids = currentVideos.map(v => v.id).join(',');
    const titles = currentVideos.map(v => v.title).join(',');
    const thumbs = currentVideos.map(v => v.thumbnail).join(',');
    const url = `/api/export_sql?ids=${encodeURIComponent(ids)}&titles=${encodeURIComponent(titles)}&thumbnails=${encodeURIComponent(thumbs)}&category=${currentCategory}&mode=${mode}&streamflow_url=${encodeURIComponent(sfUrl)}`;
    window.location.href = url;
}

async function loadSavedChannels() {
    try {
        const res = await fetch('/api/channels');
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro ao carregar canais');
        renderSavedChannels(data.channels || []);
    } catch (e) {
        showError(e.message);
    }
}

async function addChannel() {
    const link = channelInput.value.trim();
    if (!link) return;
    showError();
    addChannelBtn.disabled = true;
    try {
        const res = await fetch('/api/channels/add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro ao adicionar');
        channelInput.value = '';
        await loadSavedChannels();
    } catch (e) {
        showError(e.message);
    } finally {
        addChannelBtn.disabled = false;
    }
}

async function removeChannel(index) {
    if (!confirm('Remover este canal da playlist?')) return;
    try {
        const res = await fetch('/api/channels/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ index }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro ao remover');
        await loadSavedChannels();
    } catch (e) {
        showError(e.message);
    }
}

function renderSavedChannels(channels) {
    savedChannels.innerHTML = '';
    if (!channels.length) {
        savedChannels.innerHTML = '<p class="text-gray-500 text-xs">Nenhum canal ainda. Adicione um @canal acima.</p>';
        return;
    }
    channels.forEach((c, i) => {
        const row = document.createElement('div');
        row.className = 'flex items-center gap-3 bg-dark-700 border border-dark-600 rounded p-2';
        row.innerHTML = `
            <img src="${c.logo}" class="w-12 h-12 object-cover rounded" onerror="this.style.visibility='hidden'">
            <div class="flex-1 min-w-0">
                <div class="text-sm font-bold text-gray-200 truncate uppercase">${c.name}</div>
                <div class="text-[10px] text-gray-500 truncate">${c.channel_id}</div>
            </div>
            <a href="/channel_stream/${c.channel_id}" target="_blank" class="bg-dark-600 text-gray-300 px-3 py-1.5 rounded text-xs uppercase tracking-wider border border-dark-600 hover:border-accent">▶ testar</a>
            <button onclick="removeChannel(${i})" class="bg-red-900/50 text-red-400 px-3 py-1.5 rounded text-xs uppercase tracking-wider border border-red-700 hover:bg-red-900/80">remover</button>
        `;
        savedChannels.appendChild(row);
    });
}
