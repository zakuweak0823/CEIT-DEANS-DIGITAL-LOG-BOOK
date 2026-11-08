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

export const isValidContactNumber = (value) => /^[0-9]{11}$/.test(value);
export const contactNumberDigits = (value) => value.replace(/\D/g, '').slice(0, 11);
