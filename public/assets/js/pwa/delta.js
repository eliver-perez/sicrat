/* Apply existing messaging deltas only to pages the user has actually read. */
globalThis.SicratPwaDelta = {
    messages(body, delta) {
        if (!Array.isArray(body?.data?.rows)) return false;
        let changed = false;
        const removed = new Set((delta.message_updates || []).filter(row => row.eliminado).map(row => Number(row.id)));
        const attachments = new Map((delta.attachment_updates || []).map(row => [Number(row.id), row.attachments]));
        for (const row of body.data.rows) {
            if (removed.has(Number(row.id))) {
                row.eliminado = 1;
                row.mensaje = 'Mensaje retirado';
                row.attachments = [];
                changed = true;
            } else if (!Number(row.eliminado) && attachments.has(Number(row.id))) {
                row.attachments = attachments.get(Number(row.id));
                changed = true;
            }
        }
        return changed;
    }
};
