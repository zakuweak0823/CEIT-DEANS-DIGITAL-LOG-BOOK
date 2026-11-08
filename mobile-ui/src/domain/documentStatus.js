export const canAdvanceStatuses = ['Received', 'In Review', 'Forwarded', 'Approved', 'For Release'];

export const statusClasses = {
  'For Release': 'border border-teal-200 bg-teal-100 text-teal-800',
  Released: 'border border-green-200 bg-green-100 text-green-800',
  Received: 'border border-lime-200 bg-lime-100 text-emerald-900',
  'In Review': 'border border-yellow-300 bg-yellow-100 text-slate-800',
  Forwarded: 'border border-orange-200 bg-orange-100 text-orange-800',
  Incomplete: 'border border-rose-200 bg-rose-100 text-rose-700',
  'On Hold': 'border border-slate-300 bg-slate-100 text-slate-700',
  Approved: 'border border-sky-200 bg-sky-100 text-sky-800',
  Cancelled: 'border border-slate-300 bg-slate-100 text-slate-600',
  Pending: 'border border-yellow-300 bg-yellow-100 text-slate-800',
  Queued: 'border border-yellow-300 bg-yellow-100 text-slate-800',
  Borrowed: 'border border-yellow-300 bg-yellow-100 text-slate-800',
  'Not Returned': 'border border-rose-200 bg-rose-100 text-rose-700',
  Returned: 'border border-green-200 bg-green-100 text-green-800',
  Delivered: 'border border-green-200 bg-green-100 text-green-800',
  Sent: 'border border-green-200 bg-green-100 text-green-800',
  Failed: 'border border-rose-200 bg-rose-100 text-rose-700',
};

export const neutralBadgeClass = 'border border-slate-200 bg-slate-100 text-slate-700';
const mobilePendingStatuses = ['Received', 'In Review', 'Forwarded', 'Incomplete', 'On Hold', 'Approved'];
const mobileClosedStatuses = ['Returned', 'Cancelled'];

export const statusLabel = (status, type = 'Document') => {
  if (type === 'Borrowed') return status;
  if (mobilePendingStatuses.includes(status)) return 'Pending';
  if (mobileClosedStatuses.includes(status)) return 'Released';
  return status;
};
