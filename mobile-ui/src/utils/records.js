export const parseDocumentPages = (value) => {
  if (Array.isArray(value)) return value;
  try {
    const parsed = JSON.parse(value || '[]');
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
};

export const todayLocalDate = () => {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

export const formatBorrowedDateTime = (value) => {
  const match = /^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):\d{2}$/.exec(value || '');
  if (!match) return value || '';
  const hour = Number(match[2]);
  return `${match[1]} ${hour % 12 || 12}:${match[3]} ${hour < 12 ? 'AM' : 'PM'}`;
};

export const isValidContactNumber = (value) => /^[0-9]{11}$/.test(value);
export const contactNumberDigits = (value) => value.replace(/\D/g, '').slice(0, 11);
