// eccube-mcp/audit.mjs — 誰が・いつ・何を呼んだかの記録（1行1件の JSON）
//
// 置き場所は ECCUBE_MCP_AUDIT_LOG（例: /var/log/eccube-mcp/audit.jsonl）。未設定なら標準エラーに出す。
// 引数は丸ごと残す（自由SQL の中身も含めて、あとから「誰が何を引いたか」を追えるように）。
// 結果（DB から返った中身）は残さない（個人情報をログに複製しないため）。

import { appendFile, mkdir } from 'node:fs/promises';
import { dirname } from 'node:path';

const MAX_ARGS_CHARS = 4000;

export function createAudit(path, log = (...a) => console.error(...a)) {
  let ready = null;
  return async function audit(entry) {
    const line = JSON.stringify({ ts: new Date().toISOString(), ...entry }, (k, v) => {
      if (k === 'args') {
        const s = JSON.stringify(v ?? {});
        return s.length > MAX_ARGS_CHARS ? `${s.slice(0, MAX_ARGS_CHARS)}…(${s.length}文字)` : v;
      }
      return v;
    });
    if (!path) {
      log('[audit]', line);
      return;
    }
    try {
      ready ??= mkdir(dirname(path), { recursive: true });
      await ready;
      await appendFile(path, `${line}\n`, { encoding: 'utf8', mode: 0o600 });
    } catch (e) {
      // 記録に失敗しても道具は止めない（止めると誰も使えなくなる）。代わりに標準エラーに残す
      log('[audit] 書き込み失敗:', String(e?.message ?? e), line);
    }
  };
}
