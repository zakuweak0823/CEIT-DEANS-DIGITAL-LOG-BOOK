export default function DocumentStatusPanel({ counts }) {
  return (
    <section className="welcome-status-panel mx-auto mb-3 w-full max-w-5xl rounded-2xl border border-emerald-200 bg-white p-3 shadow-sm sm:mb-4 sm:p-4" aria-labelledby="document-statuses-title">
      <h2 id="document-statuses-title" className="welcome-status-heading mb-1 text-center text-sm font-bold text-emerald-950 sm:text-base">Document Status</h2>
      <div className="welcome-status-grid grid gap-2">
        {counts.map((item) => (
          <div key={item.status} data-status={item.status} className="status-tile flex min-h-14 items-center justify-between gap-2 rounded-xl px-3 py-2">
            <span className="status-tile-label min-w-0 break-words text-xs font-semibold sm:text-sm">{item.status}</span>
            <span className="status-tile-count inline-flex min-w-8 shrink-0 items-center justify-center rounded-full px-2 py-1 text-sm font-bold">
              {Number(item.count) || 0}
            </span>
          </div>
        ))}
      </div>
    </section>
  );
}
