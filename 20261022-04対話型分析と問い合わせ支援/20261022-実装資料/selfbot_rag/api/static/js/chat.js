const messagesDiv = document.getElementById('messages');
const questionInput = document.getElementById('question');
const sendButton = document.getElementById('send');
const statusDiv = document.getElementById('status');

questionInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') sendQuestion();
});

async function sendQuestion() {
    const question = questionInput.value.trim();
    if (!question) return;

    addMessage('user', question);
    questionInput.value = '';
    sendButton.disabled = true;
    statusDiv.textContent = '回答を生成中...';

    try {
        const controller = new AbortController();
        // サーバー側が 60 秒で打ち切るため、それより少し長く待つ（通信断などの保険）
        const timeoutId = setTimeout(() => controller.abort(), 65000);

        const response = await fetch('/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: question }),
            signal: controller.signal
        });
        clearTimeout(timeoutId);

        if (!response.ok) {
            const errText = await response.text();
            throw new Error(`HTTP ${response.status}: ${errText}`);
        }

        const data = await response.json();
        addMessage('bot', data.answer, data.sources);
    } catch (error) {
        if (error.name === 'AbortError') {
            addMessage('bot', '申し訳ございません。回答の生成に時間がかかり、正常に処理を完了できませんでした。お手数ですが、システム管理者にお問い合わせください。');
        } else {
            addMessage('bot', `エラーが発生しました: ${error.message}`);
        }
    } finally {
        sendButton.disabled = false;
        statusDiv.textContent = '';
        questionInput.focus();
    }
}

function toggleQa() {
    const panel = document.getElementById('qa-panel');
    const toggle = document.querySelector('.toggle');
    if (panel.classList.contains('hidden')) {
        panel.classList.remove('hidden');
        toggle.textContent = '▲ 手動 Q&A 登録を閉じる';
    } else {
        panel.classList.add('hidden');
        toggle.textContent = '▼ 手動 Q&A 登録を開く';
    }
}

async function registerQa() {
    const question = document.getElementById('qa-question').value.trim();
    const answer = document.getElementById('qa-answer').value.trim();
    const status = document.getElementById('qa-status');

    if (!question || !answer) {
        status.textContent = '質問と回答の両方を入力してください。';
        return;
    }

    const btn = document.getElementById('qa-submit');
    btn.disabled = true;
    status.textContent = '登録中...';

    try {
        const response = await fetch('/qa', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question, answer })
        });
        if (!response.ok) {
            const errText = await response.text();
            throw new Error(`HTTP ${response.status}: ${errText}`);
        }
        status.textContent = 'Q&A を登録しました。';
        document.getElementById('qa-question').value = '';
        document.getElementById('qa-answer').value = '';
    } catch (error) {
        status.textContent = `登録に失敗しました: ${error.message}`;
    } finally {
        btn.disabled = false;
    }
}

function addMessage(sender, text, sources) {
    const div = document.createElement('div');
    div.className = `message ${sender}`;
    const label = sender === 'user' ? '質問' : '回答';
    const bodyHtml = sender === 'bot' ? renderMarkdown(text) : escapeHtml(text);

    let html = `<div class="message__head"><div class="label">${label}</div></div><div>${bodyHtml}</div>`;

    if (sources && sources.length > 0) {
        const items = sources.map(s => {
            const page = s.page_label ? `（P.${s.page_label}）` : '';
            const cat = s.category ? `[${s.category}] ` : '';
            const displayText = escapeHtml(cat + s.file_name + page);
            const url = buildSourceUrl(s.resource_path, s.page_label);
            return url
                ? `<li><a href="${url}" target="_blank" rel="noopener noreferrer">${displayText}</a></li>`
                : `<li>${displayText}</li>`;
        }).join('');
        html += `<div class="sources"><strong>参照ソース：</strong><ul>${items}</ul></div>`;
    }

    div.innerHTML = html;
    messagesDiv.appendChild(div);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function renderMarkdown(text) {
    // 行頭のリストマーカー（*・-・数字.）を一時的に保護し、インライン装飾の対象外にする
    const protectedMarkers = [];
    function protectMarker(match) {
        protectedMarkers.push(match);
        return `\x00${protectedMarkers.length - 1}\x00`;
    }

    let protectedText = text;
    protectedText = protectedText.replace(/^[\s]*(?:\d+\.\s|・\s*|[\-\*]\s)/gm, protectMarker);

    // HTMLエスケープしてから、限定したマークダウン記法をHTMLに変換
    let html = escapeHtml(protectedText);
    // 太字 **text**
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // 斜体 *text*（**で囲まれていないもの）
    html = html.replace(/(^|[^*])\*([^*]+?)\*([^*]|$)/g, '$1<em>$2</em>$3');

    // 保護したマーカーを復元
    html = html.replace(/\x00(\d+)\x00/g, (m, idx) => escapeHtml(protectedMarkers[parseInt(idx)]));

    // 行単位で見出し・リスト・段落を構造化
    const lines = html.split('\n');
    const blocks = [];
    let i = 0;

    function isBlank(line) { return line.trim() === ''; }
    function isHr(line) { return /^---+\s*$/.test(line); }
    function isListItemLine(trimmed) {
        return /^\d+\.\s/.test(trimmed) || /^・\s*\S/.test(trimmed) || /^[\-\*]\s/.test(trimmed);
    }

    while (i < lines.length) {
        const line = lines[i];

        if (isBlank(line)) { i++; continue; }

        if (isHr(line)) { blocks.push('<hr>'); i++; continue; }

        if (line.startsWith('# ')) {
            blocks.push(`<h1>${line.slice(2)}</h1>`); i++; continue;
        }
        if (line.startsWith('## ')) {
            blocks.push(`<h2>${line.slice(3)}</h2>`); i++; continue;
        }
        if (line.startsWith('### ')) {
            blocks.push(`<h3>${line.slice(4)}</h3>`); i++; continue;
        }

        const trimmed = line.trimStart();
        if (isListItemLine(trimmed)) {
            const parsed = parseMarkdownList(lines, i);
            blocks.push(parsed.html);
            i = parsed.nextIndex;
            continue;
        }

        // 通常の段落
        const paras = [];
        while (i < lines.length && !isBlank(lines[i]) && !isHr(lines[i]) &&
               !/^#{1,3}\s/.test(lines[i]) && !isListItemLine(lines[i].trimStart())) {
            paras.push(lines[i]);
            i++;
        }
        if (paras.length > 0) {
            blocks.push(`<p>${paras.join('<br>')}</p>`);
        }
    }

    html = blocks.join('');

    // 許可するタグ（strong, em, br, h1-h3, ul, ol, li, hr, p）以外を除去する（defense in depth）
    html = html.replace(/<(?!\/?(?:strong|em|br|h1|h2|h3|ul|ol|li|hr|p)\b)[^>]*>/gi, '');
    return html;
}

function getMarkdownIndent(line) {
    let count = 0;
    for (const ch of line) {
        if (ch === ' ') count += 0.5;
        else if (ch === '　') count += 1;
        else break;
    }
    return count;
}

function parseMarkdownList(lines, startIndex) {
    const items = [];
    let i = startIndex;
    const baseIndent = getMarkdownIndent(lines[i]);

    function isListItemLine(trimmed) {
        return /^\d+\.\s/.test(trimmed) || /^・\s*\S/.test(trimmed) || /^[\-\*]\s/.test(trimmed);
    }

    while (i < lines.length) {
        const line = lines[i];
        if (line.trim() === '') { i++; continue; }

        const indent = getMarkdownIndent(line);
        const trimmed = line.trimStart();

        if (indent < baseIndent) break;
        if (!isListItemLine(trimmed)) break;

        const isOrdered = /^\d+\.\s/.test(trimmed);
        const marker = isOrdered ? /^\d+\.\s/ : /^(?:・\s*|[\-\*]\s)/;
        const content = trimmed.replace(marker, '');
        let itemHtml = `<li>${content}`;
        i++;

        // リスト項目に続くインデントされた説明文を収集
        const continuations = [];
        while (i < lines.length) {
            const nextLine = lines[i];
            if (nextLine.trim() === '') { i++; continue; }
            const nextIndent = getMarkdownIndent(nextLine);
            const nextTrimmed = nextLine.trimStart();
            if (nextIndent > baseIndent && !isListItemLine(nextTrimmed)) {
                continuations.push(nextTrimmed);
                i++;
            } else {
                break;
            }
        }

        // ネストされたリストを探す
        if (i < lines.length) {
            const nextLine = lines[i];
            const nextIndent = getMarkdownIndent(nextLine);
            const nextTrimmed = nextLine.trimStart();
            if (nextIndent > baseIndent && isListItemLine(nextTrimmed)) {
                const nested = parseMarkdownList(lines, i);
                itemHtml += nested.html;
                i = nested.nextIndex;
            }
        }

        if (continuations.length > 0) {
            itemHtml += '<br>' + continuations.join('<br>');
        }

        itemHtml += '</li>';
        items.push({ isOrdered, html: itemHtml });
    }

    if (items.length === 0) return { html: '', nextIndex: startIndex };

    const tag = items[0].isOrdered ? 'ol' : 'ul';
    const html = `<${tag}>${items.map(item => item.html).join('')}</${tag}>`;
    return { html, nextIndex: i };
}

function buildSourceUrl(resourcePath, pageLabel) {
    if (!resourcePath) return '';
    try {
        const url = new URL(`/resources/${encodeURI(resourcePath)}`, window.location.origin);
        // http: または https: のみ許可
        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return '';
        }
        let urlStr = url.href;
        if (resourcePath.toLowerCase().endsWith('.pdf') && pageLabel) {
            urlStr += `#page=${pageLabel}`;
        }
        return urlStr;
    } catch (e) {
        return '';
    }
}

