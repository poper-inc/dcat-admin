// Escape text before inserting it into an HTML message template.
export default function escapeHtml(value) {
    const entities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};

    return String(value).replace(/[&<>"']/g, character => entities[character]);
}
