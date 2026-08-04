const $ = (id) => document.getElementById(id);

const addInput = $('addInput');
const addCategory = $('addCategory');
const btnAdd = $('btnAdd');
const channelsList = $('channelsList');
const countChannels = $('countChannels');
const playlistUrl = $('playlistUrl');
const btnCopy = $('btnCopy');
const btnDownload = $('btnDownload');
const searchInput = $('searchInput');
const btnSearch = $('btnSearch');
const searchResults = $('searchResults');
const videosResults = $('videosResults');
const btnReload = $('btnReload');
const toast = $('toast');

let selectedChannel = null;
let currentVideos = [];

playlistUrl.value = `${location.origin}/playlist.m3u8`;
btnDownload.href = `${location.origin}/playlist.m3u8`;

loadChannels();

/* ---------- helpers ---------- */

function toastMsg(msg, type = 'ok') {
    toast.classList.remove('hidden', 'text-green-800', 'bg-green-50', 'border-green-300', 'text-red-800', 'bg-red-50', 'border-red-300');
    if (type === 'ok') {
        toast.classList.add('text-green-800', 'bg-green-50', 'border-green-300');
    } else {
        toast.classList.add('text-red-800', 'bg-red-50', 'border-red-300');
    }
    toast.textContent = msg;
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 3500);
}

function spinner() {
    return `<div class="flex justify-center py-8"><div class="spinner"></div></div>`;
}

/* ---------- canais salvos ---------- */

async function loadChannels() {
    try {
        const res = await fetch('/api/channels');
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro ao carregar canais');
        renderChannels(data.channels || []);
    } catch (e) {
        toastMsg(e.message, 'err');
    }
}

function renderChannels(channels) {
    countChannels.textContent = `(${channels.length})`;
    if (!channels.length) {
        channelsList.innerHTML = `<div class="text-center py-10 text-gray-400 text-sm">Nenhum canal ainda. Adicione um acima — demora 1 segundo.</div>`;
        return;
    }
    channelsList.innerHTML = channels.map((c, i) => `
        <div class="flex items-center gap-4 border border-gray-200 rounded-xl p-3 fade-in" id="ch-${i}">
            <img src="${c.logo}" alt="" class="w-14 h-14 rounded-lg object-cover bg-gray-100" onerror="this.style.visibility='hidden'">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-semibold text-gray-900 truncate">${escapeHtml(c.name)}</span>
                    <span id="status-${i}" class="badge badge-video"><span class="badge-dot"></span>...</span>
                </div>
                <div class="text-xs text-gray-400 font-mono truncate">${c.channel_id}</div>
            </div>
            <a class="btn btn-outline btn-sm" href="/channel_stream/${c.channel_id}" target="_blank">▶ Testar</a>
            <button class="btn btn-danger btn-sm" onclick="removeChannel(${i})">Remover</button>
        </div>
    `).join('');
    // busca o status (live ou vídeo) de cada canal
    channels.forEach((c, i) => fetchStatus(c.channel_id, i));
}

async function fetchStatus(channelId, i) {
    try {
        const res = await fetch(`/api/channel_status?channel_id=${encodeURIComponent(channelId)}`);
        const data = await res.json();
        const el = document.getElementById(`status-${i}`);
        if (!el) return;
        if (data.is_live) {
            el.className = 'badge badge-live';
            el.innerHTML = `<span class="badge-dot"></span>Ao vivo`;
        } else {
            el.className = 'badge badge-video';
            el.innerHTML = `<span class="badge-dot"></span>Vídeo`;
        }
    } catch (e) { /* ignora */ }
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

async function addChannel() {
    const link = addInput.value.trim();
    if (!link) { toastMsg('Cole um @canal, link ou ID.', 'err'); return; }
    btnAdd.disabled = true;
    btnAdd.textContent = '...';
    try {
        const res = await fetch('/api/channels/add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link, category: addCategory.value }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro ao adicionar');
        addInput.value = '';
        toastMsg(`"${data.channel.name}" adicionado!`);
        await loadChannels();
    } catch (e) {
        toastMsg(e.message, 'err');
    } finally {
        btnAdd.disabled = false;
        btnAdd.textContent = '+ Adicionar';
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
        toastMsg('Canal removido.');
        await loadChannels();
    } catch (e) {
        toastMsg(e.message, 'err');
    }
}

/* ---------- explorar ---------- */

async function searchChannels() {
    const q = searchInput.value.trim();
    if (!q) { toastMsg('Digite algo para buscar.', 'err'); return; }
    videosResults.innerHTML = '';
    searchResults.innerHTML = spinner();
    try {
        const res = await fetch(`/api/search_channels?q=${encodeURIComponent(q)}&max_results=12`);
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro na busca');
        renderSearchChannels(data.channels || []);
    } catch (e) {
        searchResults.innerHTML = `<div class="text-center py-8 text-red-500 text-sm">${escapeHtml(e.message)}</div>`;
    }
}

function renderSearchChannels(channels) {
    if (!channels.length) {
        searchResults.innerHTML = `<div class="text-center py-8 text-gray-400 text-sm">Nenhum canal encontrado.</div>`;
        return;
    }
    searchResults.innerHTML = `
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
            ${channels.map(ch => `
                <div class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-brand-500 hover:shadow-sm transition" onclick="loadVideos('${ch.id}')">
                    <img src="${ch.thumbnail}" alt="" class="w-full aspect-video object-cover rounded-lg bg-gray-100 mb-2"
                        onerror="this.style.visibility='hidden'">
                    <div class="font-semibold text-gray-900 text-sm truncate">${escapeHtml(ch.title)}</div>
                    <div class="text-xs text-gray-400 mt-0.5 flex items-center justify-between">
                        <span class="truncate">${escapeHtml(ch.description || '') || '—'}</span>
                        <span class="text-brand-600 shrink-0 ml-2">ver vídeos →</span>
                    </div>
                </div>
            `).join('')}
        </div>`;
}

async function loadVideos(channelId) {
    selectedChannel = channelId;
    videosResults.innerHTML = spinner();
    try {
        const res = await fetch(`/api/get_videos?channel_id=${channelId}&max_results=24`);
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro ao carregar vídeos');
        currentVideos = data.videos || [];
        renderVideos();
    } catch (e) {
        videosResults.innerHTML = `<div class="text-center py-8 text-red-500 text-sm">${escapeHtml(e.message)}</div>`;
    }
}

function renderVideos() {
    if (!currentVideos.length) {
        videosResults.innerHTML = `<div class="text-center py-8 text-gray-400 text-sm">Nenhum vídeo encontrado.</div>`;
        return;
    }
    const thumbs = currentVideos.map(v => v.thumbnail).join(',');
    const titles = currentVideos.map(v => v.title).join(',');
    const ids = currentVideos.map(v => v.id).join(',');
    videosResults.innerHTML = `
        <div class="flex items-center justify-between flex-wrap gap-2 mb-3 border-t border-gray-100 pt-4">
            <span class="font-semibold text-gray-800 text-sm">Vídeos do canal (${currentVideos.length})</span>
            <a class="btn btn-dark btn-sm" href="/api/export_m3u?ids=${encodeURIComponent(ids)}&titles=${encodeURIComponent(titles)}&thumbnails=${encodeURIComponent(thumbs)}&category=${addCategory.value}">⬇ Exportar .m3u deste canal</a>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
            ${currentVideos.map((v, i) => `
                <div class="border border-gray-200 rounded-xl p-2">
                    <img src="${v.thumbnail}" alt="" class="w-full aspect-video object-cover rounded-lg bg-gray-100"
                        onerror="this.style.visibility='hidden'">
                    <div class="font-medium text-gray-900 text-sm truncate mt-2">${escapeHtml(v.title)}</div>
                    <div class="text-xs text-gray-400 mb-2">${new Date(v.published_at).toLocaleDateString('pt-BR')}</div>
                    <div class="flex gap-2">
                        <a class="btn btn-outline btn-sm flex-1" href="/stream/${v.id}" target="_blank">▶ Testar</a>
                        <button class="btn btn-primary btn-sm flex-1" onclick="addVideo('${v.id}', ${i})">+ Lista</button>
                    </div>
                </div>
            `).join('')}
        </div>`;
}

async function addVideo(videoId, index) {
    const v = currentVideos[index];
    if (!v) return;
    try {
        const res = await fetch('/api/channels/add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link: videoId, name: v.title, category: addCategory.value }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.detail || 'Erro ao adicionar');
        toastMsg(`Vídeo adicionado à playlist!`);
        await loadChannels();
    } catch (e) {
        toastMsg(e.message, 'err');
    }
}

/* ---------- eventos ---------- */

btnAdd.addEventListener('click', addChannel);
addInput.addEventListener('keydown', e => { if (e.key === 'Enter') addChannel(); });
btnSearch.addEventListener('click', searchChannels);
searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') searchChannels(); });
btnReload.addEventListener('click', () => { loadChannels(); toastMsg('Lista atualizada.'); });
btnCopy.addEventListener('click', () => {
    playlistUrl.select();
    document.execCommand('copy');
    toastMsg('URL da playlist copiada!');
});
