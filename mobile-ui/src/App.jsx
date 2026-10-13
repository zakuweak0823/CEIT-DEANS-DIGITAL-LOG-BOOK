import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import officeLogoUrl from './office-logo.png';
import confirmToneUrl from './confirm-tone.wav';
import swipeToneUrl from './swipe.mp3';
import {
  BiArchiveIn,
  BiArchiveOut,
  BiBox,
  BiFile,
  BiChevronLeft,
  BiLayer,
  BiCheckCircle,
} from 'react-icons/bi';

const sections = [
  { key: 'incoming', label: 'Submit Documents', icon: BiArchiveIn, exampleLabel: 'Submit Document' },
  { key: 'outgoing', label: 'Released Documents', icon: BiArchiveOut, exampleLabel: 'Released Documents' },
  { key: 'batches', label: 'Bulk Papers', icon: BiLayer, exampleLabel: 'Register Bulk Papers' },
  { key: 'borrowed', label: 'Borrowed Items', icon: BiBox, exampleLabel: 'Borrow Item' },
  { key: 'reports', label: 'Reports', icon: BiFile, exampleLabel: 'Generate Report' },
];

const API_ENDPOINT = import.meta.env.DEV
  ? '/api/mobile-api.php'
  : import.meta.env.VITE_API_ENDPOINT || 'http://10.0.2.2/DIT-LOGBOOK_RETRY/mobile-api.php';

const playConfirmTone = () => {
  const audio = new Audio(confirmToneUrl);
  audio.volume = 0.18;
  audio.play().catch((error) => {
    console.warn('Submission confirmation tone could not be played:', error);
  });
};

const playSwipeTone = () => {
  const audio = new Audio(swipeToneUrl);
  audio.volume = 0.1;
  audio.play().catch((error) => {
    console.warn('Pull-to-refresh tone could not be played:', error);
  });
};

const statusClasses = {
  Received: 'bg-emerald-100 text-emerald-800',
  'For Release': 'bg-amber-100 text-amber-800',
  Released: 'bg-emerald-100 text-emerald-800',
  Pending: 'bg-amber-100 text-amber-700',
  'Not Returned': 'bg-rose-100 text-rose-700',
  Returned: 'bg-emerald-100 text-emerald-800',
  Delivered: 'bg-lime-100 text-lime-700',
  Failed: 'bg-rose-100 text-rose-700',
};

const inputClass = 'w-full rounded-3xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100';
const buttonClass = 'inline-flex min-h-12 items-center justify-center rounded-2xl bg-emerald-900 px-5 py-3.5 text-base font-semibold text-white transition hover:bg-emerald-700 active:scale-[0.99]';
const todayLocalDate = () => {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};
const isValidContactNumber = (value) => /^[0-9]{11}$/.test(value);
const contactNumberDigits = (value) => value.replace(/\D/g, '').slice(0, 11);
const readApiResponse = async (response) => {
  try {
    return await response.json();
  } catch {
    throw new Error('The office server returned an unreadable response. If you submitted a change, check the records before retrying.');
  }
};

function App() {
  const [selectedSection, setSelectedSection] = useState('home');
  const [logbookPopupOpen, setLogbookPopupOpen] = useState(false);
  const [isModalClosing, setIsModalClosing] = useState(false);
  const modalCloseTimeoutRef = useRef(null);
  const modalClosingRef = useRef(false);
  const [activeForm, setActiveForm] = useState(null);
  const [incomingModalMode, setIncomingModalMode] = useState(null);
  const [recordModal, setRecordModal] = useState(null);
  const [submissionSuccess, setSubmissionSuccess] = useState(null);
  const [actionFeedback, setActionFeedback] = useState(null);
  const [incomingSubmitting, setIncomingSubmitting] = useState(false);
  const isBatchOrBorrowModal = Boolean(recordModal);
  const isFormModalOpen = Boolean(incomingModalMode) || isBatchOrBorrowModal;
  const [apiError, setApiError] = useState('');
  const [isOnline, setIsOnline] = useState(() => navigator.onLine);
  const [reloadCount, setReloadCount] = useState(0);
  const [reportLoading, setReportLoading] = useState(false);
  const [clientStatusOpen, setClientStatusOpen] = useState(false);
  const [clientStatusPhone, setClientStatusPhone] = useState('');
  const [clientStatusItems, setClientStatusItems] = useState([]);
  const [clientStatusSearched, setClientStatusSearched] = useState(false);
  const [clientStatusLoading, setClientStatusLoading] = useState(false);
  const [clientStatusError, setClientStatusError] = useState('');
  const isAnyModalOpen = isFormModalOpen || clientStatusOpen || logbookPopupOpen || Boolean(submissionSuccess) || Boolean(actionFeedback);
  const [releaseDocumentId, setReleaseDocumentId] = useState(null);
  const [releaseBusy, setReleaseBusy] = useState(false);
  const [smsBusyId, setSmsBusyId] = useState(null);

  const closePopup = useCallback((onClose) => {
    if (modalClosingRef.current) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      onClose();
      return;
    }

    modalClosingRef.current = true;
    setIsModalClosing(true);
    modalCloseTimeoutRef.current = window.setTimeout(() => {
      onClose();
      modalClosingRef.current = false;
      modalCloseTimeoutRef.current = null;
      setIsModalClosing(false);
    }, 180);
  }, []);

  const [apiData, setApiData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [refreshError, setRefreshError] = useState('');
  const refreshInProgressRef = useRef(false);
  const hasLoadedDataRef = useRef(false);
  const refreshRequestRef = useRef(null);
  const pullGestureRef = useRef(null);
  const [pullDistance, setPullDistance] = useState(0);
  const [pullRefreshing, setPullRefreshing] = useState(false);

  const [incomingItems, setIncomingItems] = useState([]);
  const [outgoingItems, setOutgoingItems] = useState([]);
  const [borrowedItems, setBorrowedItems] = useState([]);
  const [batchItems, setBatchItems] = useState([]);
  const [reportItems, setReportItems] = useState([]);

  const [incomingForm, setIncomingForm] = useState({ date: '', from: 'Student', subject: '', recipient: '', clientPhone: '' });
  const [borrowedForm, setBorrowedForm] = useState({ item: '', borrower: '', phone: '' });
  const [borrowedBusyId, setBorrowedBusyId] = useState(null);
  const [borrowedSubmitting, setBorrowedSubmitting] = useState(false);
  const [batchSubmitting, setBatchSubmitting] = useState(false);
  const isFormSubmitting = incomingSubmitting || borrowedSubmitting || batchSubmitting;
  const [batchForm, setBatchForm] = useState({ type: '', documentType: 'Incoming', date: todayLocalDate(), senderType: 'Student', recipientType: 'Unassigned', recipient: '', paperCount: '1', remarks: '' });
  const [editingBatchId, setEditingBatchId] = useState(null);
  const [batchStatusBusyId, setBatchStatusBusyId] = useState(null);
  const [reportForm, setReportForm] = useState({ type: 'Incoming', from: '', to: '' });
  const [reportFilters, setReportFilters] = useState(reportForm);

  const showActionFeedback = (feedback) => setActionFeedback(feedback);

  const incrementDocumentStatusCount = (status, amount = 1) => {
    setApiData((current) => {
      if (!current) return current;
      const currentStatuses = current.documentStatuses || [];
      const existing = currentStatuses.find((item) => item.status === status);
      return {
        ...current,
        documentStatuses: [
          ...currentStatuses.filter((item) => item.status !== status),
          { status, count: Number(existing?.count || 0) + amount },
        ].sort((left, right) => left.status.localeCompare(right.status)),
      };
    });
  };

  const documentStatusCounts = useMemo(() => {
    const counts = new Map((apiData?.documentStatuses || []).map((item) => [item.status, Number(item.count) || 0]));
    if (!counts.has('Pending')) counts.set('Pending', 0);
    return [...counts.entries()]
      .map(([status, count]) => ({ status, count }))
      .sort((left, right) => {
        if (left.status === 'Pending') return -1;
        if (right.status === 'Pending') return 1;
        return left.status.localeCompare(right.status);
      });
  }, [apiData?.documentStatuses]);

  const apiFetch = async (options = {}) => {
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), 20000);
    const method = String(options.method || 'GET').toUpperCase();
    const isWriteRequest = method !== 'GET' && method !== 'HEAD';
    try {
      return await fetch(API_ENDPOINT, {
        ...options,
        signal: controller.signal,
        headers: {
          ...(options.headers || {}),
        },
      });
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        // A timed-out write may still have reached the server, so warn against blind retries.
        throw new Error(isWriteRequest
          ? 'The request timed out. The server may have received the change; check the records before retrying.'
          : 'The request timed out. Check your connection and try again.');
      }
      if (error instanceof TypeError) {
        const connectionMessage = navigator.onLine
          ? 'Could not reach the office server. Check your connection.'
          : 'You are offline. Reconnect to the internet.';
        throw new Error(isWriteRequest
          ? `${connectionMessage} If you submitted a change, check the records before retrying.`
          : `${connectionMessage} Then try again.`);
      }
      throw error;
    } finally {
      window.clearTimeout(timeoutId);
    }
  };

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  useEffect(() => {
    let isCurrent = true;
    const fetchData = async (manualRefresh = false) => {
      if (refreshInProgressRef.current) {
        if (manualRefresh) setPullRefreshing(false);
        return;
      }
      if (!navigator.onLine) {
        if (!hasLoadedDataRef.current) {
          setApiError('You are offline. Reconnect to the internet to load the office logbook.');
          setLoading(false);
        }
        if (manualRefresh) setPullRefreshing(false);
        return;
      }
      refreshInProgressRef.current = true;
      if (manualRefresh) setPullRefreshing(true);
      const refreshStartedAt = manualRefresh ? Date.now() : 0;
      const isInitialLoad = !hasLoadedDataRef.current;
      if (isInitialLoad) setLoading(true);

      try {
        const response = await apiFetch({ cache: 'no-store' });
        const data = await readApiResponse(response);
        if (!response.ok) throw new Error(data.message || `HTTP error! status: ${response.status}`);
        if (!isCurrent) return;
        setApiError('');
        setRefreshError('');
        setApiData(data);
        hasLoadedDataRef.current = true;
        // Map to local state
        setIncomingItems(data.recentDocuments.filter(d => d.type === 'Incoming').map(d => ({
          id: d.id,
          date: d.date,
          from: d.fromTo.split(' / ')[0],
          submitterType: d.submitterType || 'Other',
          isPriority: Number(d.isPriority) === 1,
          subject: d.details,
          recipient: d.fromTo.split(' / ')[1],
          clientPhone: d.clientPhone || '',
          status: d.status,
        })));
        setOutgoingItems(data.recentDocuments.filter(d => d.type === 'Outgoing').map(d => ({
          id: d.id,
          date: d.date,
          to: d.fromTo.split(' / ')[1],
          subject: d.details,
          clientPhone: d.clientPhone || '',
          status: d.status,
          remarks: d.remarks || '',
        })));
        setBorrowedItems(data.borrowedItems.map(b => ({
          id: b.id,
          item: b.item,
          borrower: b.borrower,
          borrowed: b.borrowed,
          returned: b.returned || '',
          status: b.status,
        })));
        setBatchItems(data.recentBatches || []);
        setReportItems([...data.recentDocuments.map(d => ({
          date: d.date,
          type: d.type,
          from: d.fromTo.split(' / ')[0],
          subject: d.details,
          status: d.status,
        })), ...data.borrowedItems.map(b => ({
          id: b.id,
          date: b.borrowed,
          type: 'Borrowed',
          from: b.borrower,
          subject: b.item,
          status: b.status,
        }))]);
      } catch (error) {
        console.error('Failed to fetch data:', error);
        if (isCurrent) {
          const message = error instanceof Error ? error.message : 'Could not load the office logbook.';
          if (hasLoadedDataRef.current) {
            setRefreshError(message);
          } else {
            setApiError(message);
          }
        }
      } finally {
        if (manualRefresh) {
          const remainingIndicatorTime = 2500 - (Date.now() - refreshStartedAt);
          if (remainingIndicatorTime > 0) {
            await new Promise((resolve) => window.setTimeout(resolve, remainingIndicatorTime));
          }
        }
        refreshInProgressRef.current = false;
        if (isCurrent) {
          setLoading(false);
          setPullRefreshing(false);
        }
      }
    };
    const requestRefresh = () => fetchData(true);
    refreshRequestRef.current = requestRefresh;
    const refreshWhenVisible = () => {
      if (!document.hidden && navigator.onLine) fetchData();
    };
    const refreshInterval = window.setInterval(refreshWhenVisible, 3000);
    document.addEventListener('visibilitychange', refreshWhenVisible);
    window.addEventListener('online', refreshWhenVisible);
    fetchData();
    return () => {
      isCurrent = false;
      if (refreshRequestRef.current === requestRefresh) refreshRequestRef.current = null;
      window.clearInterval(refreshInterval);
      document.removeEventListener('visibilitychange', refreshWhenVisible);
      window.removeEventListener('online', refreshWhenVisible);
    };
  }, [reloadCount]);

  const selectedSectionData = useMemo(() => sections.find((section) => section.key === selectedSection) ?? null, [selectedSection]);

  useEffect(() => {
    if (!isAnyModalOpen) return undefined;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const handleKeyDown = (event) => {
      if (event.key === 'Escape' && !isFormSubmitting) {
        closePopup(() => {
          setIncomingModalMode(null);
          setRecordModal(null);
          setActiveForm(null);
          setClientStatusOpen(false);
          setLogbookPopupOpen(false);
          setSubmissionSuccess(null);
          setActionFeedback(null);
        });
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [closePopup, isAnyModalOpen, isFormSubmitting]);

  useEffect(() => () => {
    if (modalCloseTimeoutRef.current !== null) {
      window.clearTimeout(modalCloseTimeoutRef.current);
    }
  }, []);

  const openClientStatusModal = () => {
    setClientStatusOpen(true);
    setClientStatusError('');
    setClientStatusItems([]);
    setClientStatusSearched(false);
  };

  const launchLogbookAction = (action) => {
    closePopup(() => {
      setLogbookPopupOpen(false);
      if (action === 'submit' || action === 'quick') {
        openIncomingModal(action === 'quick' ? 'quick' : 'standard');
        return;
      }
      if (action === 'batch') {
        openRecordModal('batches');
        return;
      }
      if (action === 'borrow') {
        openRecordModal('borrowed');
        return;
      }
      if (action === 'status') {
        openClientStatusModal();
      }
    });
  };

  const openRecordModal = (formType) => {
    if (formType === 'batches') {
      setEditingBatchId(null);
      setBatchForm({ type: '', documentType: 'Incoming', date: todayLocalDate(), senderType: 'Student', recipientType: 'Unassigned', recipient: '', paperCount: '1', remarks: '' });
    }
    setRecordModal(formType);
  };

  // Write actions run directly on submit/tap; busy state prevents duplicate requests and feedback reports the outcome.
  const handleSubmitIncoming = async (event) => {
    event.preventDefault();
    if (incomingSubmitting) return;
    if (incomingModalMode === 'standard' && incomingForm.clientPhone !== '' && !isValidContactNumber(incomingForm.clientPhone)) {
      showActionFeedback({ title: 'Check the contact number', description: 'Enter exactly 11 digits using numbers only, or leave it blank.', tone: 'warning' });
      return;
    }
    setIncomingSubmitting(true);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: incomingModalMode === 'quick' ? 'quick_submit_incoming' : 'add_incoming',
          date: incomingForm.date,
          sender: incomingForm.from,
          submitter_type: incomingForm.from,
          recipient: incomingForm.recipient,
          client_phone: incomingModalMode === 'standard' ? incomingForm.clientPhone : '',
          subject: incomingForm.subject,
          remarks: '',
        }),
      });
      
      const result = await readApiResponse(response);
      if (!response.ok) {
        throw new Error(result.message || `HTTP error! status: ${response.status}`);
      }
      if (result.success) {
        playConfirmTone();
        const clientPhone = incomingModalMode === 'standard' ? incomingForm.clientPhone : '';
        const incomingStatus = 'Pending';
        setIncomingItems((prev) => [{ id: result.id, date: incomingForm.date, from: incomingForm.from, subject: incomingForm.subject, recipient: incomingForm.recipient, clientPhone, status: incomingStatus }, ...prev]);
        setReportItems((prev) => [
          { id: result.id, date: incomingForm.date, type: 'Incoming', from: incomingForm.from, subject: incomingForm.subject, status: incomingStatus },
          ...prev,
        ]);
        incrementDocumentStatusCount(incomingStatus);
        setIncomingForm({ date: incomingForm.date, from: 'Student', subject: '', recipient: '', clientPhone: '' });
        closePopup(() => {
          setActiveForm(null);
          setIncomingModalMode(null);
          setSubmissionSuccess({
            title: incomingModalMode === 'quick' ? 'Quick submission received' : 'Document submitted',
            description: 'Your document is now recorded and awaiting office review.',
            details: [
              { label: 'Document type', value: incomingForm.subject },
              { label: 'Recipient', value: incomingForm.recipient },
              { label: 'Status', value: result.status || incomingStatus },
            ],
            reference: result.id ? `Reference #${result.id}` : '',
          });
        });
      } else {
        showActionFeedback({ title: 'Document was not submitted', description: result.message || 'The office server could not save this document.', tone: 'danger' });
      }
    } catch (error) {
      console.error('Incoming submission error:', error);
      showActionFeedback({ title: 'Document submission problem', description: error.message, tone: 'danger' });
    } finally {
      setIncomingSubmitting(false);
    }
  };

  const handlePrepareIncoming = async (item) => {
    if (!item.id) {
      showActionFeedback({ title: 'Cannot prepare this document', description: 'This record cannot be prepared from mobile. Reload the logbook and try again.', tone: 'warning' });
      return;
    }
    setReleaseDocumentId(item.id);
    setReleaseBusy(true);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'prepare_incoming', doc_id: item.id }),
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) throw new Error(result.message || 'Document could not be prepared');

      setIncomingItems((items) => items.filter((record) => record.id !== item.id));
      setOutgoingItems((items) => [{ id: item.id, date: item.date, to: result.recipient, subject: item.subject, clientPhone: item.clientPhone, status: 'For Release' }, ...items]);
      incrementDocumentStatusCount('Pending', -1);
      incrementDocumentStatusCount('For Release');
      setReleaseDocumentId(null);
      showActionFeedback({ title: 'Document prepared for release', description: 'The document was moved to Outgoing Documents and is now marked For Release.', tone: 'success' });
    } catch (error) {
      setReleaseDocumentId(null);
      showActionFeedback({ title: 'Could not prepare document', description: error.message, tone: 'danger' });
    } finally {
      setReleaseBusy(false);
    }
  };

  const handleSetOutgoingStatus = async (item, status) => {
    if (!item.id) return;
    setReleaseDocumentId(item.id);
    setReleaseBusy(true);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_outgoing_status', doc_id: item.id, status }),
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) throw new Error(result.message || 'Document status could not be changed');
      setOutgoingItems((items) => items.map((record) => record.id === item.id ? { ...record, status } : record));
      incrementDocumentStatusCount(item.status, -1);
      incrementDocumentStatusCount(status);
      showActionFeedback({
        title: 'Document status updated',
        description: `The document is now marked ${status}.`,
        tone: 'success',
      });
    } catch (error) {
      showActionFeedback({ title: 'Could not update document status', description: error.message, tone: 'danger' });
    } finally {
      setReleaseDocumentId(null);
      setReleaseBusy(false);
    }
  };

  const handleNotifyClient = async (item) => {
    if (!item.id || smsBusyId !== null) return;
    setSmsBusyId(item.id);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'notify_client', doc_id: item.id }),
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) throw new Error(result.message || 'SMS could not be sent');
      const smsStatus = String(result.sms?.status || '').toLowerCase();
      showActionFeedback({
        title: ['pending', 'queued', 'sent'].includes(smsStatus) ? 'SMS accepted — delivery pending' : 'SMS notification sent',
        description: result.sms?.message || 'The SMS request was accepted.',
        tone: ['pending', 'queued', 'sent'].includes(smsStatus) ? 'warning' : 'success',
      });
    } catch (error) {
      showActionFeedback({ title: 'SMS could not be sent', description: error.message, tone: 'danger' });
    } finally {
      setSmsBusyId(null);
    }
  };

  const handleClientStatusLookup = async (event) => {
    event.preventDefault();
    if (!isValidContactNumber(clientStatusPhone)) {
      setClientStatusError('Enter the same 11-digit contact number given to the office.');
      setClientStatusSearched(false);
      return;
    }
    setClientStatusLoading(true);
    setClientStatusError('');
    setClientStatusSearched(false);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'client_status_lookup', client_phone: clientStatusPhone.trim() }),
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) throw new Error(result.message || 'Status check failed');
      setClientStatusItems(result.records);
      setClientStatusSearched(true);
    } catch (error) {
      setClientStatusError(error.message);
    } finally {
      setClientStatusLoading(false);
    }
  };

  const handleSubmitBorrowed = async (event) => {
    event.preventDefault();
    if (borrowedSubmitting) return;
    if (borrowedForm.phone !== '' && !isValidContactNumber(borrowedForm.phone)) {
      showActionFeedback({ title: 'Check the borrower contact number', description: 'Enter exactly 11 digits using numbers only, or leave it blank.', tone: 'warning' });
      return;
    }
    setBorrowedSubmitting(true);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'add_borrowed',
          item: borrowedForm.item,
          borrower: borrowedForm.borrower,
          borrower_phone: borrowedForm.phone,
          borrowed_date: todayLocalDate(),
        }),
      });
      
      const result = await readApiResponse(response);
      if (!response.ok) {
        throw new Error(result.message || `HTTP error! status: ${response.status}`);
      }
      if (result.success) {
        playConfirmTone();
        const borrowedStatus = result.status || 'Borrowed';
        setBorrowedItems((prev) => [{ id: result.id, ...borrowedForm, borrowed: todayLocalDate(), returned: '', status: borrowedStatus }, ...prev]);
        setReportItems((prev) => [
          { id: result.id, date: todayLocalDate(), type: 'Borrowed', from: borrowedForm.borrower, subject: borrowedForm.item, status: borrowedStatus },
          ...prev,
        ]);
        setBorrowedForm({ item: '', borrower: '', phone: '' });
        closePopup(() => {
          setActiveForm(null);
          setRecordModal(null);
          setSubmissionSuccess({
            title: 'Borrowed item recorded',
            description: 'The borrowed item has been added to the office records.',
            details: [
              { label: 'Item', value: borrowedForm.item },
              { label: 'Borrower', value: borrowedForm.borrower },
              { label: 'Status', value: borrowedStatus },
            ],
            reference: result.id ? `Record #${result.id}` : '',
          });
        });
      } else {
        showActionFeedback({ title: 'Borrowed item was not recorded', description: result.message || 'The office server could not save this item.', tone: 'danger' });
      }
    } catch (error) {
      console.error('Borrowed submission error:', error);
      showActionFeedback({ title: 'Borrowed item submission problem', description: error.message, tone: 'danger' });
    } finally {
      setBorrowedSubmitting(false);
    }
  };

  const handleMarkBorrowedReturned = async (item) => {
    if (!item.id || borrowedBusyId !== null) return;
    setBorrowedBusyId(item.id);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_borrowed_returned', item_id: item.id }),
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) {
        throw new Error(result.message || `HTTP error! status: ${response.status}`);
      }
      const returned = result.returned || todayLocalDate();
      setBorrowedItems((items) => items.map((current) => current.id === item.id
        ? { ...current, returned, status: 'Returned' }
        : current));
      setReportItems((items) => items.map((current) => (
        current.type === 'Borrowed' && current.id === item.id && current.status !== 'Returned'
          ? { ...current, status: 'Returned' }
          : current
      )));
      showActionFeedback({ title: 'Item marked as returned', description: `${item.item} is now recorded as Returned.`, tone: 'success' });
    } catch (error) {
      console.error('Borrowed item return update failed:', error);
      showActionFeedback({ title: 'Could not update borrowed item', description: error.message || 'Could not update the borrowed item.', tone: 'danger' });
    } finally {
      setBorrowedBusyId(null);
    }
  };

  const handleSetBatchStatus = async (batch) => {
    if (!batch.id || batchStatusBusyId !== null || !isOnline) return;
    setBatchStatusBusyId(batch.id);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_batch_status', batch_id: batch.id }),
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) throw new Error(result.message || 'Batch status could not be changed');
      setBatchItems((items) => items.map((item) => item.id === batch.id ? { ...item, status: result.status } : item));
      setReloadCount((count) => count + 1);
      showActionFeedback({
        title: 'Batch status updated',
        description: `${batch.batchCode} and its linked papers are now ${result.status}.`,
        tone: 'success',
      });
    } catch (error) {
      showActionFeedback({ title: 'Could not update batch status', description: error.message, tone: 'danger' });
    } finally {
      setBatchStatusBusyId(null);
    }
  };

  const handleSubmitBatch = async (event) => {
    event.preventDefault();
    if (batchSubmitting) return;
    const batchType = batchForm.type.trim();
    if (!batchType || batchType.length > 255) {
      showActionFeedback({ title: 'Check the batch type', description: 'Enter a batch or document name of up to 255 characters.', tone: 'warning' });
      return;
    }
    const paperCount = Number(batchForm.paperCount);
    const recipient = batchForm.recipientType === 'Unassigned' ? '' : batchForm.recipient.trim();
    if (!Number.isInteger(paperCount) || paperCount < 1 || paperCount > 9999) {
      showActionFeedback({ title: 'Check the paper count', description: 'Enter a paper count between 1 and 9,999.', tone: 'warning' });
      return;
    }
    if (batchForm.recipientType === 'Email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(recipient)) {
      showActionFeedback({ title: 'Check the recipient email', description: 'Enter a valid recipient email address.', tone: 'warning' });
      return;
    }
    if (batchForm.recipientType === 'Contact' && !isValidContactNumber(recipient)) {
      showActionFeedback({ title: 'Check the recipient contact', description: 'Enter exactly 11 digits using numbers only.', tone: 'warning' });
      return;
    }

    setBatchSubmitting(true);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: editingBatchId ? 'update_batch' : 'add_batch',
          batch_id: editingBatchId,
          document_type: batchForm.documentType,
          batch_type: batchType,
          batch_date: batchForm.date,
          sender_type: batchForm.senderType,
          recipient_contact_type: batchForm.recipientType,
          recipient,
          remarks: batchForm.remarks,
          paper_count: paperCount,
        }),
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) throw new Error(result.message || `HTTP error! status: ${response.status}`);

      let successNotice;
      if (editingBatchId) {
        setBatchItems((items) => items.map((batch) => batch.id === editingBatchId
          ? { ...batch, documentType: batchForm.documentType, batchType, batchDate: batchForm.date, senderType: batchForm.senderType, recipientContactType: batchForm.recipientType, recipient, paperCount, remarks: batchForm.remarks }
          : batch));
        successNotice = {
          title: 'Bulk paper details updated',
          description: 'Your changes have been saved to the office records.',
          details: [
            { label: 'Batch type', value: batchType },
            { label: 'Paper count', value: String(paperCount) },
          ],
        };
      } else {
        const savedBatch = result.batch;
        playConfirmTone();
        setBatchItems((previous) => [savedBatch, ...previous]);
        successNotice = {
          title: 'Bulk papers submitted',
          description: 'The batch has been recorded and is ready for office tracking.',
          details: [
            { label: 'Batch type', value: savedBatch.batchType },
            { label: 'Status', value: savedBatch.status },
            { label: 'Paper count', value: String(savedBatch.paperCount) },
          ],
          reference: savedBatch.batchCode ? `Batch ${savedBatch.batchCode}` : '',
        };
      }
      closePopup(() => {
        setBatchForm({ type: '', documentType: 'Incoming', date: todayLocalDate(), senderType: 'Student', recipientType: 'Unassigned', recipient: '', paperCount: '1', remarks: '' });
        setEditingBatchId(null);
        setActiveForm(null);
        setRecordModal(null);
        setSelectedSection('home');
        setSubmissionSuccess(successNotice);
      });
    } catch (error) {
      showActionFeedback({ title: `Bulk papers ${editingBatchId ? 'were not updated' : 'were not saved'}`, description: error.message, tone: 'danger' });
    } finally {
      setBatchSubmitting(false);
    }
  };

  const handleGenerateReport = async (event) => {
    event.preventDefault();
    setReportFilters(reportForm);
    setReportLoading(true);
    try {
      const response = await apiFetch({
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_report', report_type: reportForm.type, from: reportForm.from, to: reportForm.to }),
      });
      const result = await readApiResponse(response);
      if (!response.ok || !result.success) throw new Error(result.message || 'Report could not be generated');
      setReportItems(result.records.map((row) => ({ ...row, from: '' })));
    } catch (error) {
      showActionFeedback({ title: 'Report could not be generated', description: error.message, tone: 'danger' });
    } finally {
      setReportLoading(false);
    }
  };

  const reportResult = reportItems.filter((item) => {
    const matchesType = reportFilters.type === 'All' || item.type === reportFilters.type;
    const matchesFrom = reportFilters.from ? item.date >= reportFilters.from : true;
    const matchesTo = reportFilters.to ? item.date <= reportFilters.to : true;
    return matchesType && matchesFrom && matchesTo;
  });

  const openIncomingModal = (mode) => {
    setIncomingForm((form) => ({ ...form, date: form.date || todayLocalDate() }));
    setIncomingModalMode(mode);
  };

  const renderForm = (formType = activeForm) => {
    if (formType === 'standard' || formType === 'quick') {
      return (
        <form onSubmit={handleSubmitIncoming} className="mobile-modal-scroll min-h-0 flex-1 overflow-y-auto rounded-3xl border border-emerald-200 bg-white p-4 shadow-sm sm:p-6">
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">Date</span>
              <input type="date" className={inputClass} value={incomingForm.date} onChange={(event) => setIncomingForm({ ...incomingForm, date: event.target.value })} required />
            </label>
            <fieldset className="min-w-0 space-y-2">
              <legend className="text-sm font-semibold text-emerald-900">From</legend>
              <div className="grid grid-cols-2 gap-2">
                {['Student', 'Faculty'].map((senderType) => {
                  const selected = incomingForm.from === senderType;
                  return (
                    <label key={senderType} className={`flex min-h-12 cursor-pointer items-center gap-2 rounded-2xl border px-3 py-2 text-sm font-semibold transition focus-within:ring-2 focus-within:ring-emerald-200 ${selected ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-emerald-200 bg-white text-emerald-700 hover:bg-emerald-50'}`}>
                      <input
                        type="radio"
                        name="sender_type"
                        value={senderType}
                        checked={selected}
                        onChange={() => setIncomingForm({ ...incomingForm, from: senderType })}
                        className="h-4 w-4 shrink-0 accent-emerald-800"
                        required
                      />
                      <span>{senderType}</span>
                    </label>
                  );
                })}
              </div>
            </fieldset>
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">Document type</span>
              <input type="text" className={inputClass} value={incomingForm.subject} onChange={(event) => setIncomingForm({ ...incomingForm, subject: event.target.value })} placeholder="Enter document type" required />
            </label>
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">Recipient</span>
              <input type="text" className={inputClass} value={incomingForm.recipient} onChange={(event) => setIncomingForm({ ...incomingForm, recipient: event.target.value })} placeholder="Staff or office" required />
            </label>
            {formType === 'standard' && (
              <label className="space-y-2 sm:col-span-2">
                <span className="text-sm font-semibold text-emerald-900">Client contact number (optional)</span>
                <input type="tel" inputMode="numeric" autoComplete="tel-national" pattern="[0-9]{11}" title="Enter exactly 11 digits." className={inputClass} value={incomingForm.clientPhone} onChange={(event) => setIncomingForm({ ...incomingForm, clientPhone: contactNumberDigits(event.target.value) })} placeholder="09123456789" />
              </label>
            )}
          </div>

          <div className="sticky bottom-0 mt-5 flex flex-col gap-3 border-t border-emerald-100 bg-white/95 px-4 pb-4 pt-3 backdrop-blur sm:flex-row sm:justify-end sm:px-0 sm:pb-0">
            <button type="button" onClick={() => closePopup(() => setIncomingModalMode(null))} disabled={isFormSubmitting} className="min-h-12 rounded-3xl border border-emerald-200 bg-emerald-50 px-5 py-3.5 text-base font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-60">
              Cancel
            </button>
            <button type="submit" disabled={incomingSubmitting || !isOnline} className={`${buttonClass} disabled:cursor-wait disabled:opacity-70`} aria-live="polite">
              {incomingSubmitting ? <><span className="loading-spinner" aria-hidden="true" />Submitting…</> : !isOnline ? 'Connection required' : formType === 'quick' ? 'Quick Submit' : 'Submit Document'}
            </button>
          </div>
        </form>
      );
    }

    if (formType === 'batches') {
      return (
        <form onSubmit={handleSubmitBatch} className="mobile-modal-scroll min-h-0 flex-1 overflow-y-auto rounded-3xl border border-emerald-200 bg-white p-4 shadow-sm sm:p-6">
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">Batch type / document name</span>
              <input type="text" maxLength={255} className={inputClass} value={batchForm.type} onChange={(event) => setBatchForm({ ...batchForm, type: event.target.value })} placeholder="e.g. Transcript request documents" required />
            </label>
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">Date</span>
              <input type="date" className={inputClass} value={batchForm.date} onChange={(event) => setBatchForm({ ...batchForm, date: event.target.value })} required />
            </label>
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">From</span>
              <select className={inputClass} value={batchForm.senderType} onChange={(event) => setBatchForm({ ...batchForm, senderType: event.target.value })} required>
                <option value="Student">Student</option>
                <option value="Faculty">Faculty</option>
              </select>
            </label>
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">Recipient contact type</span>
              <select className={inputClass} value={batchForm.recipientType} onChange={(event) => setBatchForm({ ...batchForm, recipientType: event.target.value, recipient: event.target.value === 'Unassigned' ? '' : batchForm.recipient })} required>
                <option value="Email">Email</option>
                <option value="Contact">Contact</option>
                <option value="Unassigned">Unassigned</option>
              </select>
            </label>
            <label className="space-y-2 sm:col-span-2">
              <span className="text-sm font-semibold text-emerald-900">{batchForm.recipientType === 'Email' ? 'Recipient email' : 'Recipient contact'}</span>
              <input
                type={batchForm.recipientType === 'Email' ? 'email' : batchForm.recipientType === 'Contact' ? 'tel' : 'text'}
                inputMode={batchForm.recipientType === 'Contact' ? 'numeric' : undefined}
                pattern={batchForm.recipientType === 'Contact' ? '[0-9]{11}' : undefined}
                maxLength={batchForm.recipientType === 'Contact' ? undefined : 255}
                title={batchForm.recipientType === 'Contact' ? 'Enter exactly 11 digits.' : undefined}
                className={inputClass}
                value={batchForm.recipient}
                onChange={(event) => setBatchForm({ ...batchForm, recipient: batchForm.recipientType === 'Contact' ? contactNumberDigits(event.target.value) : event.target.value })}
                placeholder={batchForm.recipientType === 'Email' ? 'name@example.com' : batchForm.recipientType === 'Contact' ? 'e.g. 09123456789' : 'No recipient assigned'}
                disabled={batchForm.recipientType === 'Unassigned'}
                required={batchForm.recipientType !== 'Unassigned'}
              />
            </label>
            <label className="space-y-2 sm:col-span-2">
              <span className="text-sm font-semibold text-emerald-900">Number of papers</span>
              <input type="number" inputMode="numeric" min="1" max="9999" className={inputClass} value={batchForm.paperCount} onChange={(event) => setBatchForm({ ...batchForm, paperCount: event.target.value })} required />
            </label>
          </div>
          <label className="mt-4 block space-y-2">
            <span className="text-sm font-semibold text-emerald-900">Batch remarks</span>
            <textarea className={inputClass} rows="2" value={batchForm.remarks} onChange={(event) => setBatchForm({ ...batchForm, remarks: event.target.value })} />
          </label>
          <div className="sticky bottom-0 mt-5 flex flex-col gap-3 border-t border-emerald-100 bg-white/95 px-4 pb-4 pt-3 backdrop-blur sm:flex-row sm:justify-end sm:px-0 sm:pb-0">
            <button type="button" disabled={batchSubmitting} onClick={() => closePopup(() => { setRecordModal(null); setActiveForm(null); })} className="inline-flex min-h-14 w-full items-center justify-center rounded-2xl border border-emerald-200 bg-emerald-50 px-6 py-4 text-base font-semibold text-emerald-700 disabled:opacity-60 sm:w-auto sm:min-w-36">Cancel</button>
            <button type="submit" disabled={batchSubmitting || !isOnline} className={`${buttonClass} min-h-14 w-full px-6 py-4 text-base disabled:cursor-wait disabled:opacity-70 sm:w-auto sm:min-w-48`} aria-live="polite">{batchSubmitting ? <><span className="loading-spinner" aria-hidden="true" />Saving…</> : !isOnline ? 'Connection required' : editingBatchId ? 'Save Bulk Papers Changes' : 'Save Bulk Papers'}</button>
          </div>
        </form>
      );
    }

    if (formType === 'borrowed') {
      return (
        <form onSubmit={handleSubmitBorrowed} className="mobile-modal-scroll min-h-0 flex-1 overflow-y-auto rounded-3xl border border-emerald-200 bg-white p-4 shadow-sm sm:p-6">
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">Item</span>
              <input type="text" className={inputClass} value={borrowedForm.item} onChange={(event) => setBorrowedForm({ ...borrowedForm, item: event.target.value })} placeholder="Item name" required />
            </label>
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">Borrower Name</span>
              <input type="text" className={inputClass} value={borrowedForm.borrower} onChange={(event) => setBorrowedForm({ ...borrowedForm, borrower: event.target.value })} placeholder="Borrower name" required />
            </label>
            <label className="space-y-2">
              <span className="text-sm font-semibold text-emerald-900">Borrower Phone (optional)</span>
              <input type="tel" inputMode="numeric" autoComplete="tel-national" pattern="[0-9]{11}" title="Enter exactly 11 digits." className={inputClass} value={borrowedForm.phone} onChange={(event) => setBorrowedForm({ ...borrowedForm, phone: contactNumberDigits(event.target.value) })} placeholder="09123456789" />
            </label>
          </div>
          <p className="mt-4 rounded-2xl bg-emerald-50 px-4 py-3 text-center text-sm text-emerald-800">
            Borrowed date is recorded automatically. You can mark the item returned when it comes back.
          </p>

          <div className="sticky bottom-0 mt-5 flex flex-col gap-3 border-t border-emerald-100 bg-white/95 px-4 pb-4 pt-3 backdrop-blur sm:flex-row sm:justify-end sm:px-0 sm:pb-0">
            <button type="button" disabled={borrowedSubmitting} onClick={() => closePopup(() => { setRecordModal(null); setActiveForm(null); })} className="inline-flex min-h-14 w-full items-center justify-center rounded-3xl border border-emerald-200 bg-emerald-50 px-6 py-4 text-base font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-60 sm:w-auto sm:min-w-36">
              Cancel
            </button>
            <button type="submit" disabled={borrowedSubmitting || !isOnline} className={`${buttonClass} min-h-14 w-full px-6 py-4 text-base disabled:cursor-wait disabled:opacity-70 sm:w-auto sm:min-w-48`} aria-live="polite">
            {borrowedSubmitting ? <><span className="loading-spinner" aria-hidden="true" />Saving…</> : !isOnline ? 'Connection required' : 'Record Item'}
            </button>
          </div>
        </form>
      );
    }

    return null;
  };

  const renderSectionContent = () => {
    if (selectedSection === 'incoming') {
      return (
        <>
          <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-center text-lg font-semibold text-emerald-950">Incoming documents</p>
            </div>
            <button className={buttonClass} onClick={() => openIncomingModal('standard')}>+ Add New</button>
          </div>

          <div className="space-y-2">
            {incomingItems.length === 0 ? <p className="rounded-2xl border border-emerald-200 bg-white p-5 text-center text-sm text-emerald-700">No incoming documents yet.</p> : incomingItems.map((item) => (
              <article key={`${item.date}-${item.subject}-${item.recipient}`} className="rounded-2xl border border-emerald-200 bg-white p-4 shadow-sm">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="break-words text-center font-semibold text-emerald-950">{item.subject}</p>
                    <p className="mt-1 text-center text-sm text-emerald-700">From {item.from} · To {item.recipient}</p>
                  </div>
                  <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${statusClasses[item.status] || 'bg-gray-100 text-gray-700'}`}>{item.status}</span>
                </div>
                <p className="mt-3 text-center text-xs text-emerald-600">Submitted {String(item.date).slice(0, 10)}</p>
                {item.id && item.status === 'Pending' && <button type="button" className="mt-3 rounded-lg border border-emerald-700 px-3 py-2 text-xs font-semibold text-emerald-800 disabled:cursor-wait disabled:opacity-50" disabled={releaseBusy || !isOnline} onClick={() => handlePrepareIncoming(item)}>{releaseBusy && releaseDocumentId === item.id ? 'Preparing…' : 'Prepare for release'}</button>}
              </article>
            ))}
          </div>
        </>
      );
    }

    if (selectedSection === 'outgoing') {
      return (
        <>
          <div className="mb-3">
            <p className="text-center text-lg font-semibold text-emerald-950">Outgoing documents</p>
            <p className="mt-1 text-center text-sm text-emerald-700">Documents prepared for release from incoming submissions appear here.</p>
          </div>

          <div className="space-y-2">
            {outgoingItems.length === 0 ? <p className="rounded-2xl border border-emerald-200 bg-white p-5 text-center text-sm text-emerald-700">No documents are prepared for release yet. Prepare a pending submission from Incoming Documents.</p> : outgoingItems.map((item) => (
              <article key={item.id || `${item.date}-${item.subject}-${item.to}`} className="rounded-2xl border border-emerald-200 bg-white p-4 shadow-sm">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="break-words text-center font-semibold text-emerald-950">{item.subject}</p>
                    <p className="mt-1 text-center text-sm text-emerald-700">Recipient: {item.to}</p>
                    {item.clientPhone && <p className="mt-1 text-center text-xs text-emerald-600">Client contact: {item.clientPhone}</p>}
                  </div>
                  <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${statusClasses[item.status] || 'bg-gray-100 text-gray-700'}`}>{item.status}</span>
                </div>
                <p className="mt-3 text-center text-xs text-emerald-600">Date: {String(item.date).slice(0, 10)}</p>
                {item.remarks && <p className="mt-2 whitespace-pre-wrap break-words text-sm text-emerald-700">{item.remarks}</p>}
                {item.id && (
                  <button
                    type="button"
                    className="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                    disabled={releaseBusy || !isOnline}
                    onClick={() => handleSetOutgoingStatus(item, item.status === 'Released' ? 'For Release' : 'Released')}
                  >
                    {releaseBusy && releaseDocumentId === item.id ? 'Updating…' : item.status === 'Released' ? 'Set For Release' : 'Mark Released'}
                  </button>
                )}
                {item.id && item.status === 'Released' && item.clientPhone ? (
                  <button type="button" className="mt-3 rounded-lg border border-emerald-700 px-3 py-2 text-xs font-semibold text-emerald-800 disabled:cursor-wait disabled:opacity-50" disabled={smsBusyId === item.id || !isOnline} onClick={() => handleNotifyClient(item)}>{smsBusyId === item.id ? 'Sending…' : 'Send SMS'}</button>
                ) : item.status === 'Released' ? <p className="mt-2 text-xs text-emerald-600">No client contact saved.</p> : null}
              </article>
            ))}
          </div>
        </>
      );
    }

    if (selectedSection === 'borrowed') {
      return (
        <>
          <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-center text-lg font-semibold text-emerald-950">Borrowed items</p>
            </div>
            {!activeForm && <button className={`${buttonClass} min-h-14 min-w-40 text-center`} onClick={() => openRecordModal('borrowed')}>+ Add item</button>}
          </div>

          <div className="space-y-2">
            {borrowedItems.length === 0 ? <p className="rounded-2xl border border-emerald-200 bg-white p-5 text-center text-sm text-emerald-700">No borrowed items recorded yet.</p> : borrowedItems.map((item) => (
              <article key={item.id ?? `${item.item}-${item.borrower}-${item.borrowed}`} className="rounded-2xl border border-emerald-200 bg-white p-4 shadow-sm">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="break-words text-center font-semibold text-emerald-950">{item.item}</p>
                    <p className="mt-1 text-center text-sm text-emerald-700">Borrowed by {item.borrower}</p>
                  </div>
                  <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${statusClasses[item.status] || 'bg-gray-100 text-gray-700'}`}>{item.status}</span>
                </div>
                <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-emerald-600">
                  <span>Borrowed {String(item.borrowed).slice(0, 10)}</span>
                  <span>{item.returned ? `Returned ${String(item.returned).slice(0, 10)}` : 'Awaiting return'}</span>
                </div>
                {item.status !== 'Returned' && item.id && (
                  <button
                    type="button"
                    onClick={() => handleMarkBorrowedReturned(item)}
                    disabled={borrowedBusyId !== null || !isOnline}
                    className="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100 focus:outline-none focus:ring-4 focus:ring-emerald-200 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                  >
                    {borrowedBusyId === item.id ? 'Updating…' : 'Mark returned'}
                  </button>
                )}
              </article>
            ))}
          </div>
        </>
      );
    }

    if (selectedSection === 'batches') {
      return (
        <>
          <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-center text-lg font-semibold text-emerald-950">Bulk Papers</p>
            </div>
            {!activeForm && <button className={`${buttonClass} min-h-14 min-w-40 text-center`} onClick={() => openRecordModal('batches')}>+ Add Bulk Papers</button>}
          </div>

          <div className="space-y-3">
            {batchItems.length === 0 ? (
              <div className="rounded-3xl border border-emerald-200 bg-white px-5 py-10 text-center text-sm text-emerald-700">No bulk papers have been recorded.</div>
            ) : batchItems.map((batch) => (
              <article key={batch.id || batch.batchCode} className="rounded-3xl border border-emerald-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="font-semibold text-emerald-950">{batch.batchCode}</p>
                    <p className="mt-1 text-sm text-emerald-700">{batch.batchDate} · From {batch.senderType || batch.sender}</p>
                  </div>
                  <div className="flex flex-wrap items-center justify-end gap-2">
                    {Number(batch.isPriority) === 1 && <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Priority</span>}
                    <span className={`rounded-full px-3 py-1 text-xs font-semibold ${statusClasses[batch.status] || 'bg-amber-100 text-amber-700'}`}>{batch.status || 'Pending'}</span>
                  </div>
                </div>
                <p className="mt-2 text-sm font-semibold text-emerald-900">{batch.batchType || 'Bulk papers'} · {batch.paperCount} papers</p>
                <p className="mt-2 text-sm text-emerald-700">Recipient: {batch.recipientContactType || 'Unassigned'}{batch.recipient ? ` · ${batch.recipient}` : ''}</p>
                {batch.remarks && <p className="mt-2 break-words text-sm text-emerald-700">{batch.remarks}</p>}
                {batch.id && batch.status !== 'Released' && (
                  <button
                    type="button"
                    disabled={batchStatusBusyId !== null || !isOnline}
                    className="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-60"
                    onClick={() => handleSetBatchStatus(batch)}
                  >
                    {batchStatusBusyId === batch.id
                      ? 'Updating…'
                      : batch.status === 'For Release' ? 'Mark Released' : 'Move to For Release'}
                  </button>
                )}
                {batch.id && (
                  <button
                    type="button"
                    className="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100"
                    onClick={() => {
                      setEditingBatchId(batch.id);
                      setBatchForm({
                        type: batch.batchType || '',
                        documentType: batch.documentType || 'Incoming',
                        date: String(batch.batchDate || '').slice(0, 10),
                        senderType: batch.senderType || 'Student',
                        recipientType: batch.recipientContactType || 'Unassigned',
                        recipient: batch.recipientContactType === 'Unassigned' ? '' : (batch.recipient || ''),
                        paperCount: String(batch.paperCount || 1),
                        remarks: batch.remarks || '',
                      });
                      setRecordModal('batches');
                    }}
                  >
                    Edit Bulk Papers
                  </button>
                )}
              </article>
            ))}
          </div>
        </>
      );
    }

    if (selectedSection === 'reports') {
      return (
        <>
          <form onSubmit={handleGenerateReport} className="grid gap-4 rounded-3xl border border-emerald-200 bg-white p-4 shadow-sm sm:grid-cols-[1.6fr_1fr] sm:p-6">
            <div className="grid gap-4">
              <div>
                <p className="text-sm font-semibold text-emerald-700">Select Report Type</p>
                <select className={inputClass} value={reportForm.type} onChange={(event) => setReportForm({ ...reportForm, type: event.target.value })}>
                  <option value="All">All</option>
                  <option value="Incoming">Incoming</option>
                  <option value="Outgoing">Released</option>
                  <option value="Borrowed">Borrowed</option>
                  <option value="SMS Logs">SMS Logs</option>
                </select>
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <label className="space-y-2">
                  <span className="text-sm font-semibold text-emerald-700">From</span>
                  <input type="date" className={inputClass} value={reportForm.from} onChange={(event) => setReportForm({ ...reportForm, from: event.target.value })} />
                </label>
                <label className="space-y-2">
                  <span className="text-sm font-semibold text-emerald-700">To</span>
                  <input type="date" className={inputClass} value={reportForm.to} onChange={(event) => setReportForm({ ...reportForm, to: event.target.value })} />
                </label>
              </div>
            </div>
            <div className="flex items-end">
              <button type="submit" disabled={reportLoading || !isOnline} className="w-full rounded-3xl bg-emerald-900 px-5 py-4 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-70" aria-live="polite">
                {reportLoading ? <><span className="loading-spinner" aria-hidden="true" />Generating…</> : !isOnline ? 'Connection required' : 'Filter Report'}
              </button>
            </div>
          </form>

          <div className="mt-5 overflow-x-auto rounded-3xl border border-emerald-200 bg-white shadow-sm">
            <div className="grid grid-cols-2 gap-3 border-b border-emerald-200 px-4 py-3 text-xs font-semibold uppercase tracking-[0.12em] text-emerald-500 sm:grid-cols-4 sm:gap-4 sm:tracking-[0.2em]">
              <span>Date</span>
              <span>Type</span>
              <span>Document type</span>
              <span>Status</span>
            </div>
            <div className="space-y-2 p-4">
              {reportLoading ? (
                <div className="rounded-3xl bg-emerald-50 px-4 py-10 text-center text-sm text-emerald-700">Loading report...</div>
              ) : reportResult.length === 0 ? (
                <div className="rounded-3xl bg-emerald-50 px-4 py-10 text-center text-sm text-emerald-700 shadow-sm">
                  No report results match the selected filters.
                </div>
              ) : (
                reportResult.map((item) => (
                  <div key={`${item.date}-${item.subject}-${item.type}`} className="grid grid-cols-2 gap-3 rounded-2xl bg-emerald-50 px-3 py-3 text-sm text-emerald-900 shadow-sm sm:grid-cols-4 sm:gap-4 sm:rounded-3xl sm:px-4 sm:py-4">
                    <span className="break-words">{item.date}</span>
                    <span className="break-words">{item.type}</span>
                    <span className="break-words">{item.subject}</span>
                    <span className={`inline-flex w-fit items-center justify-center rounded-full px-3 py-1 text-xs font-semibold ${statusClasses[item.status] || 'bg-gray-100 text-gray-700'}`}>
                      {item.status}
                    </span>
                  </div>
                ))
              )}
            </div>
          </div>
        </>
      );
    }

    return null;
  };

  const handlePullTouchStart = (event) => {
    if (!apiData || !isOnline || isAnyModalOpen || refreshInProgressRef.current || window.scrollY > 0 || event.touches.length !== 1) return;
    pullGestureRef.current = { startY: event.touches[0].clientY, armed: false };
  };

  const handlePullTouchMove = (event) => {
    const gesture = pullGestureRef.current;
    if (!gesture || event.touches.length !== 1 || window.scrollY > 0) return;

    const distance = Math.max(0, event.touches[0].clientY - gesture.startY);
    const resistedDistance = Math.min(distance * 0.55, 96);
    setPullDistance(resistedDistance);

    if (resistedDistance >= 72 && !gesture.armed) {
      gesture.armed = true;
      if (typeof navigator.vibrate === 'function') navigator.vibrate(18);
      playSwipeTone();
    } else if (resistedDistance < 72 && gesture.armed) {
      gesture.armed = false;
    }
  };

  const finishPullGesture = () => {
    const gesture = pullGestureRef.current;
    pullGestureRef.current = null;
    setPullDistance(0);

    if (gesture?.armed && isOnline && !refreshInProgressRef.current) {
      setPullRefreshing(true);
      refreshRequestRef.current?.();
    }
  };

  const cancelPullGesture = () => {
    pullGestureRef.current = null;
    setPullDistance(0);
  };

  if (loading && !apiData) {
    return (
      <div className="min-h-screen min-h-[100svh] overflow-x-clip text-emerald-950" aria-busy="true">
        <div className="mobile-app-container mx-auto flex min-h-screen min-h-[100svh] max-w-6xl flex-col px-3 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-[max(0.75rem,env(safe-area-inset-top))] sm:px-6 sm:pt-[max(1.25rem,env(safe-area-inset-top))] lg:px-8">
          <header className="mobile-app-header flex w-full items-center justify-between gap-2 rounded-2xl border border-emerald-200 bg-white px-3 py-2.5 sm:gap-3 sm:px-4">
            <div className="flex min-w-0 items-center gap-2.5">
              <img src={officeLogoUrl} alt="" className="app-header-logo" />
              <div className="min-w-0">
                <p className="truncate text-[10px] font-semibold uppercase text-emerald-600">CEIT Digital Logbook</p>
                <p className="truncate text-sm font-semibold text-emerald-950">Dean&apos;s Office</p>
              </div>
            </div>
            <span className="shrink-0 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-1.5 text-[10px] font-semibold text-emerald-800 sm:px-3 sm:py-1 sm:text-xs">
              Office kiosk
            </span>
          </header>
          <main className="flex flex-1 items-center justify-center py-8" aria-label="Loading office logbook">
            <section className="w-full max-w-md rounded-3xl border border-emerald-200 bg-white/95 p-6 text-center shadow-lg shadow-emerald-950/5 sm:p-8" aria-busy="true">
              <img src={officeLogoUrl} alt="" className="welcome-logo mx-auto rounded-xl bg-white p-1 ring-1 ring-emerald-200" />
              <div className="mx-auto mt-6 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-800" role="status" aria-label="Loading">
                <span className="h-6 w-6 animate-spin rounded-full border-[3px] border-emerald-200 border-t-emerald-800" />
              </div>
              <h1 className="mt-4 text-xl font-bold text-emerald-950 sm:text-2xl">Connecting to the office logbook</h1>
              <p className="mt-2 text-sm leading-relaxed text-emerald-700">Please wait while we load the latest document statuses and office records.</p>
              <div className="mt-6 space-y-2.5" aria-hidden="true">
                <div className="loading-skeleton h-3 rounded-full" />
                <div className="loading-skeleton mx-auto h-3 w-4/5 rounded-full" />
                <div className="loading-skeleton mx-auto h-3 w-3/5 rounded-full" />
              </div>
            </section>
          </main>
        </div>
      </div>
    );
  }

  return (
    <div
      className="pull-refresh-enabled min-h-screen min-h-[100svh] overflow-x-clip text-emerald-950"
      onTouchStart={handlePullTouchStart}
      onTouchMove={handlePullTouchMove}
      onTouchEnd={finishPullGesture}
      onTouchCancel={cancelPullGesture}
    >
      {(pullDistance > 6 || pullRefreshing) && (
        <div
          className="pull-refresh-indicator"
          style={{ transform: `translate(-50%, ${pullRefreshing ? 12 : Math.max(-52, pullDistance - 52)}px)` }}
          role="status"
          aria-live="polite"
        >
          <span
            className={`pull-refresh-circle${pullRefreshing ? ' is-refreshing' : ''}`}
            style={!pullRefreshing ? { transform: `rotate(${Math.round(Math.min(pullDistance / 72, 1) * 180)}deg)` } : undefined}
            aria-hidden="true"
          />
          <span>{pullRefreshing ? 'Refreshing…' : pullDistance >= 72 ? 'Release to refresh' : 'Pull to refresh'}</span>
        </div>
      )}
      <div className="mobile-app-container mx-auto max-w-6xl pb-[max(1.5rem,env(safe-area-inset-bottom))] pl-[max(0.75rem,env(safe-area-inset-left))] pr-[max(0.75rem,env(safe-area-inset-right))] pt-[max(0.75rem,env(safe-area-inset-top))] sm:px-6 sm:pt-[max(1.25rem,env(safe-area-inset-top))] lg:px-8">
        <header className="mobile-app-header flex w-full items-center justify-between gap-2 rounded-2xl border border-emerald-200 bg-white px-3 py-2.5 sm:gap-3 sm:px-4">
          <div className="flex min-w-0 items-center gap-2.5">
            <img src={officeLogoUrl} alt="Cavite State University seal" className="app-header-logo" />
            <div className="min-w-0">
              <p className="truncate text-[10px] font-semibold uppercase text-emerald-600">CEIT Digital Logbook</p>
              <p className="truncate text-sm font-semibold text-emerald-950">Dean&apos;s Office</p>
            </div>
          </div>
          <div className="flex shrink-0 items-center">
            <span className="whitespace-nowrap rounded-full border border-emerald-200 bg-emerald-50 px-2 py-1.5 text-[10px] font-semibold text-emerald-800 sm:px-3 sm:py-1 sm:text-xs">
              Office kiosk
            </span>
          </div>
        </header>

        <main className="welcome-main flex justify-center py-3 sm:py-4 lg:py-5">
          <div className="w-full">
            {refreshError && apiData && (
              <div className="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-center text-sm text-amber-900" role="status">
                Could not reach the office server. Your screen is showing the last successful update; it will retry automatically.
              </div>
            )}
            {!isOnline && (
              <div className="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-center text-sm text-amber-900" role="alert">
                Connection lost. Your entries are not saved offline. Reconnect before submitting.
              </div>
            )}
            {!loading && !apiData && (
              <div className="mb-3 rounded-xl border border-rose-200 bg-white px-4 py-4 text-center text-sm text-rose-800" role="alert">
                <p className="mb-3">{apiError || 'Could not connect to the office logbook. Saving requires a connection.'}</p>
                <button
                  type="button"
                  onClick={() => setReloadCount((count) => count + 1)}
                  disabled={loading || !isOnline}
                  className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-900 px-5 py-2.5 font-semibold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {loading ? <><span className="loading-spinner" aria-hidden="true" />Retrying…</> : 'Try again'}
                </button>
              </div>
            )}
            {apiData && (
              <section className="welcome-status-panel mb-3 rounded-2xl border border-emerald-200 bg-white p-3 shadow-sm sm:mb-4 sm:p-4" aria-labelledby="document-statuses-title">
                <h2 id="document-statuses-title" className="welcome-status-heading mb-2 text-center text-sm font-bold text-emerald-950 sm:text-base">Document Status</h2>
                <div className="welcome-status-grid grid gap-2">
                  {documentStatusCounts.map((item) => (
                    <div key={item.status} className={`flex min-h-14 items-center justify-between gap-2 rounded-xl px-3 py-2 ${item.status === 'Pending' ? 'border border-amber-300 bg-amber-50' : 'bg-emerald-50'}`}>
                      <span className="min-w-0 break-words text-xs font-semibold text-emerald-800 sm:text-sm">{item.status}</span>
                      <span className={`inline-flex min-w-8 shrink-0 items-center justify-center rounded-full px-2 py-1 text-sm font-bold ${statusClasses[item.status] || 'bg-white text-emerald-800'}`}>
                        {Number(item.count) || 0}
                      </span>
                    </div>
                  ))}
                </div>
              </section>
            )}
            <section className="welcome-card grid w-full items-stretch gap-4 rounded-3xl border border-emerald-200 bg-white p-3 shadow-sm sm:gap-6 sm:p-6 lg:grid-cols-[1fr_0.85fr] lg:gap-8 lg:p-8">
              <div className="welcome-hero flex flex-col items-center justify-center rounded-2xl bg-emerald-50/70 p-5 text-center sm:p-8">
                <img src={officeLogoUrl} alt="Cavite State University seal" className="welcome-logo rounded-xl bg-white p-1 ring-1 ring-emerald-200" />
                <p className="mt-6 text-sm font-semibold text-emerald-700">Dean&apos;s Office</p>
                <h1 className="mt-2 w-full max-w-xl text-center text-3xl font-bold leading-tight text-emerald-950 sm:text-4xl">
                  Welcome to the CEIT Dean&apos;s Office
                </h1>
                <p className="mt-3 max-w-lg text-center text-base leading-relaxed text-emerald-800">
                  Keep document submissions, Bulk Papers, and borrowed items organized in one place.
                </p>
                <button
                  type="button"
                  onClick={() => setLogbookPopupOpen(true)}
                  className="mt-6 inline-flex min-h-14 w-full items-center justify-center gap-3 rounded-2xl bg-emerald-900 px-7 py-3.5 text-center text-base font-bold text-white shadow-md shadow-emerald-950/15 transition hover:-translate-y-0.5 hover:bg-emerald-800 hover:shadow-lg focus:outline-none focus:ring-4 focus:ring-emerald-300 active:translate-y-0 active:scale-[0.99] sm:w-auto sm:min-w-60"
                >
                  Open Logbook <BiChevronLeft className="rotate-180" size={22} aria-hidden="true" />
                </button>
                <p className="mt-4 text-center text-sm text-emerald-600">
                  {new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}
                </p>
              </div>
              <aside className="welcome-guide rounded-2xl border border-emerald-200 bg-white p-4 sm:p-6" aria-label="Logbook tools guide">
                <div className="quick-guide-heading mb-3 border-b border-emerald-200 pb-3 sm:mb-4">
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-700">Quick guide</p>
                  <h2 className="mt-1 text-lg font-bold text-emerald-950">Choose the right tool</h2>
                  <p className="mt-1 text-xs leading-relaxed text-emerald-700 sm:text-sm">A quick look at what you can do here.</p>
                </div>
                <div className="quick-guide-list">
                  {[
                    { icon: BiArchiveIn, title: 'Submit document', detail: 'Record an incoming document with recipient details.' },
                    { icon: BiFile, title: 'Quick submit', detail: 'Log one document without a contact number.' },
                    { icon: BiLayer, title: 'Bulk Papers', detail: 'Register multiple papers together in one submission.' },
                    { icon: BiBox, title: 'Borrow item', detail: 'Record borrowed items and track their return.' },
                  ].map((item) => {
                    const Icon = item.icon;
                    return (
                      <div key={item.title} className="quick-guide-item flex min-w-0 items-center gap-3 rounded-xl px-2.5 py-3 sm:gap-4 sm:px-3">
                        <span className="quick-guide-icon flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-800 sm:h-11 sm:w-11">
                          <Icon size={23} aria-hidden="true" />
                        </span>
                        <span className="min-w-0 text-left">
                          <span className="block text-sm font-semibold text-emerald-950">{item.title}</span>
                          <span className="mt-0.5 block text-xs leading-relaxed text-emerald-700 sm:text-sm">{item.detail}</span>
                        </span>
                      </div>
                    );
                  })}
                </div>
              </aside>
            </section>
          </div>
        </main>
        {logbookPopupOpen && (
          <div
            className={`mobile-modal-backdrop-enter ${isModalClosing ? 'mobile-modal-backdrop-exit' : ''} fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4`}
            onClick={() => closePopup(() => setLogbookPopupOpen(false))}
          >
            <section
              role="dialog"
              aria-modal="true"
              aria-labelledby="logbook-popup-title"
              className={`mobile-modal-panel-enter ${isModalClosing ? 'mobile-modal-panel-exit' : ''} max-h-[92dvh] w-full overflow-y-auto rounded-t-[2rem] bg-emerald-50 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl sm:max-w-2xl sm:rounded-[2rem] sm:p-6`}
              onClick={(event) => event.stopPropagation()}
            >
              <div className="mb-5 flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <p className="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">CEIT Dean&apos;s Office</p>
                  <h2 id="logbook-popup-title" className="mt-1 text-xl font-bold leading-tight text-emerald-950 sm:text-2xl">What would you like to do?</h2>
                  <p className="mt-2 max-w-prose text-sm leading-relaxed text-emerald-700">Choose an office task. Each option opens only the form you need.</p>
                </div>
                <button
                  type="button"
                  aria-label="Close logbook menu"
                  onClick={() => closePopup(() => setLogbookPopupOpen(false))}
                  className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-full border border-emerald-200 bg-white text-lg font-semibold text-emerald-800 transition hover:border-emerald-400 hover:bg-emerald-100 focus:outline-none focus:ring-4 focus:ring-emerald-200"
                >
                  ×
                </button>
              </div>
              <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2">
                {[
                  { key: 'submit', label: 'Submit document', detail: 'Send your document to the Dean’s Office.', icon: BiArchiveIn },
                  { key: 'quick', label: 'Quick submit', detail: 'Get your document into the logbook in just a few steps.', icon: BiFile },
                  { key: 'batch', label: 'Bulk Papers', detail: 'Register multiple papers together in one submission.', icon: BiLayer },
                  { key: 'borrow', label: 'Borrow item', detail: 'Record who has the item and mark it returned when it comes back.', icon: BiBox },
                  { key: 'status', label: 'Check status', detail: 'Look up submitted documents by contact number.', icon: BiArchiveOut },
                ].map((item) => {
                  const Icon = item.icon;
                  return (
                    <button
                      key={item.key}
                      type="button"
                      onClick={() => launchLogbookAction(item.key)}
                      className={`group flex min-h-[5.5rem] items-center gap-3 rounded-2xl border border-emerald-200 bg-white p-3 text-center shadow-sm transition duration-150 hover:-translate-y-0.5 hover:border-emerald-400 hover:bg-emerald-50 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-emerald-200 active:translate-y-0 active:scale-[0.99] min-[480px]:p-4 ${item.key === 'status' ? 'min-[480px]:col-span-2 min-[480px]:mx-auto min-[480px]:w-[calc(50%-0.375rem)]' : ''}`}
                    >
                      <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-900 transition group-hover:bg-emerald-200 min-[480px]:h-12 min-[480px]:w-12">
                        <Icon size={25} aria-hidden="true" />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-center font-semibold text-emerald-950">{item.label}</span>
                        <span className="mt-0.5 block text-center text-xs leading-relaxed text-emerald-700">{item.detail}</span>
                      </span>
                      <BiChevronLeft className="rotate-180 text-emerald-500 transition group-hover:translate-x-0.5 group-hover:text-emerald-800" size={20} aria-hidden="true" />
                    </button>
                  );
                })}
              </div>
              <div className="mt-5 flex justify-center border-t border-emerald-200 pt-4 sm:justify-end">
                <button
                  type="button"
                  onClick={() => closePopup(() => setLogbookPopupOpen(false))}
                  className="min-h-11 w-full rounded-xl border border-emerald-200 bg-white px-5 py-3 text-sm font-semibold text-emerald-800 transition hover:border-emerald-400 hover:bg-emerald-100 focus:outline-none focus:ring-4 focus:ring-emerald-200 sm:w-auto"
                >
                  Cancel
                </button>
              </div>
            </section>
          </div>
        )}
        {isFormModalOpen && (
          <div
            className={`mobile-modal-backdrop-enter ${isModalClosing ? 'mobile-modal-backdrop-exit' : ''} fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4`}
            onClick={() => {
              if (!isFormSubmitting) {
                closePopup(() => {
                  setIncomingModalMode(null);
                  setRecordModal(null);
                  setActiveForm(null);
                });
              }
            }}
          >
            <section
              role="dialog"
              aria-modal="true"
              aria-labelledby="incoming-modal-title"
              className={`mobile-modal-panel-enter ${isModalClosing ? 'mobile-modal-panel-exit' : ''} flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-[2rem] bg-emerald-50 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl sm:max-w-3xl sm:rounded-[2rem] sm:p-6`}
              onClick={(event) => event.stopPropagation()}
            >
              <div className="mb-4 shrink-0">
                <div className="mb-3 flex items-center justify-between gap-3">
                  <button
                    type="button"
                    disabled={isFormSubmitting}
                    onClick={() => {
                      if (isFormSubmitting) return;
                      closePopup(() => {
                        setIncomingModalMode(null);
                        setRecordModal(null);
                        setEditingBatchId(null);
                        setActiveForm(null);
                        setLogbookPopupOpen(true);
                      });
                    }}
                    className="inline-flex min-h-10 items-center gap-1 rounded-xl px-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100 focus:outline-none focus:ring-4 focus:ring-emerald-200 disabled:opacity-50"
                  >
                    <BiChevronLeft size={20} aria-hidden="true" />
                    <span>Back to options</span>
                  </button>
                  <button
                    type="button"
                    aria-label="Close submission form"
                    disabled={isFormSubmitting}
                    onClick={() => {
                      if (!isFormSubmitting) {
                        closePopup(() => {
                          setIncomingModalMode(null);
                          setRecordModal(null);
                          setEditingBatchId(null);
                          setActiveForm(null);
                        });
                      }
                    }}
                    className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-full border border-emerald-200 bg-white text-lg font-semibold text-emerald-800 transition hover:bg-emerald-100 disabled:opacity-50"
                  >
                    ×
                  </button>
                </div>
                <div className="text-center">
                  <p className="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-600">
                    {incomingModalMode === 'quick'
                      ? 'Fast entry · no contact details'
                      : isBatchOrBorrowModal ? 'Office records' : 'Document submission'}
                  </p>
                  <h2 id="incoming-modal-title" className="mt-1 text-center text-xl font-bold text-emerald-950 sm:text-2xl">
                    {incomingModalMode === 'quick'
                      ? 'Quick Submit'
                      : isBatchOrBorrowModal
                        ? recordModal === 'batches' ? 'Register Bulk Papers' : 'Record Borrowed Item'
                        : 'Submit Document'}
                  </h2>
                  <p className="mt-1 text-center text-sm text-emerald-700">
                    {incomingModalMode === 'quick'
                      ? 'Records the document without collecting or saving a contact number.'
                      : isBatchOrBorrowModal
                        ? recordModal === 'batches'
                          ? editingBatchId ? 'Update the bulk paper details and total paper count.' : 'Enter the bulk paper details and total paper count.'
                          : 'Record the item and borrower. Update its status when it is returned.'
                        : 'Add the document details. A contact number is optional.'}
                  </p>
                </div>
              </div>
              {incomingModalMode ? renderForm(incomingModalMode) : recordModal ? renderForm(recordModal) : renderForm()}
            </section>
          </div>
        )}
        {submissionSuccess && (
          <div
            className={`mobile-modal-backdrop-enter ${isModalClosing ? 'mobile-modal-backdrop-exit' : ''} fixed inset-0 z-[70] flex items-end justify-center bg-emerald-950/45 p-0 backdrop-blur-sm sm:items-center sm:p-4`}
            onClick={() => closePopup(() => setSubmissionSuccess(null))}
          >
            <section
              role="dialog"
              aria-modal="true"
              aria-labelledby="submission-success-title"
              className={`mobile-modal-panel-enter ${isModalClosing ? 'mobile-modal-panel-exit' : ''} w-full rounded-t-[2rem] bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-2xl sm:max-w-md sm:rounded-[2rem] sm:p-7`}
              onClick={(event) => event.stopPropagation()}
            >
              <div className="flex justify-end">
                <button
                  type="button"
                  aria-label="Close submission confirmation"
                  onClick={() => closePopup(() => setSubmissionSuccess(null))}
                  className="inline-flex min-h-10 min-w-10 items-center justify-center rounded-full border border-emerald-100 bg-emerald-50 text-lg font-semibold text-emerald-800 transition hover:bg-emerald-100 focus:outline-none focus:ring-4 focus:ring-emerald-200"
                >
                  ×
                </button>
              </div>
              <div className="mx-auto -mt-5 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-800 ring-8 ring-emerald-50">
                <BiCheckCircle size={38} aria-hidden="true" />
              </div>
              <p className="mt-5 text-center text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Submission complete</p>
              <h2 id="submission-success-title" className="mt-1 text-center text-xl font-bold text-emerald-950 sm:text-2xl">
                {submissionSuccess.title}
              </h2>
              <p className="mx-auto mt-2 max-w-sm text-center text-sm leading-relaxed text-emerald-800">
                {submissionSuccess.description}
              </p>
              {submissionSuccess.details?.length > 0 && (
                <dl className="mt-5 grid gap-2 rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4">
                  {submissionSuccess.details.map((detail) => (
                    <div key={detail.label} className="flex items-start justify-between gap-4 text-sm">
                      <dt className="shrink-0 text-emerald-700">{detail.label}</dt>
                      <dd className="min-w-0 break-words text-right font-semibold text-emerald-950">{detail.value}</dd>
                    </div>
                  ))}
                </dl>
              )}
              {submissionSuccess.reference && (
                <p className="mt-3 text-center text-xs font-medium text-emerald-700">{submissionSuccess.reference}</p>
              )}
              <button
                type="button"
                onClick={() => closePopup(() => setSubmissionSuccess(null))}
                className={`${buttonClass} mt-6 w-full`}
              >
                Done
              </button>
            </section>
          </div>
        )}
        {actionFeedback && (
          <div
            className={`mobile-modal-backdrop-enter ${isModalClosing ? 'mobile-modal-backdrop-exit' : ''} fixed inset-0 z-[70] flex items-end justify-center bg-emerald-950/45 p-0 backdrop-blur-sm sm:items-center sm:p-4`}
            onClick={() => closePopup(() => setActionFeedback(null))}
          >
            <section
              role="alertdialog"
              aria-modal="true"
              aria-labelledby="action-feedback-title"
              className={`mobile-modal-panel-enter ${isModalClosing ? 'mobile-modal-panel-exit' : ''} w-full rounded-t-[2rem] bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-2xl sm:max-w-md sm:rounded-[2rem] sm:p-7`}
              onClick={(event) => event.stopPropagation()}
            >
              <div className={`mx-auto flex h-16 w-16 items-center justify-center rounded-full ${actionFeedback.tone === 'danger' ? 'bg-rose-100 text-rose-700' : actionFeedback.tone === 'warning' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'} ring-8 ${actionFeedback.tone === 'danger' ? 'ring-rose-50' : actionFeedback.tone === 'warning' ? 'ring-amber-50' : 'ring-emerald-50'}`}>
                <i className={`bi ${actionFeedback.tone === 'danger' ? 'bi-exclamation-triangle' : actionFeedback.tone === 'warning' ? 'bi-exclamation-circle' : 'bi-check-circle'}`} aria-hidden="true" />
              </div>
              <p className={`mt-5 text-center text-xs font-bold uppercase tracking-[0.16em] ${actionFeedback.tone === 'danger' ? 'text-rose-700' : actionFeedback.tone === 'warning' ? 'text-amber-700' : 'text-emerald-700'}`}>
                {actionFeedback.tone === 'danger' ? 'Action not completed' : actionFeedback.tone === 'warning' ? 'Please review' : 'Action complete'}
              </p>
              <h2 id="action-feedback-title" className="mt-1 text-center text-xl font-bold text-emerald-950 sm:text-2xl">
                {actionFeedback.title}
              </h2>
              <p className="mx-auto mt-2 max-w-sm break-words text-center text-sm leading-relaxed text-emerald-800">
                {actionFeedback.description}
              </p>
              <button type="button" onClick={() => closePopup(() => setActionFeedback(null))} className={`${buttonClass} mt-6 w-full`}>
                Done
              </button>
            </section>
          </div>
        )}
        {clientStatusOpen && (
          <div
            className={`mobile-modal-backdrop-enter ${isModalClosing ? 'mobile-modal-backdrop-exit' : ''} fixed inset-0 z-[60] flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4`}
            onClick={() => closePopup(() => setClientStatusOpen(false))}
          >
            <section
              role="dialog"
              aria-modal="true"
              aria-labelledby="client-status-modal-title"
              className={`mobile-modal-panel-enter ${isModalClosing ? 'mobile-modal-panel-exit' : ''} max-h-[92dvh] w-full overflow-y-auto rounded-t-[2rem] bg-emerald-50 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl sm:max-w-xl sm:rounded-[2rem] sm:p-6`}
              onClick={(event) => event.stopPropagation()}
            >
              <div className="mb-5 flex items-start justify-between gap-4">
                <div className="flex-1 text-center">
                  <p className="text-xs font-semibold uppercase tracking-[0.14em] text-emerald-600">Document tracking</p>
                  <h2 id="client-status-modal-title" className="mt-1 text-center text-xl font-bold text-emerald-950 sm:text-2xl">Check document status</h2>
                  <p className="mt-1 text-center text-sm leading-relaxed text-emerald-700">Enter the contact number provided when the document was submitted.</p>
                </div>
                <button
                  type="button"
                  aria-label="Close status lookup"
                  onClick={() => closePopup(() => setClientStatusOpen(false))}
                  className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-full border border-emerald-200 bg-white text-lg font-semibold text-emerald-800 transition hover:bg-emerald-100"
                >
                  ×
                </button>
              </div>

              <form onSubmit={handleClientStatusLookup} className="rounded-2xl border border-emerald-200 bg-white p-4 sm:p-5">
                <label htmlFor="clientStatusPhone" className="mb-2 block text-sm font-semibold text-emerald-950">Contact number</label>
                <div className="flex flex-col gap-3 sm:flex-row">
                  <input
                    id="clientStatusPhone"
                    type="tel"
                    inputMode="numeric"
                    autoComplete="tel"
                    pattern="[0-9]{11}"
                    title="Enter exactly 11 digits."
                    value={clientStatusPhone}
                    onChange={(event) => setClientStatusPhone(contactNumberDigits(event.target.value))}
                    placeholder="09123456789"
                    aria-label="Client contact number for status lookup"
                    className={`${inputClass} min-w-0 flex-1`}
                    required
                  />
                  <button type="submit" className={`${buttonClass} shrink-0 disabled:cursor-wait disabled:opacity-70`} disabled={clientStatusLoading || !isOnline} aria-live="polite">
                    {clientStatusLoading ? <><span className="loading-spinner" aria-hidden="true" />Checking…</> : !isOnline ? 'Connection required' : 'Find documents'}
                  </button>
                </div>
                <p className="mt-2 text-xs text-emerald-600">Use the same 11-digit number you gave to the office.</p>
              </form>

              {clientStatusLoading && (
                <div className="mt-4 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-white p-4 text-sm font-medium text-emerald-800" role="status">
                  <span className="h-5 w-5 animate-spin rounded-full border-2 border-emerald-200 border-t-emerald-800" />
                  Looking up your documents…
                </div>
              )}
              {clientStatusError && <p className="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">{clientStatusError}</p>}
              {clientStatusSearched && !clientStatusError && clientStatusItems.length === 0 && (
                <div className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                  <p className="font-semibold text-amber-950">No matching documents</p>
                  <p className="mt-1 text-sm text-amber-800">Check the number and try again, or contact the office for help.</p>
                </div>
              )}
              {clientStatusItems.length > 0 && (
                <div className="mt-5">
                  <div className="mb-3 flex items-center justify-between gap-3">
                    <h3 className="font-semibold text-emerald-950">Matching documents</h3>
                    <span className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">
                      {clientStatusItems.length} {clientStatusItems.length === 1 ? 'record' : 'records'}
                    </span>
                  </div>
                  <div className="max-h-64 space-y-2 overflow-y-auto pr-1">
                    {clientStatusItems.map((record) => (
                      <article key={record.reference} className="rounded-2xl border border-emerald-200 bg-white p-4">
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <p className="break-words font-semibold text-emerald-950">{record.subject}</p>
                            <p className="mt-1 break-words text-xs text-emerald-700">{record.reference} · {record.type}</p>
                            <p className="mt-1 text-xs text-emerald-600">{record.date}</p>
                          </div>
                          <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${statusClasses[record.status] || 'bg-gray-100 text-gray-700'}`}>
                            {record.status}
                          </span>
                        </div>
                      </article>
                    ))}
                  </div>
                </div>
              )}
              <div className="mt-5 flex justify-end">
                <button type="button" onClick={() => closePopup(() => setClientStatusOpen(false))} className="min-h-11 rounded-xl border border-emerald-200 bg-white px-5 py-3 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">
                  Close
                </button>
              </div>
            </section>
          </div>
        )}
      </div>
    </div>
  );
}

export default App;
