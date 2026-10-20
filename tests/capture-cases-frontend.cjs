/* Capture-case UX against mocked REST responses; no business writes leave this test. */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const credentials = JSON.parse(fs.readFileSync(process.env.LIMU_TEST_LOGIN, 'utf8'));
  const base = process.env.LIMU_TEST_URL || 'http://127.0.0.1:8090';
  assert.equal(new URL(base).hostname, '127.0.0.1');
  const browser = await chromium.launch({ headless: true, executablePath: process.env.LIMU_CHROMIUM || '/usr/bin/chromium', args: ['--no-sandbox'] });
  const page = await browser.newPage({ locale: 'he-IL', viewport: { width: 1440, height: 1000 } });
  const hostile = '<img src=x onerror="window.__captureXss=true">';
  const checks = [], errors = [];
  let mode = 'normal', member = false, lastQuery, lastWrite, writes = 0, extraRows = [];
  const source = { date: '2026-09-15 12:00:00', phone: '0590000001', email: 'case@example.test', source_kind: 'ajax', institution: 1, last_attempt: '2026-10-07 12:00:00', attempts: 2, closed_at: '', note: '' };
  const unmappedLead = { id: 91, ...source, name: 'פנייה לשיוך', state: 'unmapped', institution: 0, form: 'טופס אתר', capture_cid: hostile, capture_issue: 'ambiguous_cid', origin_live: true, duplicate_of: 0, bill: 0, notes: [], treatment: 'new' };
  let rows = [
    { ...source, id: 201, name: hostile, state: 'review', reason: 'approved_month', reason_label: 'חודש החיוב כבר אושר', source_kind: 'native', institution: 0, institutions: [1, 2] },
    { ...source, id: 202, name: 'Tom & Jerry', state: 'retry', reason: 'temporary', reason_label: 'תקלה זמנית במסד הנתונים' },
    { ...source, id: 203, name: 'בירור סגור', state: 'closed', reason: 'identity_conflict', reason_label: 'פרטי קשר סותרים', closed_at: '2026-10-07 13:00:00', note: hostile },
    { ...source, id: 204, name: 'פנייה לשיוך', state: 'retry', reason: 'temporary', reason_label: 'ממתינה לבדיקה', institution: 0 }
  ];
  const bootstrap = { manager: true, today: '2026-10-07', month: '2026-10', settings: { start_date: '', automatic: false }, institutions: [{ id: 1, name: 'מוסד לדוגמה', agreement: { credit_days: 30, rates: [{ from: '2026-01-01', price: 1000, vat_bp: 1800 }] } }, { id: 2, name: 'נמען נוסף', agreement: { credit_days: 30, rates: [{ from: '2026-01-01', price: 1000, vat_bp: 1800 }] } }] };
  const json = (route, body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  async function check(condition, label) {
    assert.ok(condition, label);
    checks.push(label);
    console.log('PASS', label);
  }
  async function ready(title = 'טיפול בקליטה') {
    await page.getByRole('heading', { name: title, exact: true }).waitFor();
    await page.waitForFunction(() => document.getElementById('screen').getAttribute('aria-busy') === 'false');
  }
  async function chooseState(state) {
    await page.locator('#capture-state').selectOption(state);
    await page.getByRole('button', { name: 'הצגת פניות', exact: true }).click();
    await ready();
  }
  async function rowAction(id, action) {
    await page.locator(`[data-capture-id="${id}"][data-capture-action="${action}"]`).click();
    await page.locator('#capture-case-form').waitFor();
  }
  async function completedMessage(fragment) {
    await page.waitForFunction(text => !document.getElementById('modal').open && document.getElementById('screen').getAttribute('aria-busy') === 'false' && document.getElementById('notification').textContent.includes(text), fragment);
  }
  async function confirmAndRefresh(buttonName, message) {
    const refreshed = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/capture-cases') && response.request().method() === 'GET');
    await page.getByRole('button', { name: buttonName, exact: true }).click();
    await refreshed;
    await completedMessage(message);
  }
  page.on('pageerror', error => errors.push(error.message));
  try {
    await page.route(/\/assets\/(?:build\/)?crm(?:\.[a-f0-9]{12})?\.min\.js(?:\?|$)/, route => route.fulfill({ path: path.resolve(__dirname, '../limu-crm/assets/crm.js'), contentType: 'application/javascript' }));
    await page.route(/\/assets\/(?:build\/)?crm(?:\.[a-f0-9]{12})?\.min\.css(?:\?|$)/, route => route.fulfill({ path: path.resolve(__dirname, '../limu-crm/assets/crm.css'), contentType: 'text/css' }));
    await page.route('**/limu-crm/v1/**', async route => {
      const url = new URL(route.request().url()), endpoint = url.pathname.split('/').at(-1);
      if (endpoint === 'capture-case' && route.request().method() === 'POST') {
        assert.ok(route.request().headers()['x-wp-nonce'], 'Manual case decisions include the REST nonce');
        lastWrite = route.request().postDataJSON();
        writes++;
        assert.deepEqual(Object.keys(lastWrite).sort(), lastWrite.action === 'close' ? ['action', 'id', 'note'] : ['action', 'id']);
        if (mode === 'write-error') return json(route, { message: 'החודש עדיין מאושר. הפנייה ממתינה לטיפול.' }, 409);
        if (mode === 'write-malformed') return json(route, { saved: true });
        const item = rows.find(row => row.id === lastWrite.id);
        assert.ok(item, 'Only an existing capture case is changed');
        if (mode === 'write-inactive') {
          rows = rows.filter(row => row !== item);
          return json(route, { item: null, completed: false, unmapped_id: 0 });
        }
        if (lastWrite.action === 'close') {
          item.state = 'closed';
          item.note = lastWrite.note;
          item.closed_at = '2026-10-07 14:00:00';
        } else if (lastWrite.id === 202 || lastWrite.id === 204) {
          rows = rows.filter(row => row !== item);
          return json(route, { item: null, completed: true, unmapped_id: lastWrite.id === 204 ? 91 : 0 });
        } else {
          item.state = 'review';
          item.attempts++;
        }
        return json(route, { item, completed: false, unmapped_id: 0 });
      }
      assert.equal(route.request().method(), 'GET', 'Other endpoints remain read-only');
      if (endpoint === 'bootstrap') return json(route, { ...bootstrap, manager: !member });
      if (endpoint === 'summary') return json(route, { received_leads: 3, institution_leads: 2, institution_live: 2, institution_historical: 0, capture_pending: 3, approved: 0, paid: 0, overdue: 0, duplicates: 0, unmapped: 0, pending: 0, draft: 0, by_institution: {} });
      if (endpoint === 'deliveries') { lastQuery = url.searchParams; const selected = rows.some(row => row.id === 204) ? [] : [unmappedLead]; return json(route, { items: selected, total: selected.length, pages: selected.length ? 1 : 0 }); }
      assert.equal(endpoint, 'capture-cases', 'Only the manager capture-list endpoint is read');
      lastQuery = url.searchParams;
      if (member) return json(route, { message: 'אין הרשאה' }, 403);
      if (mode === 'list-error') return json(route, { message: 'הרשימה אינה זמינה כרגע' }, 503);
      if (mode === 'list-malformed') return json(route, { items: null });
      const state = lastQuery.get('state'), pageNumber = Number(lastQuery.get('page'));
      const selected = [...rows, ...extraRows].filter(row => state === 'active' ? row.state !== 'closed' : row.state === state);
      return json(route, { items: selected.slice((pageNumber - 1) * 30, pageNumber * 30), total: selected.length, pages: Math.ceil(selected.length / 30), page: pageNumber });
    });
    await page.goto(base + '/crm/');
    if (await page.locator('#user_login').count()) {
      if (await page.locator('#privacy-ack').isVisible()) await page.locator('#privacy-ack').click();
      await page.locator('#user_login').fill(credentials.user);
      await page.locator('#user_pass').fill(credentials.password);
      await page.getByRole('button', { name: 'כניסה למערכת', exact: true }).click();
    }
    await ready('סקירה כללית');
    await check(!(await page.locator('#capture-retry-alert').textContent()).includes('מדי שעה') && (await page.locator('#capture-retry-alert').textContent()).includes('החלטת מנהל'), 'Dashboard makes no blanket promise that every pending case will be fixed by hourly retry');
    await page.locator('#open-capture-cases').click();
    await ready();
    await check(lastQuery.get('state') === 'active' && lastQuery.get('page') === '1' && await page.locator('[data-capture-row]').count() === 3, 'Dashboard opens a global bounded list of active capture cases');
    await check(await page.locator('[data-capture-row="201"]').isVisible() && (await page.locator('[data-capture-row="201"]').textContent()).includes('חודש החיוב כבר אושר') && (await page.locator('[data-capture-row="202"]').textContent()).includes('תקלה זמנית') && (await page.locator('#screen').textContent()).includes('יותר משורה אחת'), 'List separates a blocked approved month from a temporary failure and explains per-record versus dashboard source counts');
    await check((await page.locator('[data-capture-row="201"]').textContent()).includes('מוסד לדוגמה · נמען נוסף') && !(await page.locator('[data-capture-row="201"]').textContent()).includes('ללא שיוך'), 'A native case retains all known recipients rather than falsely reporting a shared source as unassigned');
    await check((await page.locator('#screen').textContent()).includes(hostile) && (await page.locator('#screen').textContent()).includes('Tom & Jerry') && await page.locator('#screen img').count() === 0 && await page.evaluate(() => !window.__captureXss), 'Case contacts and reasons preserve literal text without executing hostile markup');
    extraRows = Array.from({ length: 30 }, (_, index) => ({ ...rows[1], id: 1000 + index, name: 'המשך רשימת פניות' }));
    await chooseState('active');
    await page.locator('#next-page').click(); await ready();
    await check(lastQuery.get('page') === '2' && await page.locator('[data-capture-row]').count() === 3, 'Next page requests only its bounded page rather than loading the whole capture queue');
    await chooseState('review');
    await check(lastQuery.get('page') === '1' && lastQuery.get('state') === 'review' && await page.locator('[data-capture-row]').count() === 1, 'Changing case status resets pagination and displays only the requested status');
    extraRows = [];
    await rowAction(201, 'retry');
    await check((await page.locator('#modal').textContent()).includes(hostile) && await page.locator('#modal img').count() === 0 && (await page.locator('#modal').textContent()).includes('חיובים מאושרים'), 'Manual retry confirmation escapes identities and explains that approved financial data stays protected');
    mode = 'write-error'; await page.getByRole('button', { name: 'אישור ניסיון נוסף', exact: true }).click();
    await page.locator('#capture-case-form .form-error').filter({ hasText: 'החודש עדיין מאושר' }).waitFor();
    await check(await page.locator('#capture-case-form').isVisible() && rows[0].state === 'review' && !(await page.locator('#notification').textContent()).includes('נקלטה במערכת'), 'A rejected retry leaves the decision open and does not claim successful capture');
    mode = 'write-malformed'; await page.getByRole('button', { name: 'אישור ניסיון נוסף', exact: true }).click();
    await page.locator('#capture-case-form .form-error').filter({ hasText: 'לא ניתן לאמת' }).waitFor();
    await check(await page.locator('#capture-case-form').isVisible() && !(await page.locator('#notification').textContent()).includes('נקלטה במערכת'), 'Malformed decision responses cannot produce an undefined-length error or a false completed message');
    mode = 'normal'; await confirmAndRefresh('אישור ניסיון נוסף', 'עדיין דורשת החלטת מנהל');
    await check(lastWrite.id === 201 && lastWrite.action === 'retry' && rows[0].state === 'review', 'An attempted retry that still needs manager judgment remains visible as a review case');
    await rowAction(201, 'close');
    await check((await page.locator('#modal').textContent()).includes('לא תירשם כפנייה שנקלטה') && (await page.locator('#modal').textContent()).includes('לא יבוצע שינוי בחיובים'), 'Closing a case is an explicit manual decision that retains evidence and does not pretend the lead was captured or change a bill');
    const writesBefore = writes;
    await page.locator('#capture-note').fill('   ');
    await page.getByRole('button', { name: 'אישור סגירת הבירור', exact: true }).click();
    await page.locator('#capture-case-form .form-error').filter({ hasText: 'יש לציין סיכום טיפול' }).waitFor();
    await check(writes === writesBefore && rows[0].state === 'review', 'Whitespace-only manual treatment cannot close or remove a case');
    await page.locator('#capture-note').fill('א'.repeat(700));
    await page.getByRole('button', { name: 'אישור סגירת הבירור', exact: true }).click();
    await page.locator('#capture-case-form .form-error').filter({ hasText: 'ארוך מדי' }).waitFor();
    await check(writes === writesBefore, 'A UTF-8 treatment note that exceeds the server limit is rejected before sending a write');
    await page.locator('#capture-note').fill('הטיפול הושלם מחוץ למערכת והמקור נשמר לביקורת.');
    await confirmAndRefresh('אישור סגירת הבירור', 'הבירור נסגר לאחר טיפול ידני');
    await page.locator('[data-capture-row="201"]').waitFor({ state: 'detached' });
    await check(lastWrite.action === 'close' && lastWrite.note.includes('המקור נשמר') && rows[0].state === 'closed' && !(await page.locator('#notification').textContent()).includes('נקלטה במערכת'), 'A confirmed manual close sends only its note and retains the case in the archive without claiming capture');
    await chooseState('closed');
    await check(await page.locator('[data-capture-row="201"]').isVisible() && await page.locator('[data-capture-row="203"]').isVisible() && await page.locator('[data-capture-action="close"]').count() === 0, 'Closed cases remain reviewable and offer reopening rather than another close');
    await rowAction(203, 'reopen');
    await check((await page.locator('#modal').textContent()).includes(hostile) && await page.locator('#modal img').count() === 0, 'A previous manual treatment note is escaped when reopening a case');
    await confirmAndRefresh('אישור פתיחה וניסיון נוסף', 'עדיין דורשת החלטת מנהל');
    await page.locator('[data-capture-row="203"]').waitFor({ state: 'detached' });
    await check(lastWrite.action === 'reopen' && rows.find(row => row.id === 203).state === 'review', 'Reopening a closed conflict preserves its immutable source and reports the remaining manager decision');
    await chooseState('review'); await rowAction(203, 'retry'); mode = 'write-inactive';
    await confirmAndRefresh('אישור ניסיון נוסף', 'ללא אישור קליטה');
    await page.locator('[data-capture-row="203"]').waitFor({ state: 'detached' });
    await check(!(await page.locator('#notification').textContent()).includes('נקלטה במערכת'), 'A source that became inactive without verified capture receives a neutral outcome rather than a false captured claim');
    mode = 'normal';
    await chooseState('retry'); await rowAction(202, 'retry');
    await confirmAndRefresh('אישור ניסיון נוסף', 'הפנייה נקלטה במערכת');
    await page.locator('[data-capture-row="202"]').waitFor({ state: 'detached' });
    await check(!rows.some(row => row.id === 202), 'Successful capture removes the active case only after the server confirms completion');
    await rowAction(204, 'retry'); await confirmAndRefresh('אישור ניסיון נוסף', 'דורשת שיוך למוסד');
    await page.locator('[data-capture-row="204"]').waitFor({ state: 'detached' });
    await page.locator('#capture-unmapped').click(); await ready('מרכז לידים');
    await check(lastQuery.get('state') === 'unmapped', 'An accepted dispatch with unresolved institution leads the manager to the existing assignment workflow');
    await page.locator('[data-detail="91"]').click();
    await check((await page.locator('#modal').textContent()).includes(hostile) && (await page.locator('#modal').textContent()).includes('משויך לכמה מוסדות') && await page.locator('#modal img').count() === 0, 'Manager lead details show the frozen recipient CID and a friendly ambiguity reason with escaped text');
    await page.keyboard.press('Escape'); await page.locator('[data-remap="91"]').click();
    await check((await page.locator('#modal').textContent()).includes(hostile) && (await page.locator('#modal').textContent()).includes('מזהה הנמען במקור') && await page.locator('#modal img').count() === 0, 'Existing assignment confirmation includes the original recipient evidence instead of requiring a guessed institution');
    await page.keyboard.press('Escape');
    mode = 'list-malformed'; await page.locator('[data-view="capture"]').click(); await ready('לא ניתן לטעון את הנתונים');
    await check((await page.locator('#load-error').textContent()).includes('לא ניתן לאמת'), 'Malformed list responses render a recoverable error without exposing broken record content');
    mode = 'normal'; await page.locator('#retry').click(); await ready();
    member = true; await page.reload(); await ready('סקירה כללית');
    await check(await page.locator('#capture-retry-alert,#capture-pending-count').count() === 0, 'Institution bootstrap cannot show a global capture count even if a response contains it');
    await page.locator('[data-view="capture"]').click(); await ready('לא ניתן לטעון את הנתונים');
    await check((await page.locator('#load-error').textContent()).includes('אין הרשאה') && await page.locator('[data-capture-row],#capture-case-form').count() === 0, 'A stale manager navigation entry cannot display case contacts or decisions to a read-only institution bootstrap');
    await page.locator('[data-view="deliveries"]').click(); await ready('מרכז לידים'); await page.locator('[data-detail="91"]').click();
    await check(!(await page.locator('#modal').textContent()).includes(hostile) && !(await page.locator('#modal').textContent()).includes('מזהה הנמען במקור'), 'Read-only institution details omit private capture provenance even if a mocked response incorrectly includes it');
    await check(errors.length === 0 && await page.evaluate(() => !window.__captureXss), 'Capture-case workflow produces no browser errors or executable injected markup');
    console.log(`${checks.length} capture-case frontend checks passed.`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.message); process.exit(1); });
