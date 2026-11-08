export const API_ENDPOINT = import.meta.env.DEV
  ? '/api/mobile-api.php'
  : import.meta.env.VITE_API_ENDPOINT
    || `http://10.0.2.2${import.meta.env.VITE_PROJECT_PATH}/mobile-api.php`;
export const readApiResponse = async (response) => {
  try {
    return await response.json();
  } catch {
    throw new Error('The office server returned an unreadable response. If you submitted a change, check the records before retrying.');
  }
};

export const getActiveTrackerUrl = (url, trackerType) => {
  const activeTrackerUrl = new URL(url);
  if (import.meta.env.DEV && import.meta.env.VITE_PROJECT_PATH) {
    activeTrackerUrl.pathname = `${import.meta.env.VITE_PROJECT_PATH}/${trackerType === 'batch' ? 'batch_tracker' : 'document-tracker'}`;
    activeTrackerUrl.host = window.location.host;
    activeTrackerUrl.protocol = window.location.protocol;
  }
  return activeTrackerUrl;
};

export const verifyOfficeTrackerQr = async (payload, trackerBaseUrl, batchTrackerBaseUrl) => {
  let scannedUrl;
  let trackerUrl;
  let trackerType;
  try {
    scannedUrl = new URL(payload);
  } catch {
    throw new Error('This QR code is not a valid office tracker link.');
  }
  if (!['http:', 'https:'].includes(scannedUrl.protocol) || scannedUrl.username || scannedUrl.password) {
    throw new Error('This QR code must be an office-issued HTTP or HTTPS tracker link.');
  }
  const routeName = scannedUrl.pathname.split('/').filter(Boolean).pop()?.replace(/\.php$/i, '').toLowerCase();
  if (['document-tracker', 'document_tracker'].includes(routeName)) {
    trackerUrl = trackerBaseUrl ? new URL(trackerBaseUrl) : null;
    trackerType = 'document';
  } else if (routeName === 'batch_tracker') {
    trackerUrl = batchTrackerBaseUrl ? new URL(batchTrackerBaseUrl) : null;
    trackerType = 'batch';
  } else {
    throw new Error('This QR code was not issued by the CEIT Dean’s Office document or bulk submission tracker.');
  }
  if (!trackerUrl) {
    throw new Error('The office tracker URL is not configured. Contact the office to update its tracker settings.');
  }
  if (
    scannedUrl.hash !== ''
    || Array.from(scannedUrl.searchParams.keys()).length !== 1
  ) {
    throw new Error('This QR code was not issued by the CEIT Dean’s Office tracker.');
  }
  const token = scannedUrl.searchParams.get('token') || '';
  if (!/^[a-f0-9]{64}$/.test(token)) {
    throw new Error('This QR code does not contain a valid office tracking code.');
  }
  const verificationUrl = new URL(API_ENDPOINT, window.location.href);
  verificationUrl.search = new URLSearchParams({
    action: trackerType === 'batch' ? 'batch_tracker_status' : 'document_tracker_status',
    token,
  }).toString();
  const response = await fetch(verificationUrl, { cache: 'no-store' });
  const result = await readApiResponse(response);
  if (!response.ok || !result.success) {
    throw new Error(result.message || 'This tracking QR code could not be verified by the office.');
  }

  const activeTrackerUrl = getActiveTrackerUrl(trackerUrl, trackerType);
  activeTrackerUrl.search = new URLSearchParams({ token, return: `${window.location.origin}${window.location.pathname}` }).toString();
  return activeTrackerUrl;
};

export const getBatchTrackerBaseUrl = (configuredBaseUrl = '') => {
  if (import.meta.env.DEV && import.meta.env.VITE_PROJECT_PATH) {
    return new URL(`${import.meta.env.VITE_PROJECT_PATH}/batch_tracker`, window.location.origin);
  }
  if (configuredBaseUrl) return new URL(configuredBaseUrl);
  const apiUrl = new URL(API_ENDPOINT, window.location.href);
  apiUrl.pathname = apiUrl.pathname.replace(/mobile-api\.php$/i, 'batch_tracker');
  apiUrl.search = '';
  return apiUrl;
};
