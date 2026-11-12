const pptxgen = require('pptxgenjs');
const path = require('path');
const { applyTheme } = require(process.env.SKILL_DIR + '/scripts/apply_theme.js');

const OUT = process.argv[2];
const SHOTS = path.join(OUT, 'screenshots');
const shot = (n) => path.join(SHOTS, n);

const THEME = {
  name: 'Projects',
  headFontFace: 'Calibri',
  bodyFontFace: 'Calibri',
  colors: {
    dk1: '0E1B3D', lt1: 'FFFFFF', dk2: '4A5568', lt2: 'EEF2F8',
    accent1: '0B5CD5', accent2: 'E07B2E', accent3: '7FB0FF', accent4: 'B4581A',
    accent5: '18295A', accent6: 'DCE8FF', hlink: '0B5CD5', folHlink: '18295A',
  },
};

function newDeck(title) {
  const pres = new pptxgen();
  pres.layout = 'LAYOUT_WIDE'; // 13.333 x 7.5
  pres.title = title;
  pres.author = 'Miles Global Softwares';
  pres.company = 'Miles Global Softwares';
  pres.theme = { headFontFace: THEME.headFontFace, bodyFontFace: THEME.bodyFontFace };
  const C = pres.SchemeColor;

  pres.defineSlideMaster({
    title: 'DARK',
    background: { color: C.text1 },
    objects: [
      { placeholder: { options: { name: 'eyebrow', type: 'body', x: 0.8, y: 0.7, w: 11.7, h: 0.5, fontSize: 16, bold: true, color: C.accent3, charSpacing: 3, margin: 0 }, text: '' } },
      { placeholder: { options: { name: 'title', type: 'title', x: 0.8, y: 1.9, w: 10.6, h: 2.6, fontSize: 54, bold: true, align: 'left', color: C.background1, valign: 'top', margin: 0 }, text: '' } },
      { placeholder: { options: { name: 'body', type: 'body', x: 0.8, y: 4.6, w: 10.2, h: 1.4, fontSize: 22, color: C.accent6, valign: 'top', margin: 0 }, text: '' } },
    ],
  });
  pres.defineSlideMaster({
    title: 'CONTENT',
    background: { color: C.background2 },
    objects: [
      { placeholder: { options: { name: 'title', type: 'title', x: 0.6, y: 0.4, w: 12.1, h: 0.9, fontSize: 34, bold: true, align: 'left', color: C.text1, valign: 'middle', margin: 0 }, text: '' } },
    ],
    slideNumber: { x: 12.3, y: 6.95, w: 0.5, h: 0.3, fontSize: 10, color: '4A5568', align: 'right' },
  });
  pres.defineSlideMaster({
    title: 'CONTENT_DARK',
    background: { color: C.text1 },
    objects: [
      { placeholder: { options: { name: 'title', type: 'title', x: 0.6, y: 0.4, w: 12.1, h: 0.9, fontSize: 34, bold: true, align: 'left', color: C.background1, valign: 'middle', margin: 0 }, text: '' } },
    ],
    slideNumber: { x: 12.3, y: 6.95, w: 0.5, h: 0.3, fontSize: 10, color: '7FB0FF', align: 'right' },
  });
  return { pres, C };
}

function shadow() { return { type: 'outer', color: '0E1B3D', opacity: 0.12, blur: 8, offset: 2, angle: 90 }; }

function card(slide, C, { x, y, w, h, head, body, headColor, fill, bodyColor, name, hs, bs, pad }) {
  const p = pad === undefined ? 0.25 : pad;
  slide.addShape('roundRect', { x, y, w, h, rectRadius: 0.12, fill: { color: fill || C.background1 }, line: { color: 'D9E1EE', width: 0.75 }, shadow: shadow(), objectName: name ? name + ' card' : undefined });
  slide.addText([
    { text: head, options: { bold: true, fontSize: hs || 22, color: headColor || C.text1, breakLine: true } },
    { text: body, options: { fontSize: bs || 17, color: bodyColor || C.text2 } },
  ], { x: x + 0.3, y: y + p, w: w - 0.6, h: h - 2 * p, valign: 'top', paraSpaceAfter: 6, isTextBox: true, objectName: name });
}

function picture(slide, file, { x, y, w, h, alt }) {
  slide.addShape('rect', { x, y, w, h, fill: { color: 'FFFFFF' }, line: { color: 'C9D3E3', width: 0.75 }, shadow: shadow() });
  slide.addImage({ path: shot(file), x, y, w, h, altText: alt, objectName: alt });
}

function eyebrow(slide, C, text, y, color) {
  slide.addText(text.toUpperCase(), { x: 0.6, y, w: 4.4, h: 0.35, fontSize: 13, bold: true, color: color || C.accent1, charSpacing: 2, margin: 0, isTextBox: true });
}

function para(slide, C, text, { x, y, w, h, size, color }) {
  slide.addText(text, { x, y, w, h, fontSize: size || 17, color: color || C.text2, valign: 'top', margin: 0, paraSpaceAfter: 8, isTextBox: true });
}

// Screenshot slide: title on top, text column left, picture right.
function screenSlide(pres, C, section, { title, kicker, paras, file, alt, notes }) {
  const s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: section });
  s.addText(title, { placeholder: 'title' });
  eyebrow(s, C, kicker, 1.65);
  const runs = [];
  paras.forEach((p, i) => {
    const last = i === paras.length - 1;
    if (Array.isArray(p)) {
      runs.push({ text: p[0] + ' ', options: { bold: true, color: C.text1 } });
      runs.push({ text: p[1], options: { breakLine: !last } });
    } else runs.push({ text: p, options: { breakLine: !last } });
  });
  s.addText(runs, { x: 0.6, y: 2.1, w: 4.2, h: 4.4, fontSize: 17, color: C.text2, valign: 'top', margin: 0, paraSpaceAfter: 14, isTextBox: true });
  picture(s, file, { x: 5.25, y: 1.65, w: 7.45, h: 4.656, alt });
  if (notes) s.addNotes(notes);
  return s;
}

function table(slide, C, rows, { x, y, w, colW, fontSize, rowH }) {
  const head = rows[0].map((t) => ({ text: t, options: { bold: true, color: 'FFFFFF', fill: { color: '0B5CD5' } } }));
  const body = rows.slice(1).map((r, i) => r.map((t, j) => ({ text: t, options: { bold: j === 0, color: '0E1B3D', fill: { color: i % 2 ? 'F6F8FC' : 'FFFFFF' } } })));
  slide.addTable([head, ...body], { x, y, w, colW, rowH, fontSize: fontSize || 15, fontFace: 'Calibri', border: { type: 'solid', color: 'D9E1EE', pt: 0.75 }, margin: [0.06, 0.12, 0.06, 0.12], valign: 'middle' });
}

// ---------------------------------------------------------------- Presentation
async function presentation() {
  const { pres, C } = newDeck('Projects — Product Presentation');
  const S1 = 'What Projects is', S2 = 'Product tour', S3 = 'Working with donors', S4 = 'Roles and roadmap';

  pres.addSection({ title: S1 });
  let s = pres.addSlide({ masterName: 'DARK', sectionTitle: S1 });
  s.addText('TATOOK · PROJECTS', { placeholder: 'eyebrow' });
  s.addText('Run your organization. Share what you choose.', { placeholder: 'title' });
  s.addText('Projects, budgets, spending, grants and the books in one workspace, for NGOs and the donors who fund them.', { placeholder: 'body' });
  s.addText('Product presentation · Miles Global Softwares · November 2026', { x: 0.8, y: 6.5, w: 11.7, h: 0.45, fontSize: 14, color: '7FB0FF', margin: 0, isTextBox: true });
  s.addNotes('Projects is the project and grant management product on the Tatook platform. This deck walks through what it does, screen by screen, using a demo organization called Hope Water Trust.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S1 });
  s.addText('One workspace for the work, the money and the books', { placeholder: 'title' });
  para(s, C, 'And a shared record of each grant with whoever funds it. An organization can also use it with no donor at all.', { x: 0.6, y: 1.5, w: 11, h: 0.8, size: 19 });
  [['Plan', 'Projects, tasks, milestones, risks, team and time.'], ['Control', 'Budgets, spending approvals, procurement and petty cash.'], ['Report', 'Grants, donor reports and double-entry books that post as you pay.']]
    .forEach(([h, b], i) => card(s, C, { x: 0.6 + i * 4.1, y: 2.6, w: 3.85, h: 2.0, head: h, body: b, headColor: C.accent1, name: h }));
  s.addNotes('When one organization funds another, a grant is what connects their two workspaces.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S1 });
  s.addText('Built for both sides of a grant', { placeholder: 'title' });
  [['Delivering organizations', 'Plan projects, approve spending with duties kept apart, buy through quotes and orders, keep the books.'],
   ['Funders and donors', 'One dashboard across every organization funded: grants, payments and reports to accept.'],
   ['Finance teams', 'Budgets someone else approves, spending checked before it is committed, books that post on payment.'],
   ['Project teams', 'Board, list and timeline, My work, a weekly timesheet, and purchases raised from the project.']]
    .forEach(([h, b], i) => card(s, C, { x: 0.6 + (i % 2) * 6.1, y: 1.65 + Math.floor(i / 2) * 2.55, w: 5.95, h: 2.3, head: h, body: b, headColor: i < 2 ? C.accent1 : C.accent4, name: h }));
  s.addNotes('Four audiences, one product. Donors and delivering organizations each keep their own workspace, members and records.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S1 });
  s.addText('Everything in one menu', { placeholder: 'title' });
  table(s, C, [['Area', 'What is in it'],
    ['Overview', 'Portfolio, My work, search and notifications'],
    ['Projects', 'Projects, Programmes, Spaces and lists, People, Procurement'],
    ['Funding', 'Grants, Funders, partnerships with beneficiaries'],
    ['Finance', 'Approvals, Commitments, Staff advances, Petty cash, Accounting'],
    ['Records', 'Documents with every version kept'],
    ['Administration', 'Members, Departments and teams, Roles, Settings, Audit log']], { x: 0.6, y: 1.65, w: 12.1, colW: [2.8, 9.3], fontSize: 19, rowH: 0.52 });
  para(s, C, 'Documents, Grants, Procurement and Accounting can each be turned off in Settings. Nothing is deleted.', { x: 0.6, y: 5.9, w: 12, h: 0.5, size: 16 });
  s.addNotes('Organizations switch off the parts they do not use, and switching back brings everything back.');

  pres.addSection({ title: S2 });
  screenSlide(pres, C, S2, { title: 'Every project, worst health first', kicker: 'Portfolio', file: '01-portfolio.png', alt: 'Portfolio page with open projects, health, overdue tasks and budget used',
    paras: ['Open projects, those at risk, overdue work and spend against open budgets on one page.', 'Every chart has a Table button for the figures behind it.'],
    notes: 'Home page for the demo organization: three open projects, two at risk, four overdue tasks, and USD 62,725 spent of USD 421,800 in open budgets.' });
  screenSlide(pres, C, S2, { title: 'Health is calculated, not guessed', kicker: 'A project', file: '04-project-overview.png', alt: 'Project overview with progress, milestones, coming up, top risks and activity',
    paras: [['Off track:', 'past its due date, or over budget.'], ['At risk:', 'progress 20+ points behind time elapsed, overdue tasks, or spend 20+ points ahead of progress.'], 'Progress is weighted by each task’s estimate.'],
    notes: 'Each project has a code that numbers its tasks, a manager, dates, a budget and a status. The 20-point slack can be changed by the organization.' });

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S2 });
  s.addText('Tasks on a board or a timeline', { placeholder: 'title' });
  para(s, C, 'Tasks are numbered to their project (WASH-12). A task can’t be marked done while one it waits on is open, and circular dependencies are refused.', { x: 0.6, y: 1.45, w: 12, h: 0.8, size: 17 });
  picture(s, '05-board.png', { x: 0.6, y: 2.45, w: 5.95, h: 3.72, alt: 'Kanban board with To do, In progress, In review and Done columns' });
  picture(s, '07-timeline.png', { x: 6.75, y: 2.45, w: 5.95, h: 3.72, alt: 'Timeline with task bars, milestones and a today line' });
  s.addNotes('Left: the board, where cards are dragged between columns. Right: the timeline, with milestones as diamonds and a line for today. There is also a list view.');

  screenSlide(pres, C, S2, { title: 'Committed is not spent', kicker: 'Budget', file: '08-budget.png', alt: 'Budget tab with budget, committed, spent and available by category, and funding by grant',
    paras: ['Budget, committed, spent and available for each category, and the funding behind it grant by grant.', 'An approved budget never changes. A revision is a new version that can’t cut below what is committed or spent.'],
    notes: 'Categories are labour, materials, equipment, travel, subcontractors and other. A draft is approved by someone else or returned with a reason.' });

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S2 });
  s.addText('Three hands on every payment', { placeholder: 'title' });
  [['1 · Request', 'Asked against a budget category and grant, with quotes.'],
   ['2 · Approve', 'Someone else commits it if budget and grant allow. 1–3 approvers.'],
   ['3 · Pay', 'Finance records the payment; the journal posts with it.']]
    .forEach(([h, b], i) => card(s, C, { x: 0.6, y: 1.65 + i * 1.6, w: 4.3, h: 1.45, head: h, body: b, headColor: C.accent1, name: h, hs: 20, bs: 16, pad: 0.15 }));
  picture(s, '15-approvals.png', { x: 5.25, y: 1.65, w: 7.45, h: 4.656, alt: 'Approvals page with budgets, spending requests and payments waiting' });
  s.addNotes('The requester never approves or pays their own request; the system enforces it. Rejected, cancelled and voided spending stays on record.');

  screenSlide(pres, C, S2, { title: 'Grants from opportunity to closeout', kicker: 'Grants', file: '13-grants.png', alt: 'Grants list with status, amount, allocated, received and reports owed',
    paras: ['After the award, changes come only by numbered amendment.', 'One grant funds several projects, across currencies, with the rate recorded.', 'A grant closes only when no report is owed.'],
    notes: 'Grant stages: opportunity, applied, active, closed, or declined. Payment schedules and reports owed are tracked on each grant.' });

  pres.addSection({ title: S3 });
  s = pres.addSlide({ masterName: 'CONTENT_DARK', sectionTitle: S3 });
  s.addText('The organization decides what each donor sees', { placeholder: 'title' });
  para(s, C, 'One grant and one switch at a time. Every switch is off until someone turns it on.', { x: 0.6, y: 1.45, w: 12, h: 0.6, size: 18, color: C.accent6 });
  [['Can be shared, per grant', 'Approved budget by category\nPaid spending and the committed total\nProgress, percent done, milestones\nTask number, title, status, dates', C.accent3],
   ['Always stays inside', 'Suppliers and receipts\nWho requested or approved\nStaff and the cost of people\nRisks, internal notes, task assignees', 'F2A66B']]
    .forEach(([h, b, hc], i) => {
      s.addShape('roundRect', { x: 0.6 + i * 6.1, y: 2.35, w: 5.95, h: 3.6, rectRadius: 0.12, fill: { color: C.accent5 }, line: { color: C.accent5 } });
      s.addText([{ text: h, options: { bold: true, fontSize: 22, color: hc, breakLine: true } },
        ...b.split('\n').map((t, k, a) => ({ text: t, options: { bullet: true, fontSize: 18, color: 'DCE4F3', breakLine: k < a.length - 1 } }))],
      { x: 0.95 + i * 6.1, y: 2.6, w: 5.3, h: 3.1, valign: 'top', paraSpaceAfter: 10, isTextBox: true, objectName: h });
    });
  s.addNotes('Donors see only their own grants, even when an organization has several donors.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S3 });
  s.addText('The life of a grant', { placeholder: 'title' });
  [['Invite', 'The donor invites the organization. Nothing connects until it accepts.'],
   ['Record', 'Award, amendments, allocations and payment schedule.'],
   ['Choose', 'Turn on what to share: budget, spending, progress, tasks.'],
   ['Deliver', 'Tasks move, purchases are approved, payments post to the books.'],
   ['Report', 'Reports with due dates and documents, visible once submitted.'],
   ['Accept', 'The donor accepts or asks for a revision. No report owed: it closes.']]
    .forEach(([h, b], i) => {
      const x = 0.6 + (i % 3) * 4.1, y = 1.65 + Math.floor(i / 3) * 2.55;
      s.addShape('roundRect', { x, y, w: 3.85, h: 2.3, rectRadius: 0.12, fill: { color: C.background1 }, line: { color: 'D9E1EE', width: 0.75 }, shadow: shadow() });
      s.addShape('ellipse', { x: x + 0.3, y: y + 0.3, w: 0.6, h: 0.6, fill: { color: i < 3 ? C.accent1 : C.accent2 }, line: { color: i < 3 ? C.accent1 : C.accent2 } });
      s.addText(String(i + 1), { x: x + 0.3, y: y + 0.3, w: 0.6, h: 0.6, align: 'center', valign: 'middle', bold: true, fontSize: 20, color: i < 3 ? 'FFFFFF' : '0E1B3D', margin: 0, isTextBox: true });
      s.addText(h, { x: x + 1.05, y: y + 0.3, w: 2.5, h: 0.6, valign: 'middle', bold: true, fontSize: 20, color: C.text1, margin: 0, isTextBox: true });
      para(s, C, b, { x: x + 0.3, y: y + 1.05, w: 3.25, h: 1.1, size: 17 });
    });
  s.addNotes('Steps one to three set the grant up; four to six are the delivery loop. A revision request sends the report back as owed.');

  pres.addSection({ title: S4 });
  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S4 });
  s.addText('Roles that keep duties apart', { placeholder: 'title' });
  table(s, C, [['Role', 'Can do'],
    ['Organization Administrator', 'Everything, plus approvals, grants, settings and the audit log'],
    ['Project Manager', 'Everything on projects; drafts budgets, requests spending; sees grants'],
    ['Team Member', 'Works tasks, logs own time, requests spending'],
    ['Viewer', 'View only'],
    ['Finance Controller', 'Approves budgets and spending, records payments, manages grants'],
    ['Finance Officer', 'Records payments of approved spending'],
    ['Funder contact', 'Their funder’s donor portal only']], { x: 0.6, y: 1.65, w: 12.1, colW: [3.6, 8.5], fontSize: 18, rowH: 0.52 });
  s.addNotes('A member can hold more than one role, and the server checks every action again.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S4 });
  s.addText('What guides the product', { placeholder: 'title' });
  [['Different hands', 'Whoever asks for money never approves or pays it. The product enforces that.'],
   ['History stays', 'Numbered amendments, budgets that never change once approved, spending voided not deleted.'],
   ['Figures that mean something', 'Committed kept apart from spent; currencies never added together.'],
   ['Said plainly', 'What is not built is marked Planned and listed in one place.']]
    .forEach(([h, b], i) => card(s, C, { x: 0.6 + (i % 2) * 6.1, y: 1.65 + Math.floor(i / 2) * 2.55, w: 5.95, h: 2.3, head: h, body: b, name: h }));
  s.addNotes('Each organization’s records are kept to itself. Every security-relevant change is in the audit log, which the app never edits.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S4 });
  s.addText('Available today, and what comes next', { placeholder: 'title' });
  [['Available today', ['Portfolio, projects, programmes', 'Board, list, timeline, time', 'Budgets, spending, approvals', 'Procurement, petty cash, advances', 'Grants, funders, donor workspace', 'Double-entry accounting', 'Documents, roles, audit log'], C.accent1],
   ['Planned', ['Monitoring and evaluation', 'Records of people served', 'KoboToolbox and ODK field data', 'Templates and calendar view', 'Tenders and contracts', 'An assistant that never approves', 'Microsoft 365, Google, Slack, Power BI'], C.accent4]]
    .forEach(([h, items, hc], i) => {
      s.addShape('roundRect', { x: 0.6 + i * 6.1, y: 1.65, w: 5.95, h: 4.9, rectRadius: 0.12, fill: { color: C.background1 }, line: { color: 'D9E1EE', width: 0.75 }, shadow: shadow() });
      s.addText([{ text: h, options: { bold: true, fontSize: 22, color: hc, breakLine: true } },
        ...items.map((t, k) => ({ text: t, options: { bullet: true, fontSize: 19, color: C.text1, breakLine: k < items.length - 1 } }))],
      { x: 0.95 + i * 6.1, y: 1.9, w: 5.3, h: 4.4, valign: 'top', paraSpaceAfter: 8, isTextBox: true, objectName: h });
    });
  s.addNotes('Everything on the right is not in the product today. Naming Microsoft, Google, Slack or Power BI does not imply a partnership.');

  s = pres.addSlide({ masterName: 'DARK', sectionTitle: S4 });
  s.addText('GET STARTED', { placeholder: 'eyebrow' });
  s.addText('Set up your organization in an afternoon', { placeholder: 'title' });
  s.addText('Sign up, create the workspace, invite the team, and record your first project. A donor’s invitation works too, and you can connect later.', { placeholder: 'body' });
  s.addText('yourorganization.milesoftwares.com/projects · [contact email]', { x: 0.8, y: 6.5, w: 11.7, h: 0.45, fontSize: 14, color: '7FB0FF', margin: 0, isTextBox: true });
  s.addNotes('Close with a demo request. Replace the contact placeholder before sharing.');

  const file = path.join(OUT, 'Projects-Product-Presentation.pptx');
  await pres.writeFile({ fileName: file });
  await applyTheme(file, THEME);
  console.log('wrote', file);
}

// ---------------------------------------------------------------- Pitch deck
async function pitch() {
  const { pres, C } = newDeck('Projects — Pitch Deck');
  const S1 = 'Problem and answer', S2 = 'Why Projects wins', S3 = 'The business', S4 = 'Next and the ask';

  pres.addSection({ title: S1 });
  let s = pres.addSlide({ masterName: 'DARK', sectionTitle: S1 });
  s.addText('PROJECTS BY TATOOK', { placeholder: 'eyebrow' });
  s.addText('The operating system for funded work', { placeholder: 'title' });
  s.addText('Projects, money and books for NGOs, with a shared record of every grant for the donors who fund them.', { placeholder: 'body' });
  s.addText('Miles Global Softwares · Investor and partner briefing · [Date]', { x: 0.8, y: 6.5, w: 11.7, h: 0.45, fontSize: 14, color: '7FB0FF', margin: 0, isTextBox: true });
  s.addNotes('Open with the one line: run your organization, share what you choose.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S1 });
  s.addText('Funded work runs on tools that never agree', { placeholder: 'title' });
  para(s, C, 'Spreadsheets, email approvals and a separate accounting system each hold part of the truth.', { x: 0.6, y: 1.5, w: 12, h: 0.6, size: 19 });
  [['Spending drifts', 'Money is committed before anyone checks the budget or the grant it is charged to.'],
   ['Duties blur', 'The same person asks for, approves and pays — exactly what auditors flag.'],
   ['Donors chase reports', 'Funders wait for spreadsheets; organizations rebuild the same figures for each one.']]
    .forEach(([h, b], i) => card(s, C, { x: 0.6 + i * 4.1, y: 2.4, w: 3.85, h: 2.2, head: h, body: b, headColor: C.accent4, name: h }));
  s.addNotes('Back these with interview quotes or survey numbers from your own customer conversations. No statistics are claimed here.');

  s = pres.addSlide({ masterName: 'DARK', sectionTitle: S1 });
  s.addText('THE SOLUTION', { placeholder: 'eyebrow' });
  s.addText('One workspace from the task to the journal entry', { placeholder: 'title' });
  s.addText('Plan the work, approve spending with duties kept apart, buy through quotes and orders, and post to double-entry books in the same step as the payment. Share with each donor only what you switch on.', { placeholder: 'body' });
  s.addNotes('Projects replaces the spreadsheet, the approval email chain and the separate ledger with one record.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S1 });
  s.addText('Built and running', { placeholder: 'title' });
  para(s, C, 'Portfolio health, budgets by category, approvals, grants and reports owed — real screens from a demo organization.', { x: 0.6, y: 1.45, w: 12, h: 0.7, size: 17 });
  picture(s, '01-portfolio.png', { x: 0.6, y: 2.45, w: 5.95, h: 3.72, alt: 'Portfolio page with project health, overdue tasks and budget used' });
  picture(s, '04-project-overview.png', { x: 6.75, y: 2.45, w: 5.95, h: 3.72, alt: 'Project overview with milestones, risks and activity' });
  s.addNotes('Both screens are from the working product: the portfolio, and one project with milestones, top risks and activity.');

  pres.addSection({ title: S2 });
  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S2 });
  s.addText('Controls auditors look for, built in', { placeholder: 'title' });
  [['Different hands', 'Requester never approves or pays.'], ['Checked before committed', 'Against budget category and grant.'], ['Books can’t disagree', 'Payment and journal post together.']]
    .forEach(([h, b], i) => card(s, C, { x: 0.6, y: 1.65 + i * 1.6, w: 4.3, h: 1.45, head: h, body: b, headColor: C.accent1, name: h, hs: 20, bs: 16, pad: 0.15 }));
  picture(s, '15-approvals.png', { x: 5.25, y: 1.65, w: 7.45, h: 4.656, alt: 'Approvals page with budgets, spending requests and payments waiting' });
  s.addNotes('If the books refuse a journal, the payment is refused too. Approved budgets never change, amendments are numbered, and nothing is deleted.');

  screenSlide(pres, C, S2, { title: 'Every grant can bring the other side in', kicker: 'Two-sided by design', file: '13-grants.png', alt: 'Grants list with amounts, allocations, payments received and reports owed',
    paras: ['Donors invite the organizations they fund; organizations invite their funders’ contacts.', 'Each keeps its own workspace, and the grant connects them.', 'The organization chooses what each donor sees, switch by switch.'],
    notes: 'This is the growth loop: one donor brings several funded organizations onto the platform, and one organization brings several donors.' });

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S2 });
  s.addText('What organizations use today', { placeholder: 'title' });
  table(s, C, [['Today’s option', 'What it does well', 'What it misses'],
    ['Spreadsheets and email', 'Cheap, familiar', 'No controls, no history, no donor view'],
    ['Generic task tools', 'Boards and timelines', 'No budgets, grants or approvals'],
    ['Standalone accounting', 'The ledger', 'Disconnected from projects and grants'],
    ['Donor-specific portals', 'One funder’s reporting', 'Re-typing for every donor'],
    ['Projects', 'Work, money, books, donors', 'Field data and M&E: on the roadmap']], { x: 0.6, y: 1.65, w: 12.1, colW: [3.6, 4.0, 4.5], fontSize: 19, rowH: 0.6 });
  s.addNotes('Categories rather than named competitors. Add named competitors and pricing from your own research before sharing externally.');

  pres.addSection({ title: S3 });
  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S3 });
  s.addText('Business model: the NGO ERP on Tatook', { placeholder: 'title' });
  [['Projects', 'Core. Subscription per organization workspace: [$__ / month].'],
   ['Finance, HR, Payroll', 'Add-ons. Optional Tatook applications on the same sign-in: [$__ each].'],
   ['Donor workspace', 'Funders follow grantees from one dashboard: [pricing to decide].']]
    .forEach(([h, b], i) => card(s, C, { x: 0.6 + i * 4.1, y: 1.65, w: 3.85, h: 2.2, head: h, body: b, headColor: i < 2 ? C.accent1 : C.accent4, name: h }));
  para(s, C, 'One account, one sign-in, many organizations; billing runs on the Tatook platform.', { x: 0.6, y: 4.2, w: 12, h: 0.6, size: 18 });
  s.addNotes('Projects is listed on the platform as the required application of the NGO ERP, with Finance and Accounting, HR and Payroll as optional add-ons. Fill in the bracketed prices.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S3 });
  s.addText('Market: start where we work, then grow', { placeholder: 'title' });
  [['Beachhead', 'NGOs and implementing partners in South Sudan and the region.'],
   ['East Africa', 'Donor-funded organizations reporting in several currencies.'],
   ['Donors', 'Foundations, agencies and funds that each bring their grantees.']]
    .forEach(([h, b], i) => {
      const x = 0.6 + i * 4.1;
      s.addShape('roundRect', { x, y: 1.65, w: 3.85, h: 2.9, rectRadius: 0.12, fill: { color: C.background1 }, line: { color: 'D9E1EE', width: 0.75 }, shadow: shadow() });
      s.addText('[__]', { x: x + 0.3, y: 1.9, w: 3.25, h: 1.0, fontSize: 48, bold: true, color: C.accent1, margin: 0, isTextBox: true });
      s.addText([{ text: h, options: { bold: true, fontSize: 22, color: C.text1, breakLine: true } }, { text: b, options: { fontSize: 17, color: C.text2 } }],
        { x: x + 0.3, y: 3.0, w: 3.25, h: 1.8, valign: 'top', paraSpaceAfter: 6, margin: 0, isTextBox: true, objectName: h });
    });
  s.addNotes('Fill each bracket with a sourced count of organizations or annual spend. Do not present invented market sizes.');

  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S3 });
  s.addText('Where we are', { placeholder: 'title' });
  s.addText(['Product built: projects, budgets, spending, procurement, grants, donor workspace', 'Double-entry books posting from payments', 'Single sign-on and roles on the Tatook platform', 'Pharmacy, restaurant and finance products already on the same platform']
    .map((t, k, a) => ({ text: t, options: { bullet: true, breakLine: k < a.length - 1 } })), { x: 0.6, y: 1.65, w: 6.0, h: 4.4, fontSize: 18, color: C.text1, valign: 'top', paraSpaceAfter: 12, isTextBox: true });
  s.addShape('roundRect', { x: 7.0, y: 1.65, w: 5.7, h: 3.3, rectRadius: 0.12, fill: { color: C.background1 }, line: { color: 'D9E1EE', width: 0.75 }, shadow: shadow() });
  s.addText([{ text: 'Pilot numbers', options: { bold: true, fontSize: 22, color: C.text1, breakLine: true } },
    ...['Organizations onboarded: [__]', 'Grants managed: [__]', 'Funds tracked: [$__]', 'Letters of intent: [__]'].map((t, k, a) => ({ text: t, options: { fontSize: 18, color: C.text2, breakLine: k < a.length - 1 } }))],
  { x: 7.35, y: 1.95, w: 5.0, h: 2.8, valign: 'top', paraSpaceAfter: 12, isTextBox: true, objectName: 'Pilot numbers' });
  s.addNotes('Projects is in beta. Replace the brackets with real pilot figures, or drop the card until there are some.');

  pres.addSection({ title: S4 });
  s = pres.addSlide({ masterName: 'CONTENT', sectionTitle: S4 });
  s.addText('Roadmap: from finance to impact', { placeholder: 'title' });
  [['Now · built', ['Projects and tasks', 'Budgets and approvals', 'Grants and donors', 'Accounting'], C.text1, 'FFFFFF', C.accent3],
   ['Next · [quarter]', ['Monitoring and evaluation', 'KoboToolbox, ODK data', 'Templates, calendar'], C.background1, '0E1B3D', C.accent1],
   ['Later · [quarter]', ['People served records', 'Tenders and contracts', 'Assistant, integrations'], C.background1, '0E1B3D', C.accent4]]
    .forEach(([h, items, fill, tc, hc], i) => {
      const x = 0.6 + i * 4.1;
      s.addShape('roundRect', { x, y: 1.65, w: 3.85, h: 3.2, rectRadius: 0.12, fill: { color: fill }, line: { color: i === 0 ? '0E1B3D' : 'D9E1EE', width: 0.75 }, shadow: shadow() });
      s.addText([{ text: h, options: { bold: true, fontSize: 20, color: hc, breakLine: true } },
        ...items.map((t, k) => ({ text: t, options: { bullet: true, fontSize: 19, color: tc, breakLine: k < items.length - 1 } }))],
      { x: x + 0.3, y: 1.9, w: 3.25, h: 3.5, valign: 'top', paraSpaceAfter: 10, isTextBox: true, objectName: h });
    });
  s.addNotes('Items under Next and Later are planned, not built. Set the quarters to your real plan.');

  s = pres.addSlide({ masterName: 'DARK', sectionTitle: S4 });
  s.addText('THE ASK', { placeholder: 'eyebrow' });
  s.addText('[Raising $__] to bring Projects to [__] organizations', { placeholder: 'title' });
  s.addText('[__%] sales and partner onboarding · [__%] monitoring and evaluation build · [__%] support and implementation', { placeholder: 'body' });
  s.addText('[Founder name] · [contact email] · Miles Global Softwares', { x: 0.8, y: 6.5, w: 11.7, h: 0.45, fontSize: 14, color: '7FB0FF', margin: 0, isTextBox: true });
  s.addNotes('Pilot partners can be asked for something other than money: a donor willing to invite three grantees is worth naming here.');

  const file = path.join(OUT, 'Projects-Pitch-Deck.pptx');
  await pres.writeFile({ fileName: file });
  await applyTheme(file, THEME);
  console.log('wrote', file);
}

(async () => { await presentation(); await pitch(); })().catch((e) => { console.error(e); process.exit(1); });
