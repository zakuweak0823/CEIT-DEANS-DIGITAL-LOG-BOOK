// Generates database/dummy_data.sql with sample rows that follow the rules of the DIT Logbook.
// Usage: node database/generate_dummy_data.js
// Dates are written relative to NOW()/CURDATE(), so the data always looks recent on import.
//
// Rules mirrored from the system:
// - Incoming Documents only holds Pending documents. Once released, a document leaves Incoming and
//   appears in Outgoing as "For Release", then "Released".
// - Overdue = Incoming Pending older than the Incoming limit (3 days) or Outgoing For Release older
//   than the Outgoing limit (2 days). Released documents are never overdue.
// - Quick Submit (mobile) documents are Incoming, addressed to the Dean's Office, and write a mobile log.
// - Bulk submissions live only in document_batches and move Pending -> For Release -> Released.
//   A batch cannot leave Pending before staff confirm the received count.
// - SMS logs only exist for outgoing documents that have a contact number (ready-for-release and
//   released notices). Statuses are Delivered, Failed or Pending; PhilSMS is the provider.
// - Borrowed items are Borrowed or Returned. The app does not set a due date.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const COUNTS = {
    incomingPendingRecent: 20,
    incomingPendingOverdue: 8,
    forReleaseRecent: 9,
    forReleaseOverdue: 5,
    released: 20,
    batches: 10,
    borrowedItems: 25,
    mobileLogs: 12,
};
const DAY = 24 * 60;

// Seeded PRNG so re-running produces the same names/values (tokens stay random and unique).
let seed = 20261022;
const rand = () => {
    seed = (seed * 1664525 + 1013904223) % 4294967296;
    return seed / 4294967296;
};
const int = (min, max) => Math.floor(rand() * (max - min + 1)) + min;
const pick = (list) => list[Math.floor(rand() * list.length)];
const chance = (p) => rand() < p;
const token = () => crypto.randomBytes(32).toString('hex');
const q = (value) => value === null ? 'NULL' : `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
const phone = () => '09' + String(int(0, 999999999)).padStart(9, '0');
const providerPhone = (value) => '63' + value.slice(1);

const firstNames = ['Juan', 'Maria', 'Jose', 'Ana', 'Mark', 'Kristine', 'John Paul', 'Angelica', 'Carlo', 'Patricia', 'Miguel', 'Camille', 'Rafael', 'Jasmine', 'Paolo', 'Nicole', 'Christian', 'Bea', 'Joshua', 'Danica', 'Kevin', 'Andrea', 'Ryan', 'Sofia', 'Gabriel', 'Erika', 'Adrian', 'Louise', 'Vincent', 'Trisha'];
const lastNames = ['Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Villanueva', 'Ramos', 'Aquino', 'Castillo', 'Flores', 'Gonzales', 'Navarro', 'Torres', 'Lopez', 'Rivera', 'Domingo', 'Mercado', 'Pascual', 'Soriano', 'Jayson', 'Fernandez', 'Morales', 'Salazar'];
const faculty = ['Prof. Ramon Aquino', 'Dr. Elena Santos', 'Engr. Paolo Reyes', 'Prof. Lourdes Garcia', 'Dr. Manuel Castillo', 'Prof. Teresa Mendoza', 'Engr. Victor Bautista', 'Dr. Grace Villanueva'];
const offices = ['Registrar', 'Accounting Office', 'Library', 'Guidance Office', 'Student Affairs', 'IT Department', 'HR Office', 'Admissions Office', 'Research Office'];
const person = () => `${pick(firstNames)} ${pick(lastNames)}`;

const incomingSubjects = ['Request for Transcript of Records', 'Application for Leave of Absence', 'Request for Certificate of Enrollment', 'Thesis Proposal Submission', 'Shifting Application Form', 'Request for Good Moral Certificate', 'Adding/Dropping of Subjects', 'Scholarship Application', 'Request for Completion of Grade', 'OJT Endorsement Letter Request', 'Petition for Special Class', 'Request for Room Reservation', 'Faculty Leave Application', 'Purchase Request for Lab Equipment', 'Research Grant Proposal', 'Seminar Attendance Request', 'Request for Clearance Signature', 'Excuse Letter', 'Cross-Enrollment Application', 'Request for Course Syllabus'];
const outgoingSubjects = ['Approved Leave Application', 'Signed Clearance', 'Certificate of Enrollment', 'Endorsement Letter', 'Approved Shifting Form', 'Memorandum on Class Schedule', 'Notice of Approval', 'Signed OJT Endorsement', 'Certificate of Good Moral Character', 'Approved Purchase Request', 'Signed Thesis Approval Sheet', 'Notice of Meeting', 'Approved Room Reservation', 'Certified True Copy of Grades'];
const remarksList = [null, null, null, 'Needs signature of the Dean', 'Complete requirements attached', 'Follow-up on previous request', 'Urgent - deadline this week', 'Photocopy only', 'Original copy submitted', 'Submitted on behalf of student', 'For verification'];
const batchTypes = ['Clearance Forms', 'Enrollment Forms', 'Grade Sheets', 'OJT Requirements', 'Thesis Documents', 'Scholarship Requirements', 'Leave Applications', 'Purchase Requests', 'Research Papers', 'Class Records'];

const categories = {
    'Laptops': ['Lenovo ThinkPad', 'Dell Latitude', 'HP ProBook', 'Acer Aspire'],
    'Projectors': ['Epson Projector', 'BenQ Projector', 'ViewSonic Projector'],
    'Cables & Adapters': ['HDMI Cable', 'VGA Cable', 'USB-C Adapter', 'Extension Cord', 'LAN Cable'],
    'Audio Equipment': ['Wireless Microphone', 'Portable Speaker', 'Headset', 'Microphone Stand'],
    'Cameras': ['Canon DSLR', 'Webcam', 'Tripod', 'Action Camera'],
    'Networking': ['Network Switch', 'Wi-Fi Router', 'Crimping Tool', 'LAN Tester'],
    'Office Supplies': ['Stapler', 'Puncher', 'Laminator', 'Paper Cutter'],
    'Lab Kits': ['Arduino Kit', 'Raspberry Pi Kit', 'Breadboard Set', 'Multimeter'],
    'Books': ['Data Structures Textbook', 'Networking Reference', 'Thesis Writing Guide'],
    'Keys': ['Lab Room Key', 'Faculty Room Key', 'Storage Room Key'],
};

const out = [];
const push = (line) => out.push(line);

push('-- DIT Logbook dummy data. Generated by database/generate_dummy_data.js');
push('-- Import AFTER database/schema.sql. Safe to import into a database that already has data.');
push('USE dit_logbook;');
push('');
push('-- Columns the app adds at runtime; added here so the import works on a fresh schema.sql database.');
push("ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS submitter_name VARCHAR(255) NOT NULL DEFAULT '';");
push('ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS document_pages TEXT DEFAULT NULL;');
push("ALTER TABLE sms_logs ADD COLUMN IF NOT EXISTS provider_name VARCHAR(32) NOT NULL DEFAULT 'Semaphore';");
push('');
push('-- OPTIONAL: to replace data from an earlier dummy import, remove the leading "-- " on the next line first.');
push('-- SET FOREIGN_KEY_CHECKS = 0; TRUNCATE TABLE batch_papers; TRUNCATE TABLE document_batches; TRUNCATE TABLE mobile_submission_logs; TRUNCATE TABLE documents; TRUNCATE TABLE borrowed_items; TRUNCATE TABLE sms_logs; SET FOREIGN_KEY_CHECKS = 1;');
push('');
push('START TRANSACTION;');
push('');

// ---- item_categories ----
push('-- Item categories');
push('INSERT IGNORE INTO item_categories (category_name, created_at) VALUES');
push(Object.keys(categories).map((name, i) => `(${q(name)}, NOW() - INTERVAL ${200 - i} DAY)`).join(',\n') + ';');
push('');

// ---- borrowed_items ----
push('-- Borrowed items');
const itemRows = [];
for (let i = 0; i < COUNTS.borrowedItems; i++) {
    const category = pick(Object.keys(categories));
    const item = `${pick(categories[category])} #${int(1, 12)}`;
    const borrower = chance(0.7) ? person() : pick(faculty);
    const borrowedDaysAgo = int(0, 45);
    const returned = borrowedDaysAgo > 2 && chance(0.65);
    const returnedDaysAgo = returned ? int(0, borrowedDaysAgo - 1) : null;
    itemRows.push(`(${q(item)}, (SELECT category_id FROM item_categories WHERE category_name = ${q(category)}), ${q(borrower)}, ${chance(0.85) ? q(phone()) : 'NULL'}, `
        + `NULL, ${returned ? `CURDATE() - INTERVAL ${returnedDaysAgo} DAY` : 'NULL'}, `
        + `${q(returned ? 'Returned' : 'Borrowed')}, NOW() - INTERVAL ${borrowedDaysAgo} DAY - INTERVAL ${int(0, 600)} MINUTE)`);
}
push('INSERT INTO borrowed_items (item_name, category_id, borrower, borrower_phone, due_date, returned_date, status, date_borrowed) VALUES');
push(itemRows.join(',\n') + ';');
push('');

// ---- documents ----
// Each entry is one row of the documents table. "converted" outgoing documents started as an
// incoming document that staff moved to Outgoing, so their sender/recipient are reversed.
const docs = [];
const makeDoc = (kind, status, minutesAgo) => {
    const submitterType = pick(['Student', 'Student', 'Student', 'Faculty', 'Other']);
    const submitterName = submitterType === 'Faculty' ? pick(faculty) : person();
    const incomingSender = submitterType === 'Other' ? pick(offices) : submitterType;
    const entrySource = kind === 'Incoming' ? (chance(0.35) ? 'quick_submit' : 'standard') : (chance(0.2) ? 'quick_submit' : 'standard');
    const converted = kind === 'Outgoing' && (entrySource === 'quick_submit' || chance(0.6));
    let sender;
    let recipient;
    let subject;
    if (kind === 'Incoming') {
        sender = incomingSender;
        recipient = entrySource === 'quick_submit' ? "Dean's Office" : '';
        subject = pick(incomingSubjects);
    } else if (converted) {
        sender = "Dean's Office";
        recipient = incomingSender;
        subject = pick(incomingSubjects);
    } else {
        sender = chance(0.7) ? "Dean's Office" : pick(offices);
        recipient = submitterName;
        subject = pick(outgoingSubjects);
    }
    docs.push({
        kind, status, minutesAgo, submitterType, submitterName, sender, recipient, subject, entrySource,
        incomingSender,
        tracking: token(),
        hasToken: chance(0.8),
        phone: chance(kind === 'Outgoing' ? 0.88 : 0.7) ? phone() : null,
        priority: chance(0.12) ? 1 : 0,
        remarks: pick(remarksList),
    });
};

for (let i = 0; i < COUNTS.incomingPendingRecent; i++) makeDoc('Incoming', 'Pending', int(0, 3 * DAY - 120));
for (let i = 0; i < COUNTS.incomingPendingOverdue; i++) makeDoc('Incoming', 'Pending', int(4 * DAY, 40 * DAY));
for (let i = 0; i < COUNTS.forReleaseRecent; i++) makeDoc('Outgoing', 'For Release', int(0, 2 * DAY - 120));
for (let i = 0; i < COUNTS.forReleaseOverdue; i++) makeDoc('Outgoing', 'For Release', int(3 * DAY, 12 * DAY));
for (let i = 0; i < COUNTS.released; i++) makeDoc('Outgoing', 'Released', int(3 * DAY, 44 * DAY));

push('-- Documents: Incoming = Pending only; Outgoing = For Release or Released');
const docRows = docs.map((d) => `(${q(d.kind)}, ${q(d.sender)}, ${q(d.submitterName)}, ${d.hasToken ? q(d.tracking) : 'NULL'}, ${q(d.recipient)}, ${d.phone ? q(d.phone) : 'NULL'}, `
    + `${q(d.submitterType)}, ${d.priority}, ${q(d.subject)}, ${q(d.remarks)}, ${q(d.status)}, NOW() - INTERVAL ${d.minutesAgo} MINUTE, ${q(d.entrySource)})`);
push('INSERT INTO documents (type, sender, submitter_name, tracking_token, recipient, client_phone, submitter_type, is_priority, subject, remarks, status, date_created, entry_source) VALUES');
push(docRows.join(',\n') + ';');
push('');

// ---- mobile_submission_logs (Quick Submit documents) ----
push('-- Mobile submission logs (one per Quick Submit document)');
const mobileDocs = docs.filter((d) => d.entrySource === 'quick_submit' && d.hasToken).slice(0, COUNTS.mobileLogs);
for (const d of mobileDocs) {
    push(`INSERT INTO mobile_submission_logs (document_id, submission_type, sender, subject, recipient, submitted_at) `
        + `SELECT doc_id, 'Incoming', ${q(d.incomingSender)}, ${q(d.subject)}, ${q("Dean's Office")}, date_created FROM documents WHERE tracking_token = ${q(d.tracking)};`);
}
push('');

// ---- document_batches (Bulk Documents) ----
// The app stores a bulk submission as a single batch row; its documents are not added to the
// Incoming/Outgoing lists, so none are created here.
push('-- Bulk submissions');
const usedCodes = new Set();
for (let i = 0; i < COUNTS.batches; i++) {
    const daysAgo = i < 3 ? int(0, 2) : int(1, 30);
    const date = new Date();
    date.setDate(date.getDate() - daysAgo);
    const ymd = date.toISOString().slice(0, 10).replace(/-/g, '');
    let code;
    do { code = `BP-${ymd}-${crypto.randomBytes(3).toString('hex').toUpperCase()}`; } while (usedCodes.has(code));
    usedCodes.add(code);

    const documentType = chance(0.65) ? 'Incoming' : 'Outgoing';
    const senderType = chance(0.7) ? 'Student' : 'Faculty';
    const submitterName = senderType === 'Faculty' ? pick(faculty) : person();
    const batchType = pick(batchTypes);
    const paperCount = int(2, 8);
    const status = daysAgo <= 2 ? 'Pending' : pick(['Pending', 'For Release', 'Released', 'Released']);
    // Staff must confirm the received count before a batch can leave Pending.
    const received = status !== 'Pending' || chance(0.4);
    const receivedCount = received && chance(0.15) ? paperCount - 1 : paperCount;
    const priority = chance(0.15) ? 1 : 0;
    const pages = JSON.stringify(Array.from({ length: paperCount }, () => int(1, 25)));
    const hasContact = chance(0.4);
    const createdMinutes = daysAgo * DAY + 400;
    const notes = !received ? null : (receivedCount !== paperCount ? q('One document was missing; the sender will submit it later') : (chance(0.3) ? q('All documents checked and complete') : "''"));

    push(`INSERT INTO document_batches (batch_code, document_type, batch_type, status, batch_date, sender, sender_type, submitter_name, recipient, recipient_contact_type, remarks, tracking_token, paper_count, document_pages, is_priority, received_paper_count, intake_expected_count, intake_notes, received_at, received_by, created_at) VALUES `
        + `(${q(code)}, ${q(documentType)}, ${q(batchType)}, ${q(status)}, CURDATE() - INTERVAL ${daysAgo} DAY, ${q(senderType)}, ${q(senderType)}, ${q(submitterName)}, `
        + `${q(hasContact ? phone() : '')}, ${q(hasContact ? 'Contact' : 'Unassigned')}, ${q(pick(remarksList))}, ${q(token())}, ${paperCount}, ${q(pages)}, ${priority}, `
        + `${received ? receivedCount : 'NULL'}, ${received ? paperCount : 'NULL'}, ${notes === null ? 'NULL' : notes}, `
        + `${received ? `NOW() - INTERVAL ${createdMinutes - int(30, 300)} MINUTE` : 'NULL'}, ${q(received ? 'admin' : '')}, NOW() - INTERVAL ${createdMinutes} MINUTE);`);
}
push('');

// ---- sms_logs (one notice per outgoing document that has a contact number) ----
push('-- SMS logs: ready-for-release and released notices for outgoing documents');
const smsRows = [];
const addSms = (recipientPhone, text, agoMinutes) => {
    const status = agoMinutes < 120 && chance(0.5) ? 'Pending' : (chance(0.08) ? 'Failed' : 'Delivered');
    const hasProviderId = status !== 'Failed';
    smsRows.push(`(${q(providerPhone(recipientPhone))}, ${q(text)}, ${q(status)}, ${hasProviderId ? q(crypto.randomBytes(12).toString('hex')) : 'NULL'}, `
        + `${status === 'Delivered' ? `NOW() - INTERVAL ${Math.max(0, agoMinutes - 3)} MINUTE` : 'NULL'}, 'PhilSMS', NOW() - INTERVAL ${agoMinutes} MINUTE)`);
};
for (const d of docs) {
    if (d.kind !== 'Outgoing' || !d.phone) continue;
    const readyAgo = Math.max(0, d.minutesAgo - int(120, DAY));
    if (chance(d.status === 'Released' ? 0.7 : 0.55)) {
        addSms(d.phone, `CvSU Dean's Office: Your document '${d.subject}' is ready for release. Please visit the office to claim it.`, readyAgo);
    }
    if (d.status === 'Released' && chance(0.6)) {
        addSms(d.phone, `CvSU Dean's Office: The document '${d.subject}' has been released. Please contact the office if you need assistance.`, Math.max(0, readyAgo - int(60, 2 * DAY)));
    }
}
push('INSERT INTO sms_logs (recipient, message, status, provider_message_id, provider_status_checked_at, provider_name, date_sent) VALUES');
push(smsRows.join(',\n') + ';');
push('');
push('COMMIT;');

const target = path.join(__dirname, 'dummy_data.sql');
fs.writeFileSync(target, out.join('\n') + '\n');
console.log(`Wrote ${target} (${docs.length} documents, ${smsRows.length} SMS logs)`);
